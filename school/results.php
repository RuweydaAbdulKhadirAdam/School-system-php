<?php
declare(strict_types=1);

ob_start();
session_start();
require_once __DIR__ . "/conncation.php";

if (!isset($_SESSION["user_id"])) { header("Location: login.php"); exit; }

function flash(string $type, string $title, string $text): void {
  $_SESSION["flash"] = ["type"=>$type, "title"=>$title, "text"=>$text];
}

$examId = (int)($_GET["exam_id"] ?? 0);
$enrollmentIdView = (int)($_GET["enrollment_id"] ?? 0);

// Filters
$filterSectionId = (int)($_GET["section_id"] ?? 0);

// Exams dropdown
$exams = $conn->query("
  SELECT e.exam_id, e.exam_name, y.year_name, t.term_name, e.start_date
  FROM exams e
  JOIN academic_years y ON y.year_id=e.year_id
  JOIN exam_terms t ON t.term_id=e.term_id
  ORDER BY e.start_date DESC, e.exam_id DESC
")->fetch_all(MYSQLI_ASSOC);

// Sections dropdown (Class/Section filter)
$sections = $conn->query("
  SELECT s.section_id, CONCAT(g.grade_name,'-',s.section_name) AS section_label
  FROM sections s
  JOIN grades g ON g.grade_id=s.grade_id
  ORDER BY g.sort_order ASC, s.section_name ASC
")->fetch_all(MYSQLI_ASSOC);

// Refresh summary
try {
  if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "refresh_summary") {
    $examIdPost = (int)($_POST["exam_id"] ?? 0);
    if ($examIdPost <= 0) throw new RuntimeException("Select exam first.");

    $conn->begin_transaction();

    $stmt = $conn->prepare("DELETE FROM student_result_summary WHERE exam_id=?");
    $stmt->bind_param("i", $examIdPost);
    $stmt->execute();
    $stmt->close();

    $sql = "
      INSERT INTO student_result_summary (exam_id, enrollment_id, total_subjects, total_pass, total_fail, average_score, status)
      SELECT
        ? AS exam_id,
        en.enrollment_id,
        COUNT(p.paper_id) AS total_subjects,
        SUM(CASE WHEN mk.result='PASS' THEN 1 ELSE 0 END) AS total_pass,
        SUM(CASE WHEN mk.result='FAIL' THEN 1 ELSE 0 END) AS total_fail,
        ROUND(AVG(mk.mark_value), 2) AS average_score,
        CASE
          WHEN SUM(CASE WHEN mk.result='FAIL' THEN 1 ELSE 0 END) = 0 AND AVG(mk.mark_value) >= 50 THEN 'PASS'
          ELSE 'FAIL'
        END AS status
      FROM enrollments en
      JOIN exam_papers p ON p.exam_id=? AND p.section_id=en.section_id
      LEFT JOIN marks mk ON mk.paper_id=p.paper_id AND mk.enrollment_id=en.enrollment_id
      WHERE en.status='ENROLLED'
      GROUP BY en.enrollment_id
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $examIdPost, $examIdPost);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    flash("success","Updated","Result summaries refreshed.");
    header("Location: results.php?exam_id=".$examIdPost); exit;
  }
} catch (Throwable $e) {
  try { $conn->rollback(); } catch (Throwable $x) {}
  flash("error","Error",$e->getMessage());
  header("Location: results.php?exam_id=".$examId); exit;
}

