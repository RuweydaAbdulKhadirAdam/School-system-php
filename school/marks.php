<?php
declare(strict_types=1);

ob_start();
session_start();
require_once __DIR__ . "/conncation.php";

if (!isset($_SESSION["user_id"])) { header("Location: login.php"); exit; }

function flash(string $type, string $title, string $text): void {
  $_SESSION["flash"] = ["type"=>$type, "title"=>$title, "text"=>$text];
}

$examId   = (int)($_GET["exam_id"] ?? 0);
$paperId  = (int)($_GET["paper_id"] ?? 0);
$sectionId= (int)($_GET["section_id"] ?? 0);

/* =========================
   Load exams
   ========================= */
$exams = $conn->query("
  SELECT e.exam_id, e.exam_name, y.year_name, t.term_name, e.start_date
  FROM exams e
  JOIN academic_years y ON y.year_id=e.year_id
  JOIN exam_terms t ON t.term_id=e.term_id
  ORDER BY e.start_date DESC, e.exam_id DESC
")->fetch_all(MYSQLI_ASSOC);

/* =========================
   Load papers for selected exam
   ========================= */
$papers = [];
if ($examId > 0) {
  $stmt = $conn->prepare("
    SELECT p.paper_id,
           p.section_id,
           CONCAT(g.grade_name,'-',s.section_name) AS section_label,
           sub.subject_name
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

  // if paper picked, set sectionId auto
  if ($paperId > 0 && $sectionId <= 0) {
    foreach ($papers as $pp) {
      if ((int)$pp["paper_id"] === $paperId) { $sectionId = (int)$pp["section_id"]; break; }
    }
  }
}

/* =========================
   Save marks
   ========================= */
try {
  if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "save_marks") {
    $examIdPost  = (int)($_POST["exam_id"] ?? 0);
    $paperIdPost = (int)($_POST["paper_id"] ?? 0);

    if ($examIdPost <= 0 || $paperIdPost <= 0) {
      throw new RuntimeException("Select Exam and Paper first.");
    }

    $marksInput = $_POST["mark"] ?? [];
    if (!is_array($marksInput)) $marksInput = [];

    $conn->begin_transaction();

    $stmtIns = $conn->prepare("
      INSERT INTO marks (paper_id, enrollment_id, mark_value)
      VALUES (?,?,?)
      ON DUPLICATE KEY UPDATE mark_value=VALUES(mark_value)
    ");

    foreach ($marksInput as $enrollmentId => $val) {
      $enrollmentId = (int)$enrollmentId;
      $val = trim((string)$val);

      if ($enrollmentId <= 0) continue;

      if ($val === "") {
        // empty -> skip (do not overwrite)
        continue;
      }

      if (!is_numeric($val)) {
        throw new RuntimeException("Invalid mark for enrollment_id=".$enrollmentId);
      }

      $mark = (int)$val;
      if ($mark < 0 || $mark > 100) {
        throw new RuntimeException("Mark must be 0..100 (enrollment_id=".$enrollmentId.")");
      }

      $stmtIns->bind_param("iii", $paperIdPost, $enrollmentId, $mark);
      $stmtIns->execute();
    }

    $stmtIns->close();
    $conn->commit();

    flash("success","Saved","Marks saved successfully.");
    header("Location: marks.php?exam_id=".$examIdPost."&paper_id=".$paperIdPost); exit;
  }
} catch (Throwable $e) {
  try { $conn->rollback(); } catch (Throwable $x) {}
  flash("error","Error",$e->getMessage());
  header("Location: marks.php?exam_id=".$examId."&paper_id=".$paperId); exit;
}

/* =========================
   Load students for selected paper
   ========================= */
$rows = [];
$paperInfo = null;

if ($examId > 0 && $paperId > 0) {
  // paper info
  $stmt = $conn->prepare("
    SELECT p.paper_id,
           CONCAT(g.grade_name,'-',s.section_name) AS section_label,
           sub.subject_name
    FROM exam_papers p
    JOIN sections s ON s.section_id=p.section_id
    JOIN grades g ON g.grade_id=s.grade_id
    JOIN subjects sub ON sub.subject_id=p.subject_id
    WHERE p.paper_id=? AND p.exam_id=?
  ");
  $stmt->bind_param("ii", $paperId, $examId);
  $stmt->execute();
  $paperInfo = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  // students in that section (ENROLLED)
  $stmt = $conn->prepare("
    SELECT en.enrollment_id,
           CONCAT(st.first_name,' ',st.middle_name,' ',st.last_name) AS student_name,
           en.roll_no,
           mk.mark_value,
           mk.result
    FROM enrollments en
    JOIN students st ON st.student_id=en.student_id
    LEFT JOIN marks mk ON mk.enrollment_id=en.enrollment_id AND mk.paper_id=?
    WHERE en.section_id=? AND en.status='ENROLLED'
    ORDER BY student_name ASC
  ");
  $stmt->bind_param("ii", $paperId, $sectionId);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
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
  <title>Marks</title>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    body{font-family:system-ui,Segoe UI,Arial; background:#070f1c; color:#e7eefc; margin:0}
    .wrap{max-width:1200px; margin:0 auto; padding:24px}
    .top{display:flex; justify-content:space-between; align-items:center; gap:12px}
    h1{margin:0; font-size:22px}
    .card{background:#0f1b33; border:1px solid rgba(255,255,255,.08); border-radius:16px; padding:16px; box-shadow:0 10px 25px rgba(0,0,0,.25)}
    label{font-size:12px; opacity:.85}
    input,select{width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(255,255,255,.12); background:#070f1c; color:#e7eefc; outline:none}
    .btn{border:0; padding:10px 14px; border-radius:12px; cursor:pointer; font-weight:650}
    .btn-primary{background:#2d6cff; color:#fff}
    .btn-ghost{background:transparent; color:#e7eefc; border:1px solid rgba(255,255,255,.14)}
    table{width:100%; border-collapse:collapse}
    th,td{padding:10px; border-bottom:1px solid rgba(255,255,255,.08); font-size:14px}
    th{text-align:left; opacity:.85}
    .muted{opacity:.7}
    .badge{display:inline-block; padding:4px 8px; border-radius:999px; font-size:12px; border:1px solid rgba(255,255,255,.16)}
    .grid{display:grid; grid-template-columns: 1fr; gap:16px; margin-top:16px}
    a{color:#9cc2ff; text-decoration:none}
    .search{display:flex; gap:10px; align-items:center}
    .search input{max-width:340px}
    .pill{display:inline-block; padding:6px 10px; border-radius:999px; background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.1)}
  </style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <h1>📝 Marks Entry</h1>
    <div class="search">
      <input id="searchBox" type="text" placeholder="Live search student / roll..."/>
      <a class="btn btn-ghost" href="dashboardadmin.php">⬅ Back</a>
    </div>
  </div>

  <div class="card" style="margin-top:14px;">
    <form method="get" style="display:grid; grid-template-columns: 1fr 1fr auto auto; gap:10px; align-items:end;">
      <div>
        <label>Exam</label>
        <select name="exam_id" onchange="this.form.submit()" required>
          <option value="">-- Select exam --</option>
          <?php foreach ($exams as $ex): ?>
            <option value="<?= (int)$ex["exam_id"] ?>" <?= ($examId==(int)$ex["exam_id"])?"selected":"" ?>>
              <?= e($ex["exam_name"]) ?> (<?= e($ex["year_name"]) ?> - <?= e($ex["term_name"]) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label>Paper (Section + Subject)</label>
        <select name="paper_id" onchange="this.form.submit()" <?= ($examId<=0)?"disabled":"" ?> required>
          <option value="">-- Select paper --</option>
          <?php foreach ($papers as $p): ?>
            <option value="<?= (int)$p["paper_id"] ?>" <?= ($paperId==(int)$p["paper_id"])?"selected":"" ?>>
              <?= e($p["section_label"]) ?> • <?= e($p["subject_name"]) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <a class="btn btn-ghost" href="exams.php?exam_id=<?= (int)$examId ?>">Manage Papers</a>
      <a class="btn btn-ghost" href="results.php?exam_id=<?= (int)$examId ?>">View Results</a>
    </form>

    <?php if ($paperInfo): ?>
      <div style="margin-top:10px" class="pill">
        Selected: <b><?= e($paperInfo["section_label"]) ?></b> • <b><?= e($paperInfo["subject_name"]) ?></b>
      </div>
    <?php endif; ?>
  </div>

  <div class="grid">
    <div class="card">
      <div style="display:flex; justify-content:space-between; align-items:center; gap:10px;">
        <h3 style="margin:0">Students</h3>
        <span class="muted">Enter 0..100 (PASS/FAIL auto)</span>
      </div>

      <?php if ($examId<=0 || $paperId<=0): ?>
        <div class="muted" style="margin-top:10px;">Select exam and paper to enter marks.</div>
      <?php else: ?>
        <form method="post" style="margin-top:10px;">
          <input type="hidden" name="action" value="save_marks"/>
          <input type="hidden" name="exam_id" value="<?= (int)$examId ?>"/>
          <input type="hidden" name="paper_id" value="<?= (int)$paperId ?>"/>

          <div style="overflow:auto;">
            <table id="marksTable">
              <thead>
                <tr>
                  <th>Student</th>
                  <th>Roll</th>
                  <th>Mark</th>
                  <th>Result</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td><b><?= e($r["student_name"]) ?></b></td>
                  <td class="muted"><?= e((string)($r["roll_no"] ?? "")) ?></td>
                  <td style="max-width:140px;">
                    <input
                      name="mark[<?= (int)$r["enrollment_id"] ?>]"
                      value="<?= ($r["mark_value"] === null) ? "" : (int)$r["mark_value"] ?>"
                      placeholder="e.g. 78"
                      inputmode="numeric"
                    />
                  </td>
                  <td>
                    <?php if ($r["result"] === null): ?>
                      <span class="muted">—</span>
                    <?php else: ?>
                      <span class="badge"><?= e($r["result"]) ?></span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$rows): ?>
                <tr><td colspan="4" class="muted">No enrolled students in this section.</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>

          <div style="display:flex; gap:10px; margin-top:12px;">
            <button class="btn btn-primary" type="submit">💾 Save Marks</button>
            <a class="btn btn-ghost" href="marks.php?exam_id=<?= (int)$examId ?>&paper_id=<?= (int)$paperId ?>">Refresh</a>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
  const sb = document.getElementById('searchBox');
  const tb = document.getElementById('marksTable');
  if (sb && tb) {
    sb.addEventListener('input', () => {
      const q = sb.value.toLowerCase();
      [...tb.querySelectorAll('tbody tr')].forEach(tr => {
        tr.style.display = tr.innerText.toLowerCase().includes(q) ? '' : 'none';
      });
    });
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
