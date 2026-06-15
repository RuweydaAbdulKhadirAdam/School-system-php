<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . "/conncation.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* =========================
   ADMIN GUARD
   ========================= */
if (!isset($_SESSION["user_id"])) { header("Location: login.php"); exit; }
if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "ADMIN") { header("Location: login.php"); exit; }

$userId = (int)($_SESSION["user_id"] ?? 0);

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
   CURRENT YEAR (default)
   ========================= */
$currentYear = null;
$rs = $conn->query("SELECT year_id, year_name FROM academic_years WHERE is_current=1 LIMIT 1");
$currentYear = $rs->fetch_assoc();

$year_id    = (int)($_GET["year_id"] ?? ($currentYear["year_id"] ?? 0));
$section_id = (int)($_GET["section_id"] ?? 0);

/* =========================
   LISTS
   ========================= */
$years = [];
$rs = $conn->query("SELECT year_id, year_name, is_current FROM academic_years ORDER BY start_date DESC");
while ($r = $rs->fetch_assoc()) $years[] = $r;

$sections = [];
$rs = $conn->query("
  SELECT s.section_id, CONCAT(g.grade_name,' - ', s.section_name) AS display_name
  FROM sections s
  JOIN grades g ON g.grade_id = s.grade_id
  ORDER BY g.sort_order ASC, s.section_name ASC
");
while ($r = $rs->fetch_assoc()) $sections[] = $r;

$days = [];
$rs = $conn->query("SELECT day_id, day_name, sort_order FROM week_days ORDER BY sort_order ASC");
while ($r = $rs->fetch_assoc()) $days[] = $r;

$slots = [];
$rs = $conn->query("SELECT slot_id, slot_no, start_time, end_time, is_break FROM time_slots ORDER BY slot_no ASC, start_time ASC");
while ($r = $rs->fetch_assoc()) $slots[] = $r;

$rooms = [];
$rs = $conn->query("SELECT room_id, room_name FROM rooms ORDER BY room_name ASC");
while ($r = $rs->fetch_assoc()) $rooms[] = $r;

$teachers = [];
$rs = $conn->query("
  SELECT t.teacher_id, e.full_name
  FROM teachers t
  JOIN employees e ON e.employee_id = t.employee_id
  ORDER BY e.full_name ASC
");
while ($r = $rs->fetch_assoc()) $teachers[] = $r;

/* =========================
   SUBJECTS FOR SELECTED CLASS (section_subjects)
   ========================= */
$sectionSubjects = [];
if ($section_id > 0) {
  $st = $conn->prepare("
    SELECT sub.subject_id, sub.subject_name
    FROM section_subjects ss
    JOIN subjects sub ON sub.subject_id = ss.subject_id
    WHERE ss.section_id = ?
    ORDER BY sub.subject_name ASC
  ");
  $st->bind_param("i", $section_id);
  $st->execute();
  $res = $st->get_result();
  while ($r = $res->fetch_assoc()) $sectionSubjects[] = $r;
  $st->close();
}

/* =========================
   Ensure timetable header exists
   ========================= */
function ensureTimetable(mysqli $conn, int $year_id, int $section_id, int $userId): int {
  $st = $conn->prepare("SELECT timetable_id FROM timetables WHERE year_id=? AND section_id=? LIMIT 1");
  $st->bind_param("ii", $year_id, $section_id);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();

  if ($row) return (int)$row["timetable_id"];

  $name = "Timetable";
  $st = $conn->prepare("INSERT INTO timetables(year_id, section_id, name) VALUES(?,?,?)");
  $st->bind_param("iis", $year_id, $section_id, $name);
  $st->execute();
  $ttid = (int)$conn->insert_id;
  $st->close();

  // activity log
  $action = "CREATE_TIMETABLE";
  $entity = "timetables";
  $details = "year_id=$year_id, section_id=$section_id";
  $st = $conn->prepare("INSERT INTO activity_logs(user_id, action, entity, entity_id, details) VALUES(?,?,?,?,?)");
  $st->bind_param("issis", $userId, $action, $entity, $ttid, $details);
  $st->execute();
  $st->close();

  return $ttid;
}

$timetable_id = 0;
if ($year_id > 0 && $section_id > 0) {
  $timetable_id = ensureTimetable($conn, $year_id, $section_id, $userId);
}

/* =========================
   Helpers for generation
   ========================= */
function cleanIntArray($arr): array {
  if (!is_array($arr)) return [];
  $out = [];
  foreach ($arr as $v) {
    $i = (int)$v;
    if ($i > 0) $out[] = $i;
  }
  $out = array_values(array_unique($out));
  return $out;
}

/* =========================
   POST ACTIONS
   ========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $action = (string)($_POST["action"] ?? "");
  $year_id_p = (int)($_POST["year_id"] ?? 0);
  $section_id_p = (int)($_POST["section_id"] ?? 0);

  if ($year_id_p <= 0 || $section_id_p <= 0) {
    setFlash("error","Qalad!","Dooro Academic Year iyo Class.");
    header("Location: timetable.php");
    exit;
  }

  $ttid = ensureTimetable($conn, $year_id_p, $section_id_p, $userId);

  /* =========
     1) SAVE CELL (manual edit)
     ========= */
  if ($action === "save_entry") {
    $day_id     = (int)($_POST["day_id"] ?? 0);
    $slot_id    = (int)($_POST["slot_id"] ?? 0);
    $subject_id = (int)($_POST["subject_id"] ?? 0);
    $teacher_id = (int)($_POST["teacher_id"] ?? 0);
    $room_id    = (isset($_POST["room_id"]) && $_POST["room_id"] !== "") ? (int)$_POST["room_id"] : null;

    if ($day_id<=0 || $slot_id<=0 || $subject_id<=0 || $teacher_id<=0) {
      setFlash("error","Qalad!","Buuxi day/slot/subject/teacher.");
      header("Location: timetable.php?year_id=$year_id_p&section_id=$section_id_p");
      exit;
    }

    // prevent break slot
    $st = $conn->prepare("SELECT is_break FROM time_slots WHERE slot_id=? LIMIT 1");
    $st->bind_param("i",$slot_id);
    $st->execute();
    $slotRow = $st->get_result()->fetch_assoc();
    $st->close();
    if ($slotRow && (int)$slotRow["is_break"]===1) {
      setFlash("warning","Digniin!","BREAK slot lama buuxin karo.");
      header("Location: timetable.php?year_id=$year_id_p&section_id=$section_id_p");
      exit;
    }

    // subject must belong to section
    $st = $conn->prepare("SELECT 1 FROM section_subjects WHERE section_id=? AND subject_id=? LIMIT 1");
    $st->bind_param("ii",$section_id_p,$subject_id);
    $st->execute();
    $ok = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$ok) {
      setFlash("error","Qalad!","Maadadan class-kan looma xirin (subjects.php hubi).");
      header("Location: timetable.php?year_id=$year_id_p&section_id=$section_id_p");
      exit;
    }

    $conn->begin_transaction();
    try {
      $st = $conn->prepare("SELECT entry_id FROM timetable_entries WHERE timetable_id=? AND day_id=? AND slot_id=? LIMIT 1");
      $st->bind_param("iii",$ttid,$day_id,$slot_id);
      $st->execute();
      $existing = $st->get_result()->fetch_assoc();
      $st->close();

      if ($existing) {
        $entry_id = (int)$existing["entry_id"];
        if ($room_id === null) {
          $st = $conn->prepare("UPDATE timetable_entries SET subject_id=?, teacher_id=?, room_id=NULL WHERE entry_id=?");
          $st->bind_param("iii",$subject_id,$teacher_id,$entry_id);
        } else {
          $st = $conn->prepare("UPDATE timetable_entries SET subject_id=?, teacher_id=?, room_id=? WHERE entry_id=?");
          $st->bind_param("iiii",$subject_id,$teacher_id,$room_id,$entry_id);
        }
        $st->execute(); $st->close();
        $logAction="UPDATE_TIMETABLE_ENTRY";
        $eid=$entry_id;
      } else {
        if ($room_id === null) {
          $st = $conn->prepare("INSERT INTO timetable_entries(timetable_id,day_id,slot_id,subject_id,teacher_id,room_id) VALUES(?,?,?,?,?,NULL)");
          $st->bind_param("iiiii",$ttid,$day_id,$slot_id,$subject_id,$teacher_id);
        } else {
          $st = $conn->prepare("INSERT INTO timetable_entries(timetable_id,day_id,slot_id,subject_id,teacher_id,room_id) VALUES(?,?,?,?,?,?)");
          $st->bind_param("iiiiii",$ttid,$day_id,$slot_id,$subject_id,$teacher_id,$room_id);
        }
        $st->execute(); $eid=(int)$conn->insert_id; $st->close();
        $logAction="CREATE_TIMETABLE_ENTRY";
      }

      $details="entry_id=$eid, day_id=$day_id, slot_id=$slot_id, subject_id=$subject_id, teacher_id=$teacher_id";
      $entity="timetable_entries";
      $st = $conn->prepare("INSERT INTO activity_logs(user_id, action, entity, entity_id, details) VALUES(?,?,?,?,?)");
      $st->bind_param("issis",$userId,$logAction,$entity,$eid,$details);
      $st->execute(); $st->close();

      $conn->commit();
      setFlash("success","Waa guul!","Xisadda waa la kaydiyey.");
    } catch (Throwable $e) {
      $conn->rollback();
      setFlash("error","Qalad!","Cilad: ".$e->getMessage());
    }

    header("Location: timetable.php?year_id=$year_id_p&section_id=$section_id_p");
    exit;
  }

  /* =========
     2) DELETE CELL
     ========= */
  if ($action === "delete_entry") {
    $entry_id = (int)($_POST["entry_id"] ?? 0);
    if ($entry_id <= 0) {
      setFlash("error","Qalad!","Entry ID ma saxna.");
      header("Location: timetable.php?year_id=$year_id_p&section_id=$section_id_p"); exit;
    }

    $st = $conn->prepare("DELETE FROM timetable_entries WHERE entry_id=?");
    $st->bind_param("i",$entry_id);
    $st->execute(); $st->close();

    $actionLog="DELETE_TIMETABLE_ENTRY";
    $details="entry_id=$entry_id";
    $entity="timetable_entries";
    $st = $conn->prepare("INSERT INTO activity_logs(user_id, action, entity, entity_id, details) VALUES(?,?,?,?,?)");
    $st->bind_param("issis",$userId,$actionLog,$entity,$entry_id,$details);
    $st->execute(); $st->close();

    setFlash("success","Waa la tirtiray!","Xisaddii waa la saaray.");
    header("Location: timetable.php?year_id=$year_id_p&section_id=$section_id_p");
    exit;
  }

  /* =========
     3) GENERATE FOR CLASS (Auto fill with BREAK respected)
     ========= */
  if ($action === "generate_for_class") {

    // Subjects chosen
    $subjectIds = cleanIntArray($_POST["subject_ids"] ?? []);
    if (count($subjectIds) === 0) {
      setFlash("warning","Digniin!","Dooro ugu yaraan 1 subject si loo generate-gareeyo.");
      header("Location: timetable.php?year_id=$year_id_p&section_id=$section_id_p"); exit;
    }

    // Days chosen (if empty => use all)
    $selectedDayIds = cleanIntArray($_POST["day_ids"] ?? []);
    if (count($selectedDayIds) === 0) {
      // default all days
      foreach ($days as $d) $selectedDayIds[] = (int)$d["day_id"];
    }

    // Lessons per day
    $lessonsPerDay = (int)($_POST["lessons_per_day"] ?? 0);
    if ($lessonsPerDay <= 0) $lessonsPerDay = 6;

    // validate subjects belong to section
    $in = implode(",", array_fill(0, count($subjectIds), "?"));
    $types = str_repeat("i", count($subjectIds) + 1);
    $params = array_merge([$section_id_p], $subjectIds);

    $sql = "SELECT subject_id FROM section_subjects WHERE section_id=? AND subject_id IN ($in)";
    $st = $conn->prepare($sql);
    $st->bind_param($types, ...$params);
    $st->execute();
    $res = $st->get_result();
    $allowed = [];
    while ($r = $res->fetch_assoc()) $allowed[] = (int)$r["subject_id"];
    $st->close();

    sort($allowed);
    $copy = $subjectIds; sort($copy);
    if ($allowed !== $copy) {
      setFlash("error","Qalad!","Qaar ka mid ah subjects-ka aad dooratay class-kan looma xirin (subjects.php hubi).");
      header("Location: timetable.php?year_id=$year_id_p&section_id=$section_id_p"); exit;
    }

    // Default teacher
    $defaultTeacherId = (int)($_POST["default_teacher_id"] ?? 0);
    if ($defaultTeacherId <= 0 && count($teachers) > 0) {
      $defaultTeacherId = (int)$teachers[0]["teacher_id"];
    }
    if ($defaultTeacherId <= 0) {
      setFlash("error","Qalad!","Teacher lama helin. Marka hore teachers ku dar.");
      header("Location: timetable.php?year_id=$year_id_p&section_id=$section_id_p"); exit;
    }

    // Optional shuffle
    $doShuffle = (int)($_POST["shuffle_subjects"] ?? 0) === 1;
    if ($doShuffle) {
      shuffle($subjectIds);
    }

    // Build usable slots (non-break) in order
    $usableSlotIds = [];
    foreach ($slots as $sl) {
      if ((int)$sl["is_break"] === 1) continue;
      $usableSlotIds[] = (int)$sl["slot_id"];
    }
    if (count($usableSlotIds) === 0) {
      setFlash("error","Qalad!","Time slots lama helin. Marka hore time_slots buuxi.");
      header("Location: timetable.php?year_id=$year_id_p&section_id=$section_id_p"); exit;
    }

    // Clamp lessons per day to available non-break slots
    if ($lessonsPerDay > count($usableSlotIds)) $lessonsPerDay = count($usableSlotIds);

    $conn->begin_transaction();
    try {
      // wipe existing entries (regenerate clean)
      $st = $conn->prepare("DELETE FROM timetable_entries WHERE timetable_id=?");
      $st->bind_param("i",$ttid);
      $st->execute(); $st->close();

      // Fill per selected day: only first N usable slots of that day
      $i = 0;
      foreach ($selectedDayIds as $dayId) {
        for ($k = 0; $k < $lessonsPerDay; $k++) {
          $slotId = $usableSlotIds[$k]; // respects break automatically (break slots never used)
          $sid = $subjectIds[$i % count($subjectIds)];

          $st = $conn->prepare("
            INSERT INTO timetable_entries(timetable_id, day_id, slot_id, subject_id, teacher_id, room_id)
            VALUES(?,?,?,?,?,NULL)
          ");
          $st->bind_param("iiiii", $ttid, $dayId, $slotId, $sid, $defaultTeacherId);
          $st->execute();
          $st->close();
          $i++;
        }
      }

      // log
      $actionLog="GENERATE_TIMETABLE_CLASS";
      $details="year_id=$year_id_p, section_id=$section_id_p, days=".implode(",",$selectedDayIds).", lessons_per_day=$lessonsPerDay, subjects_count=".count($subjectIds).", teacher_id=$defaultTeacherId, shuffle=".($doShuffle?1:0);
      $entity="timetables";
      $st = $conn->prepare("INSERT INTO activity_logs(user_id, action, entity, entity_id, details) VALUES(?,?,?,?,?)");
      $st->bind_param("issis",$userId,$actionLog,$entity,$ttid,$details);
      $st->execute(); $st->close();

      $conn->commit();
      setFlash("success","Waa la Generate-gareeyay!","Timetable-ka si automatic ah ayaa loo buuxiyey (BREAK waa la ixtiraamay).");
    } catch (Throwable $e) {
      $conn->rollback();
      setFlash("error","Qalad!","Generate fail: ".$e->getMessage());
    }

    header("Location: timetable.php?year_id=$year_id_p&section_id=$section_id_p");
    exit;
  }

  setFlash("warning","Info","Action lama aqoonsan.");
  header("Location: timetable.php?year_id=$year_id_p&section_id=$section_id_p");
  exit;
}

/* =========================
   LOAD GRID
   ========================= */
$grid = [];
if ($timetable_id > 0) {
  $st = $conn->prepare("
    SELECT
      te.entry_id, te.day_id, te.slot_id,
      te.subject_id, sub.subject_name,
      te.teacher_id, emp.full_name AS teacher_name,
      te.room_id, r.room_name
    FROM timetable_entries te
    JOIN subjects sub ON sub.subject_id = te.subject_id
    JOIN teachers t ON t.teacher_id = te.teacher_id
    JOIN employees emp ON emp.employee_id = t.employee_id
    LEFT JOIN rooms r ON r.room_id = te.room_id
    WHERE te.timetable_id = ?
  ");
  $st->bind_param("i",$timetable_id);
  $st->execute();
  $res = $st->get_result();
  while ($row = $res->fetch_assoc()) {
    $grid[(int)$row["day_id"]][(int)$row["slot_id"]] = $row;
  }
  $st->close();
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Timetable (Admin)</title>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    :root{--bg:#f6f7fb;--card:#fff;--ink:#0f172a;--muted:#6b7280;--primary:#4f46e5;--stroke:#e9ecff;--shadow:0 18px 44px rgba(15,23,42,.10);--radius:18px;}
    *{box-sizing:border-box}
    body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;background:var(--bg);color:var(--ink)}
    .wrap{max-width:1200px;margin:0 auto;padding:18px}
    .top{background:var(--card);border:1px solid #eef0ff;border-radius:16px;padding:14px 16px;display:flex;align-items:center;gap:12px;box-shadow:0 2px 10px rgba(0,0,0,.04)}
    .actions{margin-left:auto;display:flex;gap:10px;flex-wrap:wrap}
    .btn{text-decoration:none;border:1px solid #e5e7eb;background:#fff;padding:10px 14px;border-radius:12px;font-weight:900;cursor:pointer;display:inline-flex;align-items:center;gap:10px;color:#111827;transition:.15s}
    .btn:hover{transform:translateY(-1px);box-shadow:0 14px 24px rgba(0,0,0,.06)}
    .btn.blue{border-color:rgba(79,70,229,.25);background:rgba(79,70,229,.06);color:var(--primary)}
    .card{margin-top:14px;background:var(--card);border:1px solid #eef0ff;border-radius:var(--radius);box-shadow:var(--shadow);padding:16px}
    .filters{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    @media (max-width:800px){.filters{grid-template-columns:1fr}}
    .field{border:2px solid rgba(79,70,229,.28);border-radius:999px;padding:12px 14px;background:#fff}
    .field label{display:block;font-size:12px;color:var(--muted);font-weight:900;margin-bottom:6px}
    select,input{width:100%;border:none;outline:none;background:transparent;font-size:16px}
    .hint{margin-top:10px;color:var(--muted);font-weight:800;font-size:13px}
    .tableWrap{overflow:auto;margin-top:14px;border:1px solid var(--stroke);border-radius:16px}
    table{width:100%;border-collapse:separate;border-spacing:0;background:#fff}
    th,td{padding:12px;border-bottom:1px solid var(--stroke);border-right:1px solid var(--stroke);vertical-align:top}
    th{background:#f2f4ff;font-size:12px;text-transform:uppercase;letter-spacing:.7px;color:#374151}
    th:first-child,td:first-child{position:sticky;left:0;background:#f9faff;z-index:2}
    tr:last-child td{border-bottom:none}
    td:last-child,th:last-child{border-right:none}
    .slotMeta{font-size:12px;color:var(--muted);font-weight:900}
    .cell{min-height:76px;border-radius:14px;padding:10px;border:1px dashed rgba(79,70,229,.25);cursor:pointer;transition:.15s}
    .cell:hover{transform:translateY(-1px);box-shadow:0 16px 30px rgba(79,70,229,.12)}
    .cell .sub{font-weight:950;font-size:15px}
    .cell .teach{color:var(--muted);font-weight:800;font-size:13px;margin-top:4px}
    .cell .room{color:#0ea5e9;font-weight:900;font-size:12px;margin-top:2px}
    .empty{color:#9ca3af;font-weight:900}
    .break{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;font-weight:950;text-align:center;padding:14px;border-radius:14px}
    .grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    @media (max-width:900px){.grid2{grid-template-columns:1fr}}
    .chip{display:inline-block;padding:6px 10px;border-radius:999px;border:1px solid #e5e7eb;font-weight:900;margin:4px 6px 0 0;background:#fff}
    .mini{font-size:12px;color:#6b7280;font-weight:900;margin-top:6px}
  </style>
</head>
<body>
<div class="wrap">

  <div class="top">
    <b>📅 Timetable (Admin)</b>
    <div class="actions">
      <a class="btn" href="timetable_view.php">👁 View / Print</a>
      <a class="btn blue" href="subjects.php?view=classes">📚 Subjects</a>
    </div>
  </div>

  <div class="card">
    <form method="get" action="timetable.php">
      <div class="filters">
        <div class="field">
          <label>Academic Year</label>
          <select name="year_id" required>
            <option value="">Dooro Year</option>
            <?php foreach ($years as $y): ?>
              <option value="<?= (int)$y["year_id"] ?>" <?= ((int)$y["year_id"]===$year_id)?"selected":"" ?>>
                <?= h($y["year_name"]) ?><?= ((int)$y["is_current"]===1)?" (Current)":"" ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label>Class (Section)</label>
          <input id="classSearch" type="text" placeholder="Raadi class..." style="font-size:13px;margin:0 0 8px;color:#6b7280;font-weight:800;">
          <select name="section_id" id="sectionSelect" required>
            <option value="">Dooro Class</option>
            <?php foreach ($sections as $s): ?>
              <option value="<?= (int)$s["section_id"] ?>" <?= ((int)$s["section_id"]===$section_id)?"selected":"" ?>>
                <?= h($s["display_name"]) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;">
        <button class="btn blue" type="submit">🔎 Load</button>
        <?php if ($year_id>0 && $section_id>0): ?>
          <a class="btn" href="timetable_view.php?year_id=<?= $year_id ?>&section_id=<?= $section_id ?>">👁 View</a>
        <?php endif; ?>
      </div>

      <div class="hint">
        ✅ Door Year + Class → kadib cell kasta ku dhufo (Edit). Ama isticmaal <b>Generate For Class</b> si automatic u buuxiyo.
        <div class="mini">BREAK waxa go’aamiya time_slots.is_break=1 — break slots lama buuxinayo.</div>
      </div>
    </form>
  </div>

  <?php if ($year_id>0 && $section_id>0): ?>
    <div class="card">
      <div class="grid2">
        <div>
          <b style="font-size:16px;">⚡ Generate For Class</b>
          <div class="hint">
            Door subjects (6 ama 12) → dooro maalmaha → dooro Lessons/Day → Generate.
            BREAK si automatic ayuu uga dhex muuqanayaa (ma buuxsamo).
          </div>

          <?php if (count($sectionSubjects) === 0): ?>
            <div style="margin-top:10px;padding:12px;border-radius:14px;border:1px solid #fee2e2;background:#fff1f2;font-weight:900;color:#9f1239">
              Class-kan ma leh maadooyin. Tag
              <a href="subjects.php?view=assign&edit_section_id=<?= $section_id ?>" style="color:#4f46e5;font-weight:950;">subjects.php</a>
              oo ku xiro.
            </div>
          <?php else: ?>
            <form method="post" action="timetable.php" id="genForm" style="margin-top:10px;">
              <input type="hidden" name="action" value="generate_for_class">
              <input type="hidden" name="year_id" value="<?= (int)$year_id ?>">
              <input type="hidden" name="section_id" value="<?= (int)$section_id ?>">

              <div style="margin-top:10px;font-weight:900;color:#374151;">Maalmaha la buuxinayo</div>
              <div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:8px;">
                <?php foreach ($days as $d): ?>
                  <label class="chip">
                    <input type="checkbox" name="day_ids[]" value="<?= (int)$d["day_id"] ?>" checked style="transform:translateY(1px);">
                    <?= h($d["day_name"]) ?>
                  </label>
                <?php endforeach; ?>
              </div>

              <div style="margin-top:12px;font-weight:900;color:#374151;">Lessons per Day</div>
              <div style="margin-top:6px;border:2px solid rgba(79,70,229,.28);border-radius:999px;padding:10px 12px;">
                <input type="number" name="lessons_per_day" min="1" value="6">
              </div>
              <div class="mini">Tusaale: Sat 6 periods. Haddii non-break slots ay ka yar yihiin, system-ku wuu yareynayaa.</div>

              <div style="margin-top:12px;font-weight:900;color:#374151;">Default Teacher</div>
              <div style="margin-top:6px;border:2px solid rgba(79,70,229,.28);border-radius:999px;padding:10px 12px;">
                <select name="default_teacher_id">
                  <option value="">Auto (first teacher)</option>
                  <?php foreach ($teachers as $t): ?>
                    <option value="<?= (int)$t["teacher_id"] ?>"><?= h($t["full_name"]) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <label class="mini" style="display:flex;gap:8px;align-items:center;margin-top:10px;">
                <input type="checkbox" name="shuffle_subjects" value="1">
                Shuffle subjects (random order)
              </label>

              <div style="margin-top:12px;font-weight:900;color:#374151;">Subjects (Select multiple)</div>
              <div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:8px;">
                <?php foreach ($sectionSubjects as $sub): ?>
                  <label class="chip">
                    <input type="checkbox" name="subject_ids[]" value="<?= (int)$sub["subject_id"] ?>" style="transform:translateY(1px);">
                    <?= h($sub["subject_name"]) ?>
                  </label>
                <?php endforeach; ?>
              </div>

              <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;">
                <button type="button" class="btn" onclick="selectFirstN(6)">Select 6</button>
                <button type="button" class="btn" onclick="selectFirstN(12)">Select 12</button>
                <button type="button" class="btn" onclick="toggleAll(true)">Select All</button>
                <button type="button" class="btn" onclick="toggleAll(false)">Clear</button>
              </div>

              <div style="margin-top:12px;">
                <button class="btn blue" type="submit">⚡ Generate Now</button>
              </div>

              <div class="mini" style="margin-top:10px;">
                * Generate wuxuu tirtiraa entries-kii hore ee class-kan/year-kan kadibna dib buuxiyaa.
              </div>
            </form>
          <?php endif; ?>
        </div>

        <div>
          <b style="font-size:16px;">🧩 Edit Timetable (Manual)</b>
          <div class="hint">Cell ku dhufo → Subject + Teacher + Room (optional).</div>
          <div class="mini">Generate kadib waxaad manual u sax kartaa wixii teacher/room ah.</div>
        </div>
      </div>
    </div>

    <div class="card">
      <b style="font-size:16px;">🧩 Timetable Grid</b>
      <div class="tableWrap">
        <table>
          <thead>
            <tr>
              <th style="width:220px;">Time Slots</th>
              <?php foreach ($days as $d): ?>
                <th><?= h($d["day_name"]) ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($slots as $sl): ?>
              <?php
                $isBreak = ((int)$sl["is_break"] === 1);
                $slotLabel = "Slot ".$sl["slot_no"];
                $timeLabel = substr((string)$sl["start_time"],0,5)." - ".substr((string)$sl["end_time"],0,5);
              ?>
              <tr>
                <td>
                  <div style="font-weight:950;"><?= h($slotLabel) ?></div>
                  <div class="slotMeta"><?= h($timeLabel) ?><?= $isBreak ? " • BREAK" : "" ?></div>
                </td>

                <?php foreach ($days as $d): ?>
                  <?php
                    $day_id_i = (int)$d["day_id"];
                    $slot_id_i = (int)$sl["slot_id"];
                    $entry = $grid[$day_id_i][$slot_id_i] ?? null;
                  ?>
                  <td>
                    <?php if ($isBreak): ?>
                      <div class="break">BREAK</div>
                    <?php else: ?>
                      <div class="cell"
                        data-entry='<?= h(json_encode([
                          "entry_id"   => $entry["entry_id"] ?? 0,
                          "day_id"     => $day_id_i,
                          "slot_id"    => $slot_id_i,
                          "subject_id" => $entry["subject_id"] ?? 0,
                          "teacher_id" => $entry["teacher_id"] ?? 0,
                          "room_id"    => $entry["room_id"] ?? "",
                        ])) ?>'
                        data-title="<?= h($d["day_name"]." • ".$slotLabel." (".$timeLabel.")") ?>"
                      >
                        <?php if ($entry): ?>
                          <div class="sub"><?= h($entry["subject_name"]) ?></div>
                          <div class="teach">👨‍🏫 <?= h($entry["teacher_name"]) ?></div>
                          <?php if (!empty($entry["room_name"])): ?>
                            <div class="room">🏫 <?= h($entry["room_name"]) ?></div>
                          <?php endif; ?>
                        <?php else: ?>
                          <div class="empty">＋ Ku qor (Subject/Teacher)</div>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                  </td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

</div>

<script>
  // Flash SweetAlert
  <?php if ($alert): ?>
    Swal.fire({
      icon: <?= json_encode($alert["type"]) ?>,
      title: <?= json_encode($alert["title"]) ?>,
      text: <?= json_encode($alert["text"]) ?>,
      confirmButtonText: "Haye"
    });
  <?php endif; ?>

  // filter class select
  const classSearch = document.getElementById("classSearch");
  const sectionSelect = document.getElementById("sectionSelect");
  if (classSearch && sectionSelect) {
    const original = Array.from(sectionSelect.options).map(o => ({value:o.value, text:o.text, selected:o.selected}));
    classSearch.addEventListener("input", () => {
      const q = classSearch.value.trim().toLowerCase();
      sectionSelect.innerHTML = "";
      original.forEach(opt => {
        if (opt.value === "" || opt.text.toLowerCase().includes(q)) {
          const o = document.createElement("option");
          o.value = opt.value;
          o.textContent = opt.text;
          if (opt.selected) o.selected = true;
          sectionSelect.appendChild(o);
        }
      });
    });
  }

  // Generate helper: subjects
  function toggleAll(on){
    document.querySelectorAll('#genForm input[type="checkbox"][name="subject_ids[]"]').forEach(cb => cb.checked = !!on);
  }
  function selectFirstN(n){
    const cbs = Array.from(document.querySelectorAll('#genForm input[type="checkbox"][name="subject_ids[]"]'));
    cbs.forEach((cb, i) => cb.checked = i < n);
  }

  // Modal data
  const subjects = <?= json_encode($sectionSubjects, JSON_UNESCAPED_UNICODE) ?>;
  const teachers = <?= json_encode($teachers, JSON_UNESCAPED_UNICODE) ?>;
  const rooms    = <?= json_encode($rooms, JSON_UNESCAPED_UNICODE) ?>;

  function optionsHtml(list, valueKey, textKey, selectedVal) {
    let html = `<option value="">Dooro</option>`;
    list.forEach(x => {
      const v = String(x[valueKey]);
      const t = String(x[textKey]);
      const sel = (String(selectedVal) === v) ? "selected" : "";
      html += `<option value="${v}" ${sel}>${t}</option>`;
    });
    return html;
  }

  // click timetable cell => modal
  document.querySelectorAll(".cell").forEach(cell => {
    cell.addEventListener("click", async () => {
      const title = cell.getAttribute("data-title") || "Timetable";
      const data  = JSON.parse(cell.getAttribute("data-entry") || "{}");

      if (!subjects || subjects.length === 0) {
        Swal.fire({icon:"warning", title:"Digniin!", text:"Class-kan ma leh maadooyin (subjects.php ku xiro).", confirmButtonText:"Haye"});
        return;
      }

      const html = `
        <div style="text-align:left;display:grid;gap:10px;">
          <div>
            <label style="font-weight:900;color:#6b7280;">Subject</label>
            <select id="sw_subject" style="width:100%;padding:10px;border-radius:12px;border:1px solid #e5e7eb;">
              ${optionsHtml(subjects,"subject_id","subject_name",data.subject_id)}
            </select>
          </div>
          <div>
            <label style="font-weight:900;color:#6b7280;">Teacher</label>
            <select id="sw_teacher" style="width:100%;padding:10px;border-radius:12px;border:1px solid #e5e7eb;">
              ${optionsHtml(teachers,"teacher_id","full_name",data.teacher_id)}
            </select>
          </div>
          <div>
            <label style="font-weight:900;color:#6b7280;">Room (Optional)</label>
            <select id="sw_room" style="width:100%;padding:10px;border-radius:12px;border:1px solid #e5e7eb;">
              <option value="">None</option>
              ${rooms.map(r=>{
                const v=String(r.room_id), t=String(r.room_name);
                const sel=(String(data.room_id)===v)?"selected":"";
                return `<option value="${v}" ${sel}>${t}</option>`;
              }).join("")}
            </select>
          </div>
        </div>
      `;

      const res = await Swal.fire({
        title,
        html,
        showCancelButton:true,
        confirmButtonText:"Kaydi",
        cancelButtonText:"Jooji",
        showDenyButton:(Number(data.entry_id) > 0),
        denyButtonText:"Tirtir",
        focusConfirm:false,
        preConfirm: () => {
          const subject_id = (document.getElementById("sw_subject")||{}).value;
          const teacher_id = (document.getElementById("sw_teacher")||{}).value;
          const room_id    = (document.getElementById("sw_room")||{}).value;
          if (!subject_id || !teacher_id) {
            Swal.showValidationMessage("Dooro Subject iyo Teacher.");
            return false;
          }
          return {subject_id, teacher_id, room_id};
        }
      });

      if (res.isDenied) {
        const entry_id = Number(data.entry_id || 0);
        if (!entry_id) return;

        const ok = await Swal.fire({
          icon:"warning",title:"Ma hubtaa?",
          text:"Xisaddan ma tirtireysaa?",
          showCancelButton:true,
          confirmButtonText:"Haa, tirtir",
          cancelButtonText:"Maya"
        });
        if (!ok.isConfirmed) return;

        const form = document.createElement("form");
        form.method="post"; form.action="timetable.php";
        form.innerHTML = `
          <input type="hidden" name="action" value="delete_entry">
          <input type="hidden" name="year_id" value="<?= (int)$year_id ?>">
          <input type="hidden" name="section_id" value="<?= (int)$section_id ?>">
          <input type="hidden" name="entry_id" value="${entry_id}">
        `;
        document.body.appendChild(form);
        form.submit();
      }

      if (res.isConfirmed) {
        const form = document.createElement("form");
        form.method="post"; form.action="timetable.php";
        form.innerHTML = `
          <input type="hidden" name="action" value="save_entry">
          <input type="hidden" name="year_id" value="<?= (int)$year_id ?>">
          <input type="hidden" name="section_id" value="<?= (int)$section_id ?>">
          <input type="hidden" name="day_id" value="${data.day_id}">
          <input type="hidden" name="slot_id" value="${data.slot_id}">
          <input type="hidden" name="subject_id" value="${res.value.subject_id}">
          <input type="hidden" name="teacher_id" value="${res.value.teacher_id}">
          <input type="hidden" name="room_id" value="${res.value.room_id}">
        `;
        document.body.appendChild(form);
        form.submit();
      }
    });
  });
</script>
</body>
</html>
