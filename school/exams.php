<?php
declare(strict_types=1);

ob_start();
session_start();
require_once __DIR__ . "/conncation.php";

if (!isset($_SESSION["user_id"])) { header("Location: login.php"); exit; }

function flash(string $type, string $title, string $text): void {
  $_SESSION["flash"] = ["type"=>$type, "title"=>$title, "text"=>$text];
}

/* ============================================================
   AUTO-CREATE academic_year if EMPTY
   ============================================================ */
try {
  $row = $conn->query("SELECT COUNT(*) AS c FROM academic_years")->fetch_assoc();
  $countYears = (int)($row["c"] ?? 0);

  if ($countYears === 0) {
    $y = (int)date("Y");
    $name  = $y . "-" . ($y + 1);
    $start = $y . "-09-01";
    $end   = ($y + 1) . "-06-30";

    $conn->query("UPDATE academic_years SET is_current=0");

    $stmt = $conn->prepare("INSERT INTO academic_years (year_name, start_date, end_date, is_current) VALUES (?,?,?,1)");
    $stmt->bind_param("sss", $name, $start, $end);
    $stmt->execute();
    $stmt->close();
  }
} catch (Throwable $e) {
  // ignore
}

$examId = (int)($_GET["exam_id"] ?? 0);

/* =========================
   LOAD DROPDOWN DATA
   ========================= */
