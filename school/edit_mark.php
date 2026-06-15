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
$enrollmentId = (int)($_GET["enrollment_id"] ?? 0);

if ($examId <= 0 || $enrollmentId <= 0) {
  flash("error","Error","Missing exam_id or enrollment_id.");
  header("Location: results.php?exam_id=".$examId); exit;
}

// Header student
$stmt = $conn->prepare("
  SELECT en.enrollment_id,
         st.student_id,
         CONCAT(st.first_name,' ',st.middle_name,' ',st.last_name) AS student_name,
         CONCAT(g.grade_name,'-',sec.section_name) AS section_label,
         e.exam_name
  FROM enrollments en
  JOIN students st ON st.student_id=en.student_id
  JOIN sections sec ON sec.section_id=en.section_id
  JOIN grades g ON g.grade_id=sec.grade_id
  JOIN exams e ON e.exam_id=?
  WHERE en.enrollment_id=?
");
$stmt->bind_param("ii", $examId, $enrollmentId);
$stmt->execute();
$header = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$header) {
  flash("error","Error","Student/Enrollment not found.");
  header("Location: results.php?exam_id=".$examId); exit;
}

// Save edits
try {
  if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "save_edit") {
    $marks = $_POST["mark"] ?? [];
    if (!is_array($marks)) $marks = [];

    $conn->begin_transaction();

    $stmtUp = $conn->prepare("
      INSERT INTO marks (paper_id, enrollment_id, mark_value)
      VALUES (?,?,?)
      ON DUPLICATE KEY UPDATE mark_value=VALUES(mark_value)
    ");

    foreach ($marks as $paperId => $val) {
      $paperId = (int)$paperId;
      $val = trim((string)$val);

      if ($paperId <= 0) continue;

      if ($val === "") continue; // skip empty

      if (!is_numeric($val)) {
        throw new RuntimeException("Invalid mark value.");
      }

      $mark = (int)$val;
      if ($mark < 0 || $mark > 100) {
        throw new RuntimeException("Mark must be between 0 and 100.");
      }

      $stmtUp->bind_param("iii", $paperId, $enrollmentId, $mark);
      $stmtUp->execute();
    }

    $stmtUp->close();
    $conn->commit();

    flash("success","Saved","Marks updated successfully.");
    header("Location: edit_mark.php?exam_id=".$examId."&enrollment_id=".$enrollmentId); exit;
  }
} catch (Throwable $e) {
  try { $conn->rollback(); } catch (Throwable $x) {}
  flash("error","Error",$e->getMessage());
  header("Location: edit_mark.php?exam_id=".$examId."&enrollment_id=".$enrollmentId); exit;
}

// Load subjects papers + current mark for this student
$stmt = $conn->prepare("
  SELECT p.paper_id,
         sub.subject_name,
         mk.mark_value,
         mk.result
  FROM exam_papers p
  JOIN subjects sub ON sub.subject_id=p.subject_id
  JOIN enrollments en ON en.section_id=p.section_id
  LEFT JOIN marks mk ON mk.paper_id=p.paper_id AND mk.enrollment_id=en.enrollment_id
  WHERE p.exam_id=? AND en.enrollment_id=?
  ORDER BY sub.subject_name ASC
");
$stmt->bind_param("ii", $examId, $enrollmentId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$flash = $_SESSION["flash"] ?? null;
unset($_SESSION["flash"]);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Edit Marks</title>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    body{font-family:system-ui,Segoe UI,Arial; background:#070f1c; color:#e7eefc; margin:0}
    .wrap{max-width:1000px; margin:0 auto; padding:24px}
    .top{display:flex; justify-content:space-between; align-items:center; gap:12px}
    h1{margin:0; font-size:22px}
    .card{background:#0f1b33; border:1px solid rgba(255,255,255,.08); border-radius:16px; padding:16px; box-shadow:0 10px 25px rgba(0,0,0,.25)}
    .btn{border:0; padding:10px 14px; border-radius:12px; cursor:pointer; font-weight:650}
    .btn-primary{background:#2d6cff; color:#fff}
    .btn-ghost{background:transparent; color:#e7eefc; border:1px solid rgba(255,255,255,.14)}
    table{width:100%; border-collapse:collapse; margin-top:10px}
    th,td{padding:10px; border-bottom:1px solid rgba(255,255,255,.08); font-size:14px}
    th{text-align:left; opacity:.85}
    input{width:120px; padding:10px 12px; border-radius:12px; border:1px solid rgba(255,255,255,.12); background:#070f1c; color:#e7eefc; outline:none}
    .badge{display:inline-block; padding:4px 8px; border-radius:999px; font-size:12px; border:1px solid rgba(255,255,255,.16)}
    .muted{opacity:.7}
    .pill{display:inline-block; padding:6px 10px; border-radius:999px; background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.1)}
  </style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <h1>✏️ Edit Marks</h1>
    <div style="display:flex; gap:10px;">
      <a class="btn btn-ghost" href="results.php?exam_id=<?= (int)$examId ?>&enrollment_id=<?= (int)$enrollmentId ?>">⬅ Back Results</a>
      <a class="btn btn-ghost" href="marks.php?exam_id=<?= (int)$examId ?>">Go Marks Page</a>
    </div>
  </div>

  <div class="card" style="margin-top:14px;">
    <div class="pill">
      Exam: <b><?= e($header["exam_name"]) ?></b> •
      Student: <b><?= e($header["student_name"]) ?></b> •
      Student ID: <b><?= (int)$header["student_id"] ?></b> •
      Enrollment: <b><?= (int)$header["enrollment_id"] ?></b> •
      Class: <b><?= e($header["section_label"]) ?></b>
    </div>

    <form method="post" style="margin-top:12px;">
      <input type="hidden" name="action" value="save_edit"/>

      <table>
        <thead>
          <tr>
            <th>Subject</th>
            <th>Mark (0..100)</th>
            <th>Result</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><b><?= e($r["subject_name"]) ?></b></td>
              <td>
                <input name="mark[<?= (int)$r["paper_id"] ?>]"
                       value="<?= ($r["mark_value"]===null) ? "" : (int)$r["mark_value"] ?>"
                       placeholder="e.g. 85" />
              </td>
              <td>
                <?php if ($r["result"]===null): ?>
                  <span class="muted">—</span>
                <?php else: ?>
                  <span class="badge"><?= e($r["result"]) ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php if (!$rows): ?>
            <tr><td colspan="3" class="muted">No papers found. Check exams.php → add papers.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>

      <div style="display:flex; gap:10px; margin-top:12px;">
        <button class="btn btn-primary" type="submit">💾 Save Changes</button>
        <a class="btn btn-ghost" href="edit_mark.php?exam_id=<?= (int)$examId ?>&enrollment_id=<?= (int)$enrollmentId ?>">Refresh</a>
      </div>

      <div class="muted" style="margin-top:10px;">
        PASS/FAIL will update automatically (trigger).
      </div>
    </form>
  </div>
</div>

<script>
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
