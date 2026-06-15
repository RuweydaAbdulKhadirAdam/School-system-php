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
   Delete session (POST)
   ========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $action = (string)($_POST["action"] ?? "");
  if ($action === "delete_session") {
    $sid = (int)($_POST["session_id"] ?? 0);
    if ($sid <= 0) {
      setFlash("error","Qalad!","Session ID ma saxna.");
      header("Location: attendance_sessions.php");
      exit;
    }

    $conn->begin_transaction();
    try {
      $st = $conn->prepare("DELETE FROM attendance_sessions WHERE session_id=?");
      $st->bind_param("i", $sid);
      $st->execute();
      $st->close();

      $act="DELETE_ATTENDANCE_SESSION";
      $details="session_id=$sid";
      $entity="attendance_sessions";
      $st = $conn->prepare("INSERT INTO activity_logs(user_id, action, entity, entity_id, details) VALUES(?,?,?,?,?)");
      $st->bind_param("issis",$userId,$act,$entity,$sid,$details);
      $st->execute();
      $st->close();

      $conn->commit();
      setFlash("success","Waa la tirtiray!","Attendance session waa la saaray.");
    } catch (Throwable $e) {
      $conn->rollback();
      setFlash("error","Qalad!","Cilad: ".$e->getMessage());
    }

    header("Location: attendance_sessions.php");
    exit;
  }

  setFlash("warning","Info","Action lama aqoonsan.");
  header("Location: attendance_sessions.php");
  exit;
}

/* =========================
   Filters
   ========================= */
$currentYear = null;
$rs = $conn->query("SELECT year_id, year_name FROM academic_years WHERE is_current=1 LIMIT 1");
$currentYear = $rs->fetch_assoc();

$year_id    = (int)($_GET["year_id"] ?? ($currentYear["year_id"] ?? 0));
$section_id = (int)($_GET["section_id"] ?? 0);
$teacher_id = (int)($_GET["teacher_id"] ?? 0);
$date_from  = (string)($_GET["date_from"] ?? "");
$date_to    = (string)($_GET["date_to"] ?? "");

$years = [];
$rs = $conn->query("SELECT year_id, year_name, is_current FROM academic_years ORDER BY start_date DESC");
while ($r = $rs->fetch_assoc()) $years[] = $r;