try {
  $years = $conn->query("SELECT year_id, year_name, is_current FROM academic_years ORDER BY is_current DESC, start_date DESC")
                ->fetch_all(MYSQLI_ASSOC);

  $terms = $conn->query("SELECT term_id, term_name FROM exam_terms ORDER BY term_id ASC")
                ->fetch_all(MYSQLI_ASSOC);

  $sections = $conn->query("
    SELECT s.section_id, CONCAT(g.grade_name,'-',s.section_name) AS section_label
    FROM sections s
    JOIN grades g ON g.grade_id=s.grade_id
    ORDER BY g.sort_order ASC, s.section_name ASC
  ")->fetch_all(MYSQLI_ASSOC);

  $subjects = $conn->query("SELECT subject_id, subject_name FROM subjects WHERE is_active=1 ORDER BY subject_name ASC")
                   ->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $e) {
  flash("error","DB Error","Dropdown load failed: ".$e->getMessage());
  header("Location: dashboardadmin.php"); exit;
}

/* =========================
   ACTIONS
   ========================= */
try {

  // SAVE EXAM
  if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "save_exam") {

    $id        = (int)($_POST["exam_id"] ?? 0);
    $year_id   = (int)($_POST["year_id"] ?? 0);
    $term_id   = (int)($_POST["term_id"] ?? 0);
    $exam_name = trim((string)($_POST["exam_name"] ?? ""));
    $start     = (string)($_POST["start_date"] ?? "");
    $end       = (string)($_POST["end_date"] ?? "");

    if ($year_id<=0 || $term_id<=0 || $exam_name==="" || $start==="" || $end==="") {
      flash("error","Error","Fill all fields (Year, Term, Name, Start, End).");
      header("Location: exams.php".($id>0 ? "?exam_id=".$id : "")); exit;
    }

    if (strtotime($start) === false || strtotime($end) === false) {
      flash("error","Error","Invalid dates.");
      header("Location: exams.php".($id>0 ? "?exam_id=".$id : "")); exit;
    }
    if (strtotime($start) > strtotime($end)) {
      flash("error","Error","Start date must be before (or equal) End date.");
      header("Location: exams.php".($id>0 ? "?exam_id=".$id : "")); exit;
    }

    if ($id > 0) {
      $stmt = $conn->prepare("UPDATE exams SET year_id=?, term_id=?, exam_name=?, start_date=?, end_date=? WHERE exam_id=?");
      $stmt->bind_param("iisssi", $year_id, $term_id, $exam_name, $start, $end, $id);
      $stmt->execute();
      $stmt->close();

      flash("success","Updated","Exam updated successfully.");
      header("Location: exams.php?exam_id=".$id); exit;

    } else {
      $stmt = $conn->prepare("INSERT INTO exams (year_id, term_id, exam_name, start_date, end_date) VALUES (?,?,?,?,?)");
      $stmt->bind_param("iisss", $year_id, $term_id, $exam_name, $start, $end);
      $stmt->execute();
      $newId = (int)$stmt->insert_id;
      $stmt->close();

      flash("success","Saved","Exam created successfully.");
      header("Location: exams.php?exam_id=".$newId); exit;
    }
  }

  // DELETE EXAM
  if (isset($_GET["delete_exam"])) {
    $del = (int)$_GET["delete_exam"];
    if ($del > 0) {
      $stmt = $conn->prepare("DELETE FROM exams WHERE exam_id=?");
      $stmt->bind_param("i", $del);
      $stmt->execute();
      $stmt->close();
      flash("success","Deleted","Exam deleted.");
    }
    header("Location: exams.php"); exit;
  }

  // ADD PAPER
  if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "add_paper") {
    $exam_id    = (int)($_POST["exam_id"] ?? 0);
    $section_id = (int)($_POST["section_id"] ?? 0);
    $subject_id = (int)($_POST["subject_id"] ?? 0);

    if ($exam_id<=0 || $section_id<=0 || $subject_id<=0) {
      flash("error","Error","Select Exam + Section + Subject.");
      header("Location: exams.php?exam_id=".$exam_id); exit;
    }

    $max = 100; $min = 50;

    try {
      $stmt = $conn->prepare("INSERT INTO exam_papers (exam_id, section_id, subject_id, max_mark, min_pass) VALUES (?,?,?,?,?)");
      $stmt->bind_param("iiiii", $exam_id, $section_id, $subject_id, $max, $min);
      $stmt->execute();
      $stmt->close();

      flash("success","Saved","Paper added successfully.");
      header("Location: exams.php?exam_id=".$exam_id); exit;

    } catch (mysqli_sql_exception $ex) {
      if ((int)$ex->getCode() === 1062) {
        flash("error","Duplicate","This paper already exists (Exam + Section + Subject).");
        header("Location: exams.php?exam_id=".$exam_id); exit;
      }
      throw $ex;
    }
  }

  // DELETE PAPER
  if (isset($_GET["delete_paper"])) {
    $paperId  = (int)$_GET["delete_paper"];
    $backExam = (int)($_GET["exam_id"] ?? 0);

    if ($paperId > 0) {
      $stmt = $conn->prepare("DELETE FROM exam_papers WHERE paper_id=?");
      $stmt->bind_param("i", $paperId);
      $stmt->execute();
      $stmt->close();
      flash("success","Deleted","Paper deleted.");
    }
    header("Location: exams.php?exam_id=".$backExam); exit;
  }

} catch (Throwable $ex) {
  flash("error","DB Error",$ex->getMessage());
  header("Location: exams.php".($examId>0 ? "?exam_id=".$examId : "")); exit;
}

/* =========================
   LOAD LISTS
   ========================= */
