<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . "/conncation.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* =========================
   GUARD (Admin/Reception/Teacher)
   ========================= */
if (!isset($_SESSION["user_id"])) { header("Location: login.php"); exit; }
if (!isset($_SESSION["role"]) || !in_array($_SESSION["role"], ["ADMIN","RECEPTION","TEACHER"], true)) {
  header("Location: login.php"); exit;
}

$userId = (int)($_SESSION["user_id"]);
$role   = (string)($_SESSION["role"] ?? "");

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8"); }

function setFlash(string $type, string $title, string $text): void {
  $_SESSION["flash_alert"] = ["type"=>$type, "title"=>$title, "text"=>$text];
}
function popFlash(): ?array {
  if (!isset($_SESSION["flash_alert"])) return null;
  $a = $_SESSION["flash_alert"];
  unset($_SESSION["flash_alert"]);
  return $a;
}
$alert = popFlash();

/* =========================
   Helpers
   ========================= */
function mapDayNameFromDate(string $date): ?string {
  $d = date("D", strtotime($date));
  return match ($d) {
    "Sat" => "SAT",
    "Sun" => "SUN",
    "Mon" => "MON",
    "Tue" => "TUE",
    "Wed" => "WED",
    default => null,
  };
}

function getDayId(mysqli $conn, string $dayName): int {
  $st = $conn->prepare("SELECT day_id FROM week_days WHERE day_name=? LIMIT 1");
  $st->bind_param("s", $dayName);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();
  return (int)($row["day_id"] ?? 0);
}