// Summary rows (with optional section filter)
$summaryRows = [];
if ($examId > 0) {
  $sql = "
    SELECT
      srs.enrollment_id,
      st.student_id,
      CONCAT(st.first_name,' ',st.middle_name,' ',st.last_name) AS student_name,
      CONCAT(g.grade_name,'-',sec.section_name) AS section_label,
      sec.section_id,
      srs.total_subjects, srs.total_pass, srs.total_fail, srs.average_score, srs.status
    FROM student_result_summary srs
    JOIN enrollments en ON en.enrollment_id=srs.enrollment_id
    JOIN students st ON st.student_id=en.student_id
    JOIN sections sec ON sec.section_id=en.section_id
    JOIN grades g ON g.grade_id=sec.grade_id
    WHERE srs.exam_id=?
  ";

  if ($filterSectionId > 0) {
    $sql .= " AND sec.section_id=? ";
  }

  $sql .= " ORDER BY g.sort_order ASC, sec.section_name ASC, student_name ASC ";

  $stmt = $conn->prepare($sql);

  if ($filterSectionId > 0) {
    $stmt->bind_param("ii", $examId, $filterSectionId);
  } else {
    $stmt->bind_param("i", $examId);
  }

  $stmt->execute();
  $summaryRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}

// Detail view
$detail = [];
$detailHeader = null;