$exams = $conn->query("
  SELECT e.exam_id, e.exam_name, e.start_date, e.end_date,
         y.year_name, t.term_name, y.is_current
  FROM exams e
  JOIN academic_years y ON y.year_id=e.year_id
  JOIN exam_terms t ON t.term_id=e.term_id
  ORDER BY e.start_date DESC, e.exam_id DESC
")->fetch_all(MYSQLI_ASSOC);

$selectedExam = null;
if ($examId > 0) {
  $stmt = $conn->prepare("SELECT exam_id, year_id, term_id, exam_name, start_date, end_date FROM exams WHERE exam_id=?");
  $stmt->bind_param("i", $examId);
  $stmt->execute();
  $selectedExam = $stmt->get_result()->fetch_assoc();
  $stmt->close();
}

$papers = [];
if ($examId > 0) {
  $stmt = $conn->prepare("
    SELECT p.paper_id,
           CONCAT(g.grade_name,'-',s.section_name) AS section_label,
           sub.subject_name,
           p.max_mark, p.min_pass
    FROM exam_papers p
    JOIN sections s ON s.section_id=p.section_id
    JOIN grades g ON g.grade_id=s.grade_id
    JOIN subjects sub ON sub.subject_id=p.subject_id
    WHERE p.exam_id=?
    ORDER BY g.sort_order ASC, s.section_name ASC, sub.subject_name ASC
  ");
  $stmt->bind_param("i", $examId);
  $stmt->execute();
  $papers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}

$flash = $_SESSION["flash"] ?? null;
unset($_SESSION["flash"]);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Exams</title>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    body{font-family:system-ui,Segoe UI,Arial; background:#070f1c; color:#e7eefc; margin:0}
    .wrap{max-width:1200px; margin:0 auto; padding:24px}
    .top{display:flex; justify-content:space-between; align-items:center; gap:12px}
    h1{margin:0; font-size:22px}
    .card{background:#0f1b33; border:1px solid rgba(255,255,255,.08); border-radius:16px; padding:16px; box-shadow:0 10px 25px rgba(0,0,0,.25)}
    .grid{display:grid; grid-template-columns: 420px 1fr; gap:16px; margin-top:16px}
    label{font-size:12px; opacity:.85}
    input,select{width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(255,255,255,.12); background:#070f1c; color:#e7eefc; outline:none}
    .row{display:grid; grid-template-columns:1fr 1fr; gap:10px}
    .btn{border:0; padding:10px 14px; border-radius:12px; cursor:pointer; font-weight:650}
    .btn-primary{background:#2d6cff; color:#fff}
    .btn-danger{background:#ff3b3b; color:#fff}
    .btn-ghost{background:transparent; color:#e7eefc; border:1px solid rgba(255,255,255,.14)}
    table{width:100%; border-collapse:collapse}
    th,td{padding:10px; border-bottom:1px solid rgba(255,255,255,.08); font-size:14px}
    th{opacity:.85; text-align:left}
    .muted{opacity:.7}
    .badge{display:inline-block; padding:4px 8px; border-radius:999px; font-size:12px; border:1px solid rgba(255,255,255,.16)}
    .search{display:flex; gap:10px; align-items:center}
    .search input{max-width:360px}
    a{color:#9cc2ff; text-decoration:none}
    .hint{margin-top:10px; padding:10px; border-radius:12px; border:1px dashed rgba(255,255,255,.15); background:rgba(255,255,255,.03)}
    .warn{margin-top:10px; padding:10px; border-radius:12px; border:1px solid rgba(255,99,99,.35); background:rgba(255,99,99,.08)}
  </style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <h1>📘 Exams & Papers</h1>
    <div class="search">
      <input id="searchBox" type="text" placeholder="Live search exams..."/>
      <a class="btn btn-ghost" href="dashboardadmin.php">⬅ Back</a>
    </div>
  </div>

  <div class="hint">
    <div><b>How to use:</b></div>
    <div class="muted">1) Create Exam → 2) Click Exam name → 3) Add Papers → 4) Go to marks.php → 5) results.php summary</div>
  </div>

  <?php if (!$terms): ?>
    <div class="warn"><b>Warning:</b> exam_terms table is empty. Insert (Term1, Term2, Final).</div>
  <?php endif; ?>
  <?php if (!$sections): ?>
    <div class="warn"><b>Warning:</b> sections table is empty. Create Grades + Sections first.</div>
  <?php endif; ?>
  <?php if (!$subjects): ?>
    <div class="warn"><b>Warning:</b> subjects table is empty. Insert subjects first.</div>
  <?php endif; ?>

  <div class="grid">
    <!-- Exam form -->
    <div class="card">
      <h3 style="margin:0 0 10px 0;"><?= $selectedExam ? "Edit Exam" : "Add New Exam" ?></h3>

      <form method="post" action="exams.php<?= $selectedExam ? '?exam_id='.(int)$selectedExam['exam_id'] : '' ?>">
        <input type="hidden" name="action" value="save_exam"/>
        <input type="hidden" name="exam_id" value="<?= e((string)($selectedExam["exam_id"] ?? "0")) ?>"/>

        <label>Academic Year</label>
        <select name="year_id" required>
          <option value="">-- Select year --</option>
          <?php foreach ($years as $y): ?>
            <option value="<?= (int)$y["year_id"] ?>" <?= (($selectedExam["year_id"] ?? "") == $y["year_id"]) ? "selected" : "" ?>>
              <?= e((string)$y["year_name"]) ?><?= ((int)$y["is_current"]===1) ? " (Current)" : "" ?>
            </option>
          <?php endforeach; ?>
        </select>

        <div style="height:10px"></div>

        <label>Term</label>
        <select name="term_id" required>
          <option value="">-- Select term --</option>
          <?php foreach ($terms as $t): ?>
            <option value="<?= (int)$t["term_id"] ?>" <?= (($selectedExam["term_id"] ?? "") == $t["term_id"]) ? "selected" : "" ?>>
              <?= e((string)$t["term_name"]) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <div style="height:10px"></div>

        <label>Exam Name</label>
        <input name="exam_name" value="<?= e((string)($selectedExam["exam_name"] ?? "")) ?>" placeholder="e.g. Midterm - Term1" required/>

        <div style="height:10px"></div>

        <div class="row">
          <div>
            <label>Start Date</label>
            <input type="date" name="start_date" value="<?= e((string)($selectedExam["start_date"] ?? "")) ?>" required/>
          </div>
          <div>
            <label>End Date</label>
            <input type="date" name="end_date" value="<?= e((string)($selectedExam["end_date"] ?? "")) ?>" required/>
          </div>
        </div>

        <div style="display:flex; gap:10px; margin-top:12px">
          <button class="btn btn-primary" type="submit">💾 Save</button>
          <a class="btn btn-ghost" href="exams.php">➕ New</a>
        </div>
      </form>

      <div class="muted" style="margin-top:12px;">
        Tip: Click exam from the list to manage papers.
      </div>
    </div>

    <!-- Exams list + papers -->
    <div class="card">
      <div style="display:flex; justify-content:space-between; align-items:center; gap:10px;">
        <h3 style="margin:0">All Exams</h3>
        <div class="muted">Click exam name to open papers</div>
      </div>

      <div style="overflow:auto; margin-top:10px;">
        <table id="examsTable">
          <thead>
            <tr>
              <th>Exam</th>
              <th>Year</th>
              <th>Term</th>
              <th>Dates</th>
              <th style="width:180px;">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($exams as $ex): ?>
            <tr>
              <td>
                <a href="exams.php?exam_id=<?= (int)$ex["exam_id"] ?>"><b><?= e((string)$ex["exam_name"]) ?></b></a>
                <?php if ((int)$ex["is_current"]===1): ?> <span class="badge">Current</span><?php endif; ?>
              </td>
              <td><?= e((string)$ex["year_name"]) ?></td>
              <td><?= e((string)$ex["term_name"]) ?></td>
              <td class="muted"><?= e((string)$ex["start_date"]) ?> → <?= e((string)$ex["end_date"]) ?></td>
              <td>
                <a class="btn btn-ghost" style="padding:7px 10px" href="exams.php?exam_id=<?= (int)$ex["exam_id"] ?>">Edit</a>
                <button class="btn btn-danger" style="padding:7px 10px" type="button"
                  onclick="confirmDeleteExam(<?= (int)$ex['exam_id'] ?>)">Delete</button>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$exams): ?>
            <tr><td colspan="5" class="muted">No exams yet.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($examId > 0): ?>
        <hr style="border:0; border-top:1px solid rgba(255,255,255,.1); margin:16px 0;">
        <h3 style="margin:0 0 8px 0;">🧾 Exam Papers (Exam ID: <?= (int)$examId ?>)</h3>

        <form method="post" action="exams.php?exam_id=<?= (int)$examId ?>"
              style="display:grid; grid-template-columns:1fr 1fr 1fr auto; gap:10px; align-items:end;">
          <input type="hidden" name="action" value="add_paper"/>
          <input type="hidden" name="exam_id" value="<?= (int)$examId ?>"/>

          <div>
            <label>Section</label>
            <select name="section_id" required>
              <option value="">-- Section --</option>
              <?php foreach ($sections as $s): ?>
                <option value="<?= (int)$s["section_id"] ?>"><?= e((string)$s["section_label"]) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label>Subject</label>
            <select name="subject_id" required>
              <option value="">-- Subject --</option>
              <?php foreach ($subjects as $sb): ?>
                <option value="<?= (int)$sb["subject_id"] ?>"><?= e((string)$sb["subject_name"]) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label>Rule</label>
            <input value="Max=100, Pass>=50 (Auto)" readonly/>
          </div>

          <button class="btn btn-primary" type="submit">➕ Add Paper</button>
        </form>

        <div style="margin-top:10px; overflow:auto;">
          <table>
            <thead>
              <tr>
                <th>Section</th>
                <th>Subject</th>
                <th>Max</th>
                <th>Pass</th>
                <th style="width:120px;">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($papers as $p): ?>
                <tr>
                  <td><?= e((string)$p["section_label"]) ?></td>
                  <td><?= e((string)$p["subject_name"]) ?></td>
                  <td><?= (int)$p["max_mark"] ?></td>
                  <td><?= (int)$p["min_pass"] ?></td>
                  <td>
                    <button class="btn btn-danger" style="padding:7px 10px" type="button"
                      onclick="confirmDeletePaper(<?= (int)$p['paper_id'] ?>, <?= (int)$examId ?>)">Delete</button>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$papers): ?>
                <tr><td colspan="5" class="muted">No papers yet for this exam.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div style="margin-top:10px" class="muted">
          Next: <a href="marks.php?exam_id=<?= (int)$examId ?>">Go to marks</a> •
          <a href="results.php?exam_id=<?= (int)$examId ?>">Go to results</a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
  const searchBox = document.getElementById('searchBox');
  const table = document.getElementById('examsTable');
  if (searchBox && table) {
    searchBox.addEventListener('input', () => {
      const q = searchBox.value.toLowerCase();
      [...table.querySelectorAll('tbody tr')].forEach(tr => {
        tr.style.display = tr.innerText.toLowerCase().includes(q) ? '' : 'none';
      });
    });
  }

  function confirmDeleteExam(id){
    Swal.fire({
      icon: 'warning',
      title: 'Delete this exam?',
      text: 'This will also delete its papers and marks (cascade).',
      showCancelButton: true,
      confirmButtonText: 'Yes, delete',
      cancelButtonText: 'Cancel'
    }).then(r => { if (r.isConfirmed) window.location = 'exams.php?delete_exam=' + id; });
  }

  function confirmDeletePaper(paperId, examId){
    Swal.fire({
      icon: 'warning',
      title: 'Delete this paper?',
      text: 'Marks for this paper will also be removed (cascade).',
      showCancelButton: true,
      confirmButtonText: 'Yes, delete',
      cancelButtonText: 'Cancel'
    }).then(r => { if (r.isConfirmed) window.location = 'exams.php?exam_id=' + examId + '&delete_paper=' + paperId; });
  }

  <?php if ($flash): ?>
  Swal.fire({
    icon: <?= json_encode($flash["type"]) ?>,
    title: <?= json_encode($flash["title"]) ?>,
    text: <?= json_encode($flash["text"]) ?>,
    timer: 2400,
    showConfirmButton: false
  });
  <?php endif; ?>
</script>
</body>
</html>
<?php ob_end_flush(); ?>