$sections = [];
$rs = $conn->query("
  SELECT sec.section_id, CONCAT(g.grade_name,' - ', sec.section_name) AS class_name
  FROM sections sec
  JOIN grades g ON g.grade_id = sec.grade_id
  ORDER BY g.sort_order ASC, sec.section_name ASC
");
while ($r = $rs->fetch_assoc()) $sections[] = $r;

$teachers = [];
$rs = $conn->query("
  SELECT t.teacher_id, e.full_name
  FROM teachers t
  JOIN employees e ON e.employee_id = t.employee_id
  ORDER BY e.full_name ASC
");
while ($r = $rs->fetch_assoc()) $teachers[] = $r;

/* =========================
   Fetch sessions with summary
   ========================= */
$where = "WHERE 1=1 ";
$params = [];
$types  = "";

if ($year_id > 0) { $where .= " AND s.year_id=? "; $types.="i"; $params[]=$year_id; }
if ($section_id > 0) { $where .= " AND s.section_id=? "; $types.="i"; $params[]=$section_id; }
if ($teacher_id > 0) { $where .= " AND s.teacher_id=? "; $types.="i"; $params[]=$teacher_id; }
if ($date_from !== "") { $where .= " AND s.session_date>=? "; $types.="s"; $params[]=$date_from; }
if ($date_to !== "") { $where .= " AND s.session_date<=? "; $types.="s"; $params[]=$date_to; }

$sql = "
  SELECT
    s.session_id, s.session_date, s.status,
    ay.year_name,
    CONCAT(g.grade_name,' - ', sec.section_name) AS class_name,
    ts.slot_no, ts.start_time, ts.end_time,
    sub.subject_name,
    emp.full_name AS teacher_name,

    (SELECT COUNT(*) FROM attendance_records ar WHERE ar.session_id=s.session_id) AS total_marked,
    (SELECT COUNT(*) FROM attendance_records ar WHERE ar.session_id=s.session_id AND ar.status='P') AS present_count,
    (SELECT COUNT(*) FROM attendance_records ar WHERE ar.session_id=s.session_id AND ar.status='A') AS absent_count,
    (SELECT COUNT(*) FROM attendance_records ar WHERE ar.session_id=s.session_id AND ar.status='L') AS late_count

  FROM attendance_sessions s
  JOIN academic_years ay ON ay.year_id = s.year_id
  JOIN sections sec ON sec.section_id = s.section_id
  JOIN grades g ON g.grade_id = sec.grade_id
  JOIN time_slots ts ON ts.slot_id = s.slot_id
  JOIN subjects sub ON sub.subject_id = s.subject_id
  JOIN teachers t ON t.teacher_id = s.teacher_id
  JOIN employees emp ON emp.employee_id = t.employee_id
  $where
  ORDER BY s.session_date DESC, ts.slot_no ASC, s.session_id DESC
";

$sessions = [];
$st = $conn->prepare($sql);
if ($types !== "") $st->bind_param($types, ...$params);
$st->execute();
$res = $st->get_result();
while ($r = $res->fetch_assoc()) $sessions[] = $r;
$st->close();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Attendance Sessions</title>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    :root{
      --bg:#f6f7fb;--card:#fff;--ink:#0f172a;--muted:#6b7280;
      --primary:#4f46e5;--stroke:#e9ecff;--shadow:0 18px 44px rgba(15,23,42,.10);--radius:18px;
      --good:#16a34a;--bad:#ef4444;--warn:#f59e0b;
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
    .filters{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
    @media (max-width:1000px){.filters{grid-template-columns:1fr}}
    .field{border:2px solid rgba(79,70,229,.28);border-radius:999px;padding:12px 14px;background:#fff}
    .field label{display:block;font-size:12px;color:var(--muted);font-weight:950;margin-bottom:6px}
    select,input{width:100%;border:none;outline:none;background:transparent;font-size:15px}
    .hint{margin-top:10px;color:var(--muted);font-weight:850;font-size:13px;line-height:1.35}
    .badge{display:inline-block;padding:6px 10px;border:1px solid #e5e7eb;border-radius:999px;background:#fff;font-weight:950;color:#374151}
    .gridList{display:grid;grid-template-columns:repeat(2, 1fr);gap:12px;margin-top:14px}
    @media (max-width:950px){.gridList{grid-template-columns:1fr}}
    .sessCard{
      border:1px solid var(--stroke);border-radius:18px;background:#fff;padding:14px;
      transition:.15s; box-shadow:0 6px 18px rgba(15,23,42,.05);
      display:flex; gap:14px; align-items:stretch;
    }
    .sessCard:hover{transform:translateY(-2px);box-shadow:0 18px 36px rgba(79,70,229,.10)}
    .left{
      min-width:130px;border-radius:16px;padding:12px;background:#f4f6ff;border:1px solid #e8ebff;
      display:flex;flex-direction:column;justify-content:center;align-items:flex-start;
    }
    .date{font-weight:1100;font-size:16px}
    .year{margin-top:2px;color:var(--muted);font-weight:950;font-size:12px}
    .mid{flex:1}
    .line1{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
    .className{font-weight:1100;font-size:16px}
    .chip{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;border:1px solid #e5e7eb;background:#fff;font-weight:950;font-size:12px;color:#374151}
    .chip.blue{border-color:rgba(79,70,229,.25);background:rgba(79,70,229,.06);color:var(--primary)}
    .chip.green{border-color:rgba(22,163,74,.22);background:rgba(22,163,74,.08);color:var(--good)}
    .chip.red{border-color:rgba(239,68,68,.22);background:rgba(239,68,68,.08);color:var(--bad)}
    .chip.yellow{border-color:rgba(245,158,11,.22);background:rgba(245,158,11,.10);color:#92400e}
    .meta{margin-top:8px;color:var(--muted);font-weight:900;font-size:13px;line-height:1.4}
    .right{display:flex;flex-direction:column;gap:10px;justify-content:center}
    .miniBtn{border:1px solid #e5e7eb;background:#fff;border-radius:12px;padding:10px 12px;font-weight:1100;cursor:pointer;white-space:nowrap}
    .miniBtn.red{border-color:rgba(239,68,68,.25);background:rgba(239,68,68,.08);color:#b91c1c}
    .miniBtn.blue{border-color:rgba(79,70,229,.25);background:rgba(79,70,229,.06);color:#4f46e5}
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
    <b style="font-size:16px;">📚 Attendance Sessions</b>
    <span class="badge"><?= h($role) ?></span>

    <div class="actions">
      <a class="btn green" href="attendance_admin.php">➕ New Attendance</a>
      <button class="btn" onclick="window.print()">🖨 Print</button>
      <a class="btn blue" href="dashboard.php">🏠 Dashboard</a>
    </div>
  </div>

  <div class="card noPrint">
    <form method="get" action="attendance_sessions.php">
      <div class="filters">
        <div class="field">
          <label>Academic Year</label>
          <select name="year_id">
            <option value="">All Years</option>
            <?php foreach ($years as $y): ?>
              <option value="<?= (int)$y["year_id"] ?>" <?= ((int)$y["year_id"]===$year_id)?"selected":"" ?>>
                <?= h($y["year_name"]) ?><?= ((int)$y["is_current"]===1)?" (Current)":"" ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label>Class</label>
          <select name="section_id">
            <option value="">All Classes</option>
            <?php foreach ($sections as $s): ?>
              <option value="<?= (int)$s["section_id"] ?>" <?= ((int)$s["section_id"]===$section_id)?"selected":"" ?>>
                <?= h($s["class_name"]) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label>Teacher</label>
          <select name="teacher_id">
            <option value="">All Teachers</option>
            <?php foreach ($teachers as $t): ?>
              <option value="<?= (int)$t["teacher_id"] ?>" <?= ((int)$t["teacher_id"]===$teacher_id)?"selected":"" ?>>
                <?= h($t["full_name"]) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label>Date From</label>
          <input type="date" name="date_from" value="<?= h($date_from) ?>">
        </div>

        <div class="field">
          <label>Date To</label>
          <input type="date" name="date_to" value="<?= h($date_to) ?>">
        </div>

        <div class="field">
          <label>Live Search</label>
          <input type="text" id="liveSearch" placeholder="Raadi class/teacher/subject/date/slot..." value="">
        </div>
      </div>

      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;">
        <button class="btn blue" type="submit">🔎 Filter</button>
        <a class="btn" href="attendance_sessions.php">Reset</a>
      </div>

      <div class="hint">
        ✅ “View/Edit” wuxuu kuu furayaa <b>attendance_take.php</b> (page gooni ah) si aad u aragto/edito dot attendance.
      </div>
    </form>
  </div>

  <div class="card">
    <b style="font-size:16px;">📌 Sessions List</b>
    <div class="hint">Waxaad halkan ka arkeysaa sessions-ka oo si cad loo kala dhigey (cards). Click View/Edit si sax ah.</div>

    <?php if (count($sessions) === 0): ?>
      <div style="margin-top:12px;" class="empty">No sessions found.</div>
    <?php else: ?>
      <div class="gridList" id="sessGrid">
        <?php foreach ($sessions as $s): ?>
          <?php
            $timeLabel = substr((string)$s["start_time"],0,5)." - ".substr((string)$s["end_time"],0,5);
            $searchBlob = strtolower(
              $s["session_date"]." ".$s["year_name"]." ".$s["class_name"]." ".$s["subject_name"]." ".$s["teacher_name"]." slot ".$s["slot_no"]
            );
          ?>
          <div class="sessCard sessRow" data-search="<?= h($searchBlob) ?>">
            <div class="left">
              <div class="date">📅 <?= h($s["session_date"]) ?></div>
              <div class="year"><?= h($s["year_name"]) ?></div>
            </div>

            <div class="mid">
              <div class="line1">
                <div class="className">🏫 <?= h($s["class_name"]) ?></div>
                <span class="chip blue">📘 <?= h($s["subject_name"]) ?></span>
                <span class="chip">⏱ Slot <?= (int)$s["slot_no"] ?> (<?= h($timeLabel) ?>)</span>
              </div>

              <div class="meta">
                👨‍🏫 <b><?= h($s["teacher_name"]) ?></b>
                • Status: <b><?= h($s["status"]) ?></b>
                • Marked: <b><?= (int)$s["total_marked"] ?></b>
              </div>

              <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
                <span class="chip green">✅ P: <?= (int)$s["present_count"] ?></span>
                <span class="chip red">❌ A: <?= (int)$s["absent_count"] ?></span>
                <span class="chip yellow">⏰ L: <?= (int)$s["late_count"] ?></span>
              </div>

              <div class="meta" style="margin-top:10px;">
                Session ID: <b>#<?= (int)$s["session_id"] ?></b>
              </div>
            </div>

            <div class="right">
              <!-- ✅ IMPORTANT: View/Edit goes to attendance_take.php -->
              <a class="miniBtn blue" href="attendance_take.php?session_id=<?= (int)$s["session_id"] ?>">👁 View/Edit</a>

              <button class="miniBtn red" type="button"
                      onclick="deleteSession(<?= (int)$s['session_id'] ?>,'<?= h($s['session_date']) ?>','<?= h($s['class_name']) ?>')">
                🗑 Delete
              </button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
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

  // Live search cards
  const live = document.getElementById("liveSearch");
  if (live) {
    live.addEventListener("input", () => {
      const q = live.value.trim().toLowerCase();
      document.querySelectorAll(".sessRow").forEach(r => {
        const blob = (r.getAttribute("data-search") || "").toLowerCase();
        r.style.display = blob.includes(q) ? "" : "none";
      });
    });
  }

  function deleteSession(id, date, cls){
    Swal.fire({
      icon:"warning",
      title:"Delete?",
      text:`Ma tirtireysaa session: ${date} • ${cls} ?`,
      showCancelButton:true,
      confirmButtonText:"Haa, tirtir",
      cancelButtonText:"Maya"
    }).then(r => {
      if (!r.isConfirmed) return;

      const form = document.createElement("form");
      form.method = "post";
      form.action = "attendance_sessions.php";
      form.innerHTML = `
        <input type="hidden" name="action" value="delete_session">
        <input type="hidden" name="session_id" value="${id}">
      `;
      document.body.appendChild(form);
      form.submit();
    });
  }
</script>
</body>
</html>