if ($examId > 0 && $enrollmentIdView > 0) {
  $stmt = $conn->prepare("
    SELECT en.enrollment_id,
           st.student_id,
           CONCAT(st.first_name,' ',st.middle_name,' ',st.last_name) AS student_name,
           CONCAT(g.grade_name,'-',sec.section_name) AS section_label
    FROM enrollments en
    JOIN students st ON st.student_id=en.student_id
    JOIN sections sec ON sec.section_id=en.section_id
    JOIN grades g ON g.grade_id=sec.grade_id
    WHERE en.enrollment_id=?
  ");
  $stmt->bind_param("i", $enrollmentIdView);
  $stmt->execute();
  $detailHeader = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  $stmt = $conn->prepare("
    SELECT p.paper_id, sub.subject_name, mk.mark_value, mk.result
    FROM exam_papers p
    JOIN subjects sub ON sub.subject_id=p.subject_id
    JOIN enrollments en ON en.section_id=p.section_id
    LEFT JOIN marks mk ON mk.paper_id=p.paper_id AND mk.enrollment_id=en.enrollment_id
    WHERE p.exam_id=? AND en.enrollment_id=?
    ORDER BY sub.subject_name ASC
  ");
  $stmt->bind_param("ii", $examId, $enrollmentIdView);
  $stmt->execute();
  $detail = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
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
  <title>Results</title>
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
    .grid{display:grid; grid-template-columns: 1.2fr .8fr; gap:16px; margin-top:16px}
    a{color:#9cc2ff; text-decoration:none}
    .search{display:flex; gap:10px; align-items:center}
    .search input{max-width:340px}
    .toolbar{display:grid; grid-template-columns: 1fr 1fr 1fr; gap:10px; margin-top:12px}
    .pill{display:inline-block; padding:6px 10px; border-radius:999px; background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.1)}
  </style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <h1>📊 Results & Summaries</h1>
    <div class="search">
      <input id="searchBox" type="text" placeholder="Search by Name / Student ID / Enrollment ID / Class..."/>
      <a class="btn btn-ghost" href="dashboardadmin.php">⬅ Back</a>
    </div>
  </div>

  <div class="card" style="margin-top:14px;">
    <form method="get">
      <div class="toolbar">
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
          <label>Filter by Class/Section</label>
          <select name="section_id" onchange="this.form.submit()" <?= ($examId<=0)?"disabled":"" ?>>
            <option value="0">-- All Classes --</option>
            <?php foreach ($sections as $s): ?>
              <option value="<?= (int)$s["section_id"] ?>" <?= ($filterSectionId==(int)$s["section_id"])?"selected":"" ?>>
                <?= e($s["section_label"]) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div style="display:flex; gap:10px; align-items:end; justify-content:flex-end;">
          <a class="btn btn-ghost" href="marks.php?exam_id=<?= (int)$examId ?>">Enter Marks</a>
          <a class="btn btn-ghost" href="exams.php?exam_id=<?= (int)$examId ?>">Manage Papers</a>
        </div>
      </div>
    </form>

    <?php if ($examId>0): ?>
      <form method="post" style="margin-top:12px;">
        <input type="hidden" name="action" value="refresh_summary"/>
        <input type="hidden" name="exam_id" value="<?= (int)$examId ?>"/>
        <button class="btn btn-primary" type="submit">🔄 Generate/Refresh Summary</button>
        <span class="muted" style="margin-left:10px;">(Builds student_result_summary)</span>
      </form>
    <?php else: ?>
      <div class="muted" style="margin-top:12px;">Select exam first, then refresh summary.</div>
    <?php endif; ?>
  </div>

  <div class="grid">
    <!-- Summary -->
    <div class="card">
      <div style="display:flex; justify-content:space-between; align-items:center; gap:10px;">
        <h3 style="margin:0">Summary List</h3>
        <span class="muted">Click student to view details</span>
      </div>

      <div style="overflow:auto; margin-top:10px;">
        <table id="summaryTable">
          <thead>
            <tr>
              <th>Student</th>
              <th>Student ID</th>
              <th>Enroll ID</th>
              <th>Class</th>
              <th>Avg</th>
              <th>Status</th>
              <th style="width:110px;">Edit</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($summaryRows as $r): ?>
            <tr>
              <td>
                <a href="results.php?exam_id=<?= (int)$examId ?>&section_id=<?= (int)$filterSectionId ?>&enrollment_id=<?= (int)$r["enrollment_id"] ?>">
                  <b><?= e($r["student_name"]) ?></b>
                </a>
              </td>
              <td class="muted"><?= (int)$r["student_id"] ?></td>
              <td class="muted"><?= (int)$r["enrollment_id"] ?></td>
              <td class="muted"><?= e($r["section_label"]) ?></td>
              <td><?= e((string)($r["average_score"] ?? "")) ?></td>
              <td><span class="badge"><?= e($r["status"]) ?></span></td>
              <td>
                <a class="btn btn-ghost" style="padding:7px 10px" href="edit_mark.php?exam_id=<?= (int)$examId ?>&enrollment_id=<?= (int)$r["enrollment_id"] ?>">Edit</a>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php if (!$summaryRows && $examId>0): ?>
            <tr><td colspan="7" class="muted">No summaries found. Click “Generate/Refresh Summary”.</td></tr>
          <?php endif; ?>

          <?php if ($examId<=0): ?>
            <tr><td colspan="7" class="muted">Select exam to view results.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Detail -->
    <div class="card">
      <h3 style="margin:0 0 10px 0;">Student Detail</h3>

      <?php if ($detailHeader): ?>
        <div class="pill">
          <b><?= e($detailHeader["student_name"]) ?></b> •
          Student ID: <b><?= (int)$detailHeader["student_id"] ?></b> •
          Enroll ID: <b><?= (int)$detailHeader["enrollment_id"] ?></b> •
          Class: <b><?= e($detailHeader["section_label"]) ?></b>
        </div>

        <div style="overflow:auto; margin-top:12px;">
          <table>
            <thead>
              <tr>
                <th>Subject</th>
                <th>Mark</th>
                <th>Result</th>
                <th style="width:110px;">Edit</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($detail as $d): ?>
                <tr>
                  <td><?= e($d["subject_name"]) ?></td>
                  <td><?= ($d["mark_value"] === null) ? '<span class="muted">—</span>' : (int)$d["mark_value"] ?></td>
                  <td><?= ($d["result"] === null) ? '<span class="muted">—</span>' : '<span class="badge">'.e($d["result"]).'</span>' ?></td>
                  <td>
                    <a class="btn btn-ghost" style="padding:7px 10px"
                       href="edit_mark.php?exam_id=<?= (int)$examId ?>&enrollment_id=<?= (int)$enrollmentIdView ?>">
                       Edit
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$detail): ?>
                <tr><td colspan="4" class="muted">No papers found (check exams.php → add papers).</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="muted" style="margin-top:10px;">To change marks, click Edit.</div>
      <?php else: ?>
        <div class="muted">Click any student from Summary List to view details.</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
  // search by name / ids / class
  const sb = document.getElementById('searchBox');
  const tb = document.getElementById('summaryTable');
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
    timer: 2200,
    showConfirmButton: false
  });
  <?php endif; ?>
</script>
</body>
</html>
<?php ob_end_flush(); ?>