function ensureSession(
  mysqli $conn,
  int $year_id,
  int $section_id,
  string $session_date,
  int $day_id,
  int $slot_id,
  int $subject_id,
  int $teacher_id
): int {
  $st = $conn->prepare("
    SELECT session_id FROM attendance_sessions
    WHERE section_id=? AND session_date=? AND slot_id=? AND subject_id=?
    LIMIT 1
  ");
  $st->bind_param("isii", $section_id, $session_date, $slot_id, $subject_id);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();

  if ($row) return (int)$row["session_id"];

  $st = $conn->prepare("
    INSERT INTO attendance_sessions(year_id, section_id, session_date, day_id, slot_id, subject_id, teacher_id, status)
    VALUES(?,?,?,?,?,?,?,'OPEN')
  ");
  $st->bind_param("iisiiii", $year_id, $section_id, $session_date, $day_id, $slot_id, $subject_id, $teacher_id);
  $st->execute();
  $id = (int)$conn->insert_id;
  $st->close();

  return $id;
}

/* =========================
   Current year default
   ========================= */
$currentYear = null;
$rs = $conn->query("SELECT year_id, year_name FROM academic_years WHERE is_current=1 LIMIT 1");
$currentYear = $rs->fetch_assoc();

$year_id    = (int)($_GET["year_id"] ?? ($currentYear["year_id"] ?? 0));
$section_id = (int)($_GET["section_id"] ?? 0);
$session_id = (int)($_GET["session_id"] ?? 0);

/* =========================
   Lists: years / slots / subjects / teachers
   ========================= */
$years = [];
$rs = $conn->query("SELECT year_id, year_name, is_current FROM academic_years ORDER BY start_date DESC");
while ($r = $rs->fetch_assoc()) $years[] = $r;

$slots = [];
$rs = $conn->query("SELECT slot_id, slot_no, start_time, end_time, is_break FROM time_slots ORDER BY slot_no ASC, start_time ASC");
while ($r = $rs->fetch_assoc()) $slots[] = $r;

$subjects = [];
$rs = $conn->query("SELECT subject_id, subject_name FROM subjects WHERE is_active=1 ORDER BY subject_name ASC");
while ($r = $rs->fetch_assoc()) $subjects[] = $r;

$teachers = [];
$rs = $conn->query("
  SELECT t.teacher_id, e.full_name
  FROM teachers t
  JOIN employees e ON e.employee_id = t.employee_id
  ORDER BY e.full_name ASC
");
while ($r = $rs->fetch_assoc()) $teachers[] = $r;

/* =========================
   If session_id provided: load header
   ========================= */
$sessionHeader = null;
if ($session_id > 0) {
  $st = $conn->prepare("
    SELECT s.*, ay.year_name,
           CONCAT(g.grade_name,' - ',sec.section_name) AS class_name,
           sub.subject_name,
           emp.full_name AS teacher_name,
           ts.slot_no, ts.start_time, ts.end_time
    FROM attendance_sessions s
    JOIN academic_years ay ON ay.year_id = s.year_id
    JOIN sections sec ON sec.section_id = s.section_id
    JOIN grades g ON g.grade_id = sec.grade_id
    JOIN subjects sub ON sub.subject_id = s.subject_id
    JOIN teachers t ON t.teacher_id = s.teacher_id
    JOIN employees emp ON emp.employee_id = t.employee_id
    JOIN time_slots ts ON ts.slot_id = s.slot_id
    WHERE s.session_id=?
    LIMIT 1
  ");
  $st->bind_param("i", $session_id);
  $st->execute();
  $sessionHeader = $st->get_result()->fetch_assoc();
  $st->close();

  if ($sessionHeader) {
    $year_id    = (int)$sessionHeader["year_id"];
    $section_id = (int)$sessionHeader["section_id"];
  } else {
    setFlash("error","Qalad!","Session-ka lama helin.");
    header("Location: attendance_sessions.php");
    exit;
  }
}

/* =========================
   Load class info
   ========================= */
$classInfo = null;
if ($section_id > 0) {
  $st = $conn->prepare("
    SELECT sec.section_id, CONCAT(g.grade_name,' - ', sec.section_name) AS class_name
    FROM sections sec
    JOIN grades g ON g.grade_id = sec.grade_id
    WHERE sec.section_id=?
    LIMIT 1
  ");
  $st->bind_param("i", $section_id);
  $st->execute();
  $classInfo = $st->get_result()->fetch_assoc();
  $st->close();
}

/* =========================
   Load students (enrolled)
   ========================= */
$students = [];
if ($year_id > 0 && $section_id > 0) {
  $st = $conn->prepare("
    SELECT
      e.enrollment_id, e.student_id, e.roll_no,
      CONCAT(st.first_name,' ',st.middle_name,' ',st.last_name) AS full_name,
      st.gender
    FROM enrollments e
    JOIN students st ON st.student_id = e.student_id
    WHERE e.year_id=? AND e.section_id=? AND e.status='ENROLLED'
    ORDER BY
      CASE WHEN e.roll_no IS NULL OR e.roll_no='' THEN 1 ELSE 0 END,
      e.roll_no ASC,
      e.student_id ASC
  ");
  $st->bind_param("ii", $year_id, $section_id);
  $st->execute();
  $res = $st->get_result();
  while ($r = $res->fetch_assoc()) $students[] = $r;
  $st->close();
}

/* =========================
   Existing records for session
   ========================= */
$existingRecords = [];
if ($session_id > 0) {
  $st = $conn->prepare("SELECT enrollment_id, status FROM attendance_records WHERE session_id=?");
  $st->bind_param("i", $session_id);
  $st->execute();
  $res = $st->get_result();
  while ($r = $res->fetch_assoc()) {
    $existingRecords[(int)$r["enrollment_id"]] = (string)$r["status"];
  }
  $st->close();
}

/* =========================
   POST: Save attendance
   ========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $action = (string)($_POST["action"] ?? "");
  if ($action !== "save_attendance") {
    setFlash("warning","Info","Action lama aqoonsan.");
    header("Location: attendance_admin.php");
    exit;
  }

  $year_id_p    = (int)($_POST["year_id"] ?? 0);
  $section_id_p = (int)($_POST["section_id"] ?? 0);
  $date_p       = (string)($_POST["session_date"] ?? "");
  $slot_id_p    = (int)($_POST["slot_id"] ?? 0);
  $subject_id_p = (int)($_POST["subject_id"] ?? 0);
  $teacher_id_p = (int)($_POST["teacher_id"] ?? 0);
  $session_id_p = (int)($_POST["session_id"] ?? 0);

  if ($year_id_p<=0 || $section_id_p<=0 || $slot_id_p<=0 || $subject_id_p<=0 || $teacher_id_p<=0 || $date_p==="") {
    setFlash("error","Qalad!","Fadlan buuxi Year + Class + Date + Slot + Subject + Teacher.");
    header("Location: attendance_take.php?year_id=$year_id_p&section_id=$section_id_p");
    exit;
  }

  $dayName = mapDayNameFromDate($date_p);
  if ($dayName === null) {
    setFlash("error","Qalad!","Taariikhdaas waa Thu/Fri (system-kaaga Sat–Wed ayuu taageeraa). Dooro Sat–Wed.");
    header("Location: attendance_take.php?year_id=$year_id_p&section_id=$section_id_p");
    exit;
  }
  $day_id_p = getDayId($conn, $dayName);
  if ($day_id_p <= 0) {
    setFlash("error","Qalad!","week_days table-ka day lama helin. (SAT–WED seed hubi).");
    header("Location: attendance_take.php?year_id=$year_id_p&section_id=$section_id_p");
    exit;
  }

  // prevent break slot
  $st = $conn->prepare("SELECT is_break FROM time_slots WHERE slot_id=? LIMIT 1");
  $st->bind_param("i", $slot_id_p);
  $st->execute();
  $slotRow = $st->get_result()->fetch_assoc();
  $st->close();
  if ($slotRow && (int)$slotRow["is_break"]===1) {
    setFlash("warning","Digniin!","Break slot attendance laguma qaadi karo.");
    header("Location: attendance_take.php?year_id=$year_id_p&section_id=$section_id_p");
    exit;
  }

  // Load enrollments again
  $enrollments = [];
  $st = $conn->prepare("SELECT enrollment_id FROM enrollments WHERE year_id=? AND section_id=? AND status='ENROLLED'");
  $st->bind_param("ii", $year_id_p, $section_id_p);
  $st->execute();
  $res = $st->get_result();
  while ($r = $res->fetch_assoc()) $enrollments[] = (int)$r["enrollment_id"];
  $st->close();

  if (count($enrollments) === 0) {
    setFlash("warning","Digniin!","Class-kan wax arday ah kuma enrolled aha (year-kan).");
    header("Location: attendance_take.php?year_id=$year_id_p&section_id=$section_id_p");
    exit;
  }

  $statusArr = $_POST["status"] ?? [];
  if (!is_array($statusArr)) $statusArr = [];

  $conn->begin_transaction();
  try {
    $sessId = $session_id_p > 0 ? $session_id_p : ensureSession(
      $conn, $year_id_p, $section_id_p, $date_p, $day_id_p, $slot_id_p, $subject_id_p, $teacher_id_p
    );

    $stIns = $conn->prepare("
      INSERT INTO attendance_records(session_id, enrollment_id, status)
      VALUES(?,?,?)
      ON DUPLICATE KEY UPDATE status=VALUES(status), marked_at=CURRENT_TIMESTAMP
    ");

    foreach ($enrollments as $eid) {
      $stVal = (string)($statusArr[(string)$eid] ?? "A");
      if (!in_array($stVal, ["P","A","L"], true)) $stVal = "A";
      $stIns->bind_param("iis", $sessId, $eid, $stVal);
      $stIns->execute();
    }
    $stIns->close();

    // activity log (optional)
    $act = ($session_id_p > 0) ? "UPDATE_ATTENDANCE" : "CREATE_ATTENDANCE";
    $details = "session_id=$sessId, year_id=$year_id_p, section_id=$section_id_p, date=$date_p, slot_id=$slot_id_p, subject_id=$subject_id_p, teacher_id=$teacher_id_p";
    $entity = "attendance_sessions";
    $st = $conn->prepare("INSERT INTO activity_logs(user_id, action, entity, entity_id, details) VALUES(?,?,?,?,?)");
    $st->bind_param("issis", $userId, $act, $entity, $sessId, $details);
    $st->execute();
    $st->close();

    $conn->commit();
    setFlash("success","Waa guul!","Attendance waa la kaydiyey.");
    header("Location: attendance_take.php?session_id=$sessId");
    exit;

  } catch (Throwable $e) {
    $conn->rollback();
    setFlash("error","Qalad!","Cilad: ".$e->getMessage());
    header("Location: attendance_take.php?year_id=$year_id_p&section_id=$section_id_p");
    exit;
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Take Attendance</title>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    :root{
      --bg:#f6f7fb;--card:#fff;--ink:#0f172a;--muted:#6b7280;
      --primary:#4f46e5;--stroke:#e9ecff;--shadow:0 18px 44px rgba(15,23,42,.10);
      --radius:18px;--good:#16a34a;--bad:#ef4444;--warn:#f59e0b;
    }
    *{box-sizing:border-box}
    body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;background:var(--bg);color:var(--ink)}
    .wrap{max-width:1250px;margin:0 auto;padding:18px}
    .top{background:var(--card);border:1px solid #eef0ff;border-radius:16px;padding:14px 16px;display:flex;align-items:center;gap:12px;box-shadow:0 2px 10px rgba(0,0,0,.04)}
    .actions{margin-left:auto;display:flex;gap:10px;flex-wrap:wrap}
    .btn{border:1px solid #e5e7eb;background:#fff;padding:10px 14px;border-radius:12px;font-weight:950;cursor:pointer;display:inline-flex;align-items:center;gap:10px;color:#111827;text-decoration:none;transition:.15s}
    .btn:hover{transform:translateY(-1px);box-shadow:0 14px 24px rgba(0,0,0,.06)}
    .btn.blue{border-color:rgba(79,70,229,.25);background:rgba(79,70,229,.06);color:var(--primary)}
    .btn.green{border-color:rgba(22,163,74,.25);background:rgba(22,163,74,.08);color:var(--good)}
    .card{margin-top:14px;background:var(--card);border:1px solid #eef0ff;border-radius:var(--radius);box-shadow:var(--shadow);padding:16px}
    .hint{margin-top:8px;color:var(--muted);font-weight:850;font-size:13px;line-height:1.35}
    .badge{display:inline-block;padding:6px 10px;border:1px solid #e5e7eb;border-radius:999px;background:#fff;font-weight:950;color:#374151}
    .row2{display:grid;grid-template-columns:1.2fr 1fr 1fr 1fr 1fr;gap:10px}
    @media (max-width:1100px){.row2{grid-template-columns:1fr 1fr}}
    .field{border:2px solid rgba(79,70,229,.28);border-radius:999px;padding:12px 14px;background:#fff}
    .field label{display:block;font-size:12px;color:var(--muted);font-weight:950;margin-bottom:6px}
    select,input{width:100%;border:none;outline:none;background:transparent;font-size:15px}
    .toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:10px}
    .pill{border:1px solid #e5e7eb;border-radius:999px;padding:10px 14px;font-weight:950;background:#fff}
    .tableWrap{margin-top:14px;border:1px solid var(--stroke);border-radius:16px;overflow:auto}
    table{width:100%;border-collapse:separate;border-spacing:0;background:#fff}
    th,td{padding:12px;border-bottom:1px solid var(--stroke);border-right:1px solid var(--stroke);vertical-align:middle}
    th{background:#f2f4ff;font-size:12px;text-transform:uppercase;letter-spacing:.7px;color:#374151;position:sticky;top:0}
    td:last-child,th:last-child{border-right:none}
    tr:last-child td{border-bottom:none}
    .stuName{font-weight:1000;font-size:15px}
    .meta{color:var(--muted);font-weight:900;font-size:12px;margin-top:3px}
    .dots{display:flex;gap:10px;align-items:center}
    .dotBtn{
      width:48px;height:48px;border-radius:999px;border:2px solid #e5e7eb;background:#fff;
      display:flex;align-items:center;justify-content:center;font-weight:1100;cursor:pointer;
      transition:.12s; user-select:none;
    }
    .dotBtn:hover{transform:translateY(-1px);box-shadow:0 12px 20px rgba(0,0,0,.06)}
    .dotBtn.onP{border-color:rgba(22,163,74,.45);background:rgba(22,163,74,.10);color:var(--good)}
    .dotBtn.onA{border-color:rgba(239,68,68,.45);background:rgba(239,68,68,.10);color:var(--bad)}
    .dotBtn.onL{border-color:rgba(245,158,11,.45);background:rgba(245,158,11,.10);color:var(--warn)}
    .empty{color:#9ca3af;font-weight:1000}
    @media print{
      .top,.noPrint{display:none !important;}
      body{background:#fff}
      .card{box-shadow:none;border:none}
    }
  </style>
</head>
<body>
<div class="wrap">

  <div class="top">
    <b style="font-size:16px;">🟦 Take Attendance (Page Gooni ah)</b>
    <span class="badge"><?= h($role) ?></span>

    <div class="actions">
      <a class="btn" href="attendance_admin.php">⬅️ Back to Classes</a>
      <a class="btn" href="attendance_sessions.php">📚 Sessions</a>
      <button class="btn" onclick="window.print()">🖨 Print</button>
      <a class="btn blue" href="dashboard.php">🏠 Dashboard</a>
    </div>
  </div>

  <div class="card">
    <b style="font-size:16px;">📌 Info</b>
    <div class="hint">
      Class: <b><?= h($classInfo["class_name"] ?? "—") ?></b>
      • Year ID: <b><?= (int)$year_id ?></b>
      <?php if ($sessionHeader): ?>
        • Editing Session: <b>#<?= (int)$sessionHeader["session_id"] ?></b>
        • Date: <b><?= h($sessionHeader["session_date"]) ?></b>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <b style="font-size:16px;">✅ Attendance Form</b>

    <?php if ($year_id<=0 || $section_id<=0): ?>
      <div class="empty">Fadlan ka soo gal Classes page (attendance_admin.php) oo class dooro.</div>
    <?php elseif (count($students) === 0): ?>
      <div style="margin-top:12px;padding:14px;border-radius:14px;border:1px solid #fee2e2;background:#fff1f2;font-weight:950;color:#9f1239">
        Class-kan wax arday ah kuma enrolled aha year-kan.
      </div>
    <?php else: ?>

      <form method="post" action="attendance_take.php" id="attForm">
        <input type="hidden" name="action" value="save_attendance">
        <input type="hidden" name="year_id" value="<?= (int)$year_id ?>">
        <input type="hidden" name="section_id" value="<?= (int)$section_id ?>">
        <input type="hidden" name="session_id" value="<?= (int)$session_id ?>">

        <div class="row2 noPrint" style="margin-top:12px;">
          <div class="field">
            <label>Date (Sat–Wed)</label>
            <input type="date" name="session_date" required
                   value="<?= h($sessionHeader["session_date"] ?? date("Y-m-d")) ?>">
          </div>

          <div class="field">
            <label>Slot (Period)</label>
            <select name="slot_id" required>
              <option value="">Dooro Slot</option>
              <?php foreach ($slots as $sl): ?>
                <?php
                  $isBreak = ((int)$sl["is_break"]===1);
                  $lbl = "Slot ".$sl["slot_no"]." (".substr((string)$sl["start_time"],0,5)." - ".substr((string)$sl["end_time"],0,5).")";
                  if ($isBreak) $lbl .= " • BREAK";
                  $selVal = (int)($sessionHeader["slot_id"] ?? 0);
                ?>
                <option value="<?= (int)$sl["slot_id"] ?>" <?= ($selVal===(int)$sl["slot_id"])?"selected":"" ?> <?= $isBreak ? "disabled" : "" ?>>
                  <?= h($lbl) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label>Subject</label>
            <select name="subject_id" required>
              <option value="">Dooro Subject</option>
              <?php $selSub = (int)($sessionHeader["subject_id"] ?? 0); ?>
              <?php foreach ($subjects as $s): ?>
                <option value="<?= (int)$s["subject_id"] ?>" <?= ($selSub===(int)$s["subject_id"])?"selected":"" ?>>
                  <?= h($s["subject_name"]) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label>Teacher</label>
            <select name="teacher_id" required>
              <option value="">Dooro Teacher</option>
              <?php $selT = (int)($sessionHeader["teacher_id"] ?? 0); ?>
              <?php foreach ($teachers as $t): ?>
                <option value="<?= (int)$t["teacher_id"] ?>" <?= ($selT===(int)$t["teacher_id"])?"selected":"" ?>>
                  <?= h($t["full_name"]) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label>Live Search Student</label>
            <input type="text" id="stuSearch" placeholder="Raadi magac / roll / id..." value="">
          </div>
        </div>

        <div class="toolbar noPrint">
          <div class="pill">Total: <b id="totalCount"><?= count($students) ?></b></div>
          <div class="pill">Present: <b id="pCount">0</b></div>
          <div class="pill">Absent: <b id="aCount">0</b></div>
          <div class="pill">Late: <b id="lCount">0</b></div>

          <select id="sortBy" class="pill" style="cursor:pointer;">
            <option value="roll">Sort: Roll/ID</option>
            <option value="id">Sort: Student ID</option>
            <option value="name">Sort: Name</option>
          </select>

          <button class="btn" type="button" onclick="markAll('P')">✅ All Present</button>
          <button class="btn" type="button" onclick="markAll('A')">❌ All Absent</button>
          <button class="btn" type="button" onclick="markAll('L')">⏰ All Late</button>

          <button class="btn green" type="button" onclick="saveConfirm()">💾 Save Attendance</button>
        </div>

        <div class="tableWrap">
          <table id="stuTable">
            <thead>
              <tr>
                <th style="width:120px;">Roll/ID</th>
                <th>Student</th>
                <th style="width:220px;">Attendance (Dot)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($students as $st): ?>
                <?php
                  $enrollmentId = (int)$st["enrollment_id"];
                  $sid = (int)$st["student_id"];
                  $roll = trim((string)($st["roll_no"] ?? ""));
                  $displayRoll = $roll !== "" ? $roll : ("ID ".$sid);
                  $gender = (string)($st["gender"] ?? "");
                  $gLabel = $gender==="M" ? "Boy" : ($gender==="F" ? "Girl" : "N/A");
                  $status = $existingRecords[$enrollmentId] ?? "A";
                ?>
                <tr class="stuRow"
                    data-roll="<?= h($displayRoll) ?>"
                    data-id="<?= $sid ?>"
                    data-name="<?= h($st["full_name"]) ?>">
                  <td>
                    <div style="font-weight:1000;"><?= h($displayRoll) ?></div>
                    <div class="meta"><?= h($gLabel) ?></div>
                  </td>
                  <td>
                    <div class="stuName"><?= h($st["full_name"]) ?></div>
                    <div class="meta">enrollment_id: <?= $enrollmentId ?></div>
                  </td>
                  <td>
                    <input type="hidden" name="status[<?= $enrollmentId ?>]" id="status_<?= $enrollmentId ?>" value="<?= h($status) ?>">
                    <div class="dots">
                      <div class="dotBtn <?= $status==="P"?"onP":"" ?>" onclick="setStatus(<?= $enrollmentId ?>,'P')">P</div>
                      <div class="dotBtn <?= $status==="A"?"onA":"" ?>" onclick="setStatus(<?= $enrollmentId ?>,'A')">A</div>
                      <div class="dotBtn <?= $status==="L"?"onL":"" ?>" onclick="setStatus(<?= $enrollmentId ?>,'L')">L</div>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="hint">
          <b>P</b> = Present, <b>A</b> = Absent, <b>L</b> = Late. Default waa Absent.
        </div>
      </form>

    <?php endif; ?>
  </div>

</div>

<script>
  <?php if ($alert): ?>
  Swal.fire({
    icon: <?= json_encode($alert["type"]) ?>,
    title: <?= json_encode($alert["title"]) ?>,
    text: <?= json_encode($alert["text"]) ?>,
    confirmButtonText: "Haye"
  });
  <?php endif; ?>

  function setStatus(enrollmentId, st){
    const hidden = document.getElementById("status_"+enrollmentId);
    if (!hidden) return;
    hidden.value = st;

    const tr = hidden.closest("tr");
    if (!tr) return;
    tr.querySelectorAll(".dotBtn").forEach(btn => btn.classList.remove("onP","onA","onL"));
    const btns = tr.querySelectorAll(".dotBtn");
    if (btns.length >= 3) {
      if (st === "P") btns[0].classList.add("onP");
      if (st === "A") btns[1].classList.add("onA");
      if (st === "L") btns[2].classList.add("onL");
    }
    updateCounts();
  }

  function getAllHidden(){
    return Array.from(document.querySelectorAll('input[type="hidden"][name^="status["]'));
  }

  function updateCounts(){
    const all = getAllHidden();
    let p=0,a=0,l=0;
    all.forEach(h => {
      if (h.value === "P") p++;
      else if (h.value === "L") l++;
      else a++;
    });
    const pCount=document.getElementById("pCount");
    const aCount=document.getElementById("aCount");
    const lCount=document.getElementById("lCount");
    if (pCount) pCount.textContent = String(p);
    if (aCount) aCount.textContent = String(a);
    if (lCount) lCount.textContent = String(l);
  }
  updateCounts();

  function markAll(st){
    getAllHidden().forEach(h => {
      const eid = Number((h.id || "").replace("status_",""));
      if (!eid) return;
      setStatus(eid, st);
    });
  }

  // Live search students
  const stuSearch = document.getElementById("stuSearch");
  if (stuSearch) {
    stuSearch.addEventListener("input", () => {
      const q = stuSearch.value.trim().toLowerCase();
      document.querySelectorAll(".stuRow").forEach(r => {
        const t = (r.getAttribute("data-name")+" "+r.getAttribute("data-roll")+" "+r.getAttribute("data-id")).toLowerCase();
        r.style.display = t.includes(q) ? "" : "none";
      });
    });
  }

  // Sort students
  const sortBy = document.getElementById("sortBy");
  if (sortBy) {
    sortBy.addEventListener("change", () => {
      const tbody = document.querySelector("#stuTable tbody");
      if (!tbody) return;
      const rows = Array.from(tbody.querySelectorAll("tr"));

      const mode = sortBy.value;
      rows.sort((a,b) => {
        if (mode === "name") return (a.dataset.name||"").localeCompare(b.dataset.name||"");
        if (mode === "id") return Number(a.dataset.id||0) - Number(b.dataset.id||0);

        const ar = (a.dataset.roll||"").replace("ID ","");
        const br = (b.dataset.roll||"").replace("ID ","");
        const an = Number(ar); const bn = Number(br);
        if (!Number.isNaN(an) && !Number.isNaN(bn) && ar.trim()!=="" && br.trim()!=="") return an - bn;
        return (a.dataset.roll||"").localeCompare(b.dataset.roll||"");
      });

      rows.forEach(r => tbody.appendChild(r));
    });
  }

  function saveConfirm(){
    const form = document.getElementById("attForm");
    if (!form) return;

    const dateEl = form.querySelector('input[name="session_date"]');
    const slotEl = form.querySelector('select[name="slot_id"]');
    const subEl  = form.querySelector('select[name="subject_id"]');
    const teaEl  = form.querySelector('select[name="teacher_id"]');

    if (!dateEl.value || !slotEl.value || !subEl.value || !teaEl.value) {
      Swal.fire({icon:"warning",title:"Buuxi xogta!",text:"Date + Slot + Subject + Teacher waa required."});
      return;
    }

    Swal.fire({
      icon:"question",
      title:"Kaydin?",
      text:"Ma hubtaa inaad attendance-ka kaydiso?",
      showCancelButton:true,
      confirmButtonText:"Haa, kaydi",
      cancelButtonText:"Maya"
    }).then(r => {
      if (r.isConfirmed) form.submit();
    });
  }
</script>
</body>
</html>
