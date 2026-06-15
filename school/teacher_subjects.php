<?php
declare(strict_types=1);
session_start();
require_once "conncation.php";

/* =====================
   ADMIN / RECEPTION GUARD
   ===================== */
if (!isset($_SESSION["user_id"]) || !in_array($_SESSION["role"], ["ADMIN","RECEPTION"], true)) {
  header("Location: login.php");
  exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* =====================
   HELPERS
   ===================== */
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8"); }
function cleanSpaces(string $v): string {
  $v = trim($v);
  $v = preg_replace('/\s+/', ' ', $v);
  return $v ?? "";
}
function setFlash(string $type, string $title, string $msg): void {
  $_SESSION["flash"] = ["type"=>$type, "title"=>$title, "msg"=>$msg];
}
$flash = $_SESSION["flash"] ?? null;
unset($_SESSION["flash"]);

/* =====================
   READ PARAMS
   ===================== */
$q = cleanSpaces($_GET["q"] ?? "");
$subjectFilter = cleanSpaces($_GET["subject"] ?? "");
$teacherId = (int)($_GET["teacher_id"] ?? $_POST["teacher_id"] ?? 0);
$viewMode  = (int)($_GET["view"] ?? 0); // 1=manage subjects page

/* =====================
   SUBJECT OPTIONS (for optional filter)
   ===================== */
$subjectOptions = [];
$subStmt = $conn->prepare("SELECT DISTINCT subject_name FROM teacher_subjects ORDER BY subject_name ASC");
$subStmt->execute();
$subRs = $subStmt->get_result();
while ($r = $subRs->fetch_assoc()) {
  $name = trim((string)$r["subject_name"]);
  if ($name !== "") $subjectOptions[] = $name;
}
$subStmt->close();

/* =====================
   FETCH TEACHER FULL
   ===================== */
function fetchTeacher(mysqli $conn, int $teacherId): ?array {
  $st = $conn->prepare("
    SELECT
      t.teacher_id,
      e.employee_id,
      u.user_id,
      e.full_name,
      e.phone,
      e.gender,
      e.photo_url,
      e.salary_amount,
      e.hired_date,
      e.status,
      u.username,
      u.email,
      t.specialization,
      t.qualification,
      (SELECT GROUP_CONCAT(ts.subject_name ORDER BY ts.subject_name SEPARATOR ', ')
         FROM teacher_subjects ts WHERE ts.teacher_id = t.teacher_id) AS subjects_csv
    FROM teachers t
    JOIN employees e ON e.employee_id = t.employee_id
    JOIN users u ON u.user_id = e.user_id
    WHERE t.teacher_id=?
    LIMIT 1
  ");
  $st->bind_param("i", $teacherId);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();
  return $row ?: null;
}

/* =====================
   MANAGE TEACHER (view=1)
   ===================== */
$selectedTeacher = null;
if ($teacherId > 0 && $viewMode === 1) {
  $selectedTeacher = fetchTeacher($conn, $teacherId);
  if (!$selectedTeacher) {
    setFlash("error", "Fariin", "Macallinka lama helin (Teacher ID: {$teacherId}).");
    header("Location: teacher_subjects.php");
    exit;
  }
}

/* =====================
   ADD SUBJECT (view=1)
   ===================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "add_subject") {
  $teacherId = (int)($_POST["teacher_id"] ?? 0);
  if ($teacherId <= 0) {
    setFlash("error", "Fariin", "Teacher ID ma saxna.");
    header("Location: teacher_subjects.php");
    exit;
  }

  $subjectName = cleanSpaces($_POST["subject_name"] ?? "");
  if ($subjectName === "") {
    setFlash("error", "Fariin", "Fadlan geli subject name.");
    header("Location: teacher_subjects.php?teacher_id=".$teacherId."&view=1");
    exit;
  }

  try {
    $ins = $conn->prepare("INSERT INTO teacher_subjects (teacher_id, subject_name) VALUES (?, ?)");
    $ins->bind_param("is", $teacherId, $subjectName);
    $ins->execute();
    $ins->close();
    setFlash("success", "Guul", "Subject waa lagu daray si guul ah.");
  } catch (Throwable $e) {
    $msg = $e->getMessage();
    if (stripos($msg, "Duplicate") !== false || stripos($msg, "1062") !== false) {
      $msg = "Subject-kan hore ayuu ugu jiraa macallinkan.";
    }
    setFlash("error", "Fariin", $msg);
  }

  header("Location: teacher_subjects.php?teacher_id=".$teacherId."&view=1");
  exit;
}

/* =====================
   DELETE SUBJECT (view=1)
   ===================== */
if (isset($_GET["del_subject"]) && $viewMode === 1) {
  $teacherId = (int)($_GET["teacher_id"] ?? 0);
  $sub = cleanSpaces($_GET["del_subject"] ?? "");

  if ($teacherId <= 0 || $sub === "") {
    setFlash("error", "Fariin", "Subject/Teacher ma saxna.");
    header("Location: teacher_subjects.php?teacher_id=".$teacherId."&view=1");
    exit;
  }

  try {
    $del = $conn->prepare("DELETE FROM teacher_subjects WHERE teacher_id=? AND subject_name=? LIMIT 1");
    $del->bind_param("is", $teacherId, $sub);
    $del->execute();
    $del->close();
    setFlash("success", "Guul", "Subject waa la tirtiray.");
  } catch (Throwable $e) {
    setFlash("error", "Fariin", $e->getMessage());
  }

  header("Location: teacher_subjects.php?teacher_id=".$teacherId."&view=1");
  exit;
}

/* =====================
   DELETE TEACHER (from cards)
   - deletes teachers row only
   - teacher_subjects will delete via FK cascade
   ===================== */
if (isset($_GET["del_teacher"]) && (int)$_GET["del_teacher"] > 0) {
  $tid = (int)$_GET["del_teacher"];
  try {
    $conn->begin_transaction();

    // ensure exists
    $chk = $conn->prepare("SELECT teacher_id FROM teachers WHERE teacher_id=? LIMIT 1");
    $chk->bind_param("i", $tid);
    $chk->execute();
    $ok = (bool)$chk->get_result()->fetch_assoc();
    $chk->close();
    if (!$ok) throw new Exception("Macallinka lama helin.");

    $del = $conn->prepare("DELETE FROM teachers WHERE teacher_id=? LIMIT 1");
    $del->bind_param("i", $tid);
    $del->execute();
    $del->close();

    $conn->commit();
    setFlash("success", "Guul", "Macallinka waa la tirtiray (subjects-kana waa raacay).");
  } catch (Throwable $e) {
    $conn->rollback();
    setFlash("error", "Fariin", $e->getMessage());
  }

  header("Location: teacher_subjects.php");
  exit;
}

/* =====================
   SELECTED SUBJECTS (view=1)
   ===================== */
$selectedSubjects = [];
if ($selectedTeacher) {
  $qs = $conn->prepare("SELECT subject_name FROM teacher_subjects WHERE teacher_id=? ORDER BY subject_name ASC");
  $qs->bind_param("i", $teacherId);
  $qs->execute();
  $r2 = $qs->get_result();
  while ($x = $r2->fetch_assoc()) $selectedSubjects[] = (string)$x["subject_name"];
  $qs->close();
}

/* =====================
   LOAD TEACHERS LIST (DEFAULT: ALL)
   - live search by q and subject filter
   ===================== */
$teachers = [];
$where = "1";
$types = "";
$params = [];

if ($q !== "") {
  $where .= " AND (
    e.full_name LIKE ?
    OR u.username LIKE ?
    OR e.phone LIKE ?
    OR CAST(t.teacher_id AS CHAR) LIKE ?
    OR COALESCE(t.specialization,'') LIKE ?
    OR COALESCE(t.qualification,'') LIKE ?
    OR EXISTS (SELECT 1 FROM teacher_subjects ts WHERE ts.teacher_id=t.teacher_id AND ts.subject_name LIKE ?)
  )";
  $types .= "sssssss";
  $like = "%".$q."%";
  $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}

if ($subjectFilter !== "") {
  $where .= " AND EXISTS (
    SELECT 1 FROM teacher_subjects ts2
    WHERE ts2.teacher_id=t.teacher_id AND ts2.subject_name = ?
  )";
  $types .= "s";
  $params[] = $subjectFilter;
}

$sql = "
  SELECT
    t.teacher_id,
    e.full_name,
    e.phone,
    e.gender,
    e.photo_url,
    e.status,
    u.username,
    u.email,
    t.specialization,
    t.qualification,
    (SELECT GROUP_CONCAT(ts.subject_name ORDER BY ts.subject_name SEPARATOR ', ')
       FROM teacher_subjects ts WHERE ts.teacher_id=t.teacher_id) AS subjects_csv
  FROM teachers t
  JOIN employees e ON e.employee_id=t.employee_id
  JOIN users u ON u.user_id=e.user_id
  WHERE $where
  ORDER BY e.full_name ASC
  LIMIT 300
";
$stmt = $conn->prepare($sql);
if ($types !== "") $stmt->bind_param($types, ...$params);
$stmt->execute();
$rs = $stmt->get_result();
while ($r = $rs->fetch_assoc()) $teachers[] = $r;
$stmt->close();

/* Placeholder avatar (no image) */
function avatarSvgDataUri(string $name): string {
  $initial = mb_strtoupper(mb_substr(trim($name), 0, 1));
  if ($initial === "") $initial = "T";
  $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="220" height="220">
    <defs>
      <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
        <stop offset="0" stop-color="#c7d2fe"/>
        <stop offset="1" stop-color="#60a5fa"/>
      </linearGradient>
    </defs>
    <rect width="100%" height="100%" rx="110" fill="url(#g)"/>
    <text x="50%" y="56%" text-anchor="middle" font-family="Arial" font-size="92" fill="#0f172a" font-weight="700">'.$initial.'</text>
  </svg>';
  return "data:image/svg+xml;base64,".base64_encode($svg);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Employees - Teachers</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="stylesheet" href="bootstrap.css" />
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <style>
    :root{
      --bg:#f4f7ff;
      --card:#fff;
      --text:#0f172a;
      --muted:#6b7280;
      --primary:#3b4cd6;
      --soft:#e9edff;
      --shadow: 0 10px 30px rgba(2,6,23,.10);
      --radius: 22px;
    }
    body{ background:var(--bg); color:var(--text); }
    .wrap{ max-width: 1280px; margin: 0 auto; }

    /* Top header (like screenshot) */
    .pageHead{
      background: var(--card);
      border: 1px solid rgba(2,6,23,.10);
      border-radius: 18px;
      padding: 18px 18px;
      box-shadow: var(--shadow);
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap: 16px;
    }
    .crumb{
      display:flex; align-items:center; gap: 12px;
      font-weight: 1000;
      font-size: 22px;
    }
    .crumb small{
      font-weight: 900;
      color: var(--muted);
      font-size: 14px;
    }
    .searchBox{
      display:flex;
      align-items:center;
      gap: 14px;
      flex-wrap:wrap;
      justify-content:flex-end;
      width: 520px;
      max-width: 100%;
    }
    .searchWrap{
      position: relative;
      width: 380px;
      max-width: 100%;
    }
    .searchWrap input{
      width:100%;
      padding: 14px 18px;
      border-radius: 999px;
      border: 2px solid rgba(59,76,214,.35);
      outline:none;
      background: #fff;
      font-weight: 900;
    }
    .searchTag{
      position:absolute;
      left: 18px;
      top: -10px;
      background: var(--primary);
      color:#fff;
      font-weight: 1000;
      font-size: 12px;
      padding: 2px 10px;
      border-radius: 999px;
    }
    .btnAll{
      border:none;
      border-radius: 999px;
      background: var(--primary);
      color:#fff;
      padding: 12px 20px;
      font-weight: 1000;
      min-width: 110px;
      box-shadow: 0 10px 20px rgba(59,76,214,.20);
    }

    /* optional subject filter */
    .filterRow{
      margin-top: 14px;
      display:flex;
      gap: 12px;
      flex-wrap:wrap;
      align-items:center;
    }
    .filterRow select{
      border-radius: 999px;
      border: 1px solid rgba(2,6,23,.12);
      padding: 10px 14px;
      font-weight: 900;
      background:#fff;
    }

    /* Cards grid */
    .grid{
      margin-top: 24px;
      display:grid;
      grid-template-columns: repeat(6, 1fr);
      gap: 22px;
      align-items:start;
    }
    @media (max-width: 1200px){ .grid{ grid-template-columns: repeat(4, 1fr); } }
    @media (max-width: 900px){ .grid{ grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 520px){ .grid{ grid-template-columns: 1fr; } }

    .cardEmp{
      background: var(--card);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      border: 1px solid rgba(2,6,23,.10);
      padding: 18px 16px 14px;
      text-align:center;
      min-height: 260px;
      position: relative;
    }
    .avatar{
      width: 120px;
      height: 120px;
      border-radius: 999px;
      margin: 4px auto 12px;
      overflow:hidden;
      background: #eef2ff;
      display:flex;
      align-items:center;
      justify-content:center;
    }
    .avatar img{
      width:100%;
      height:100%;
      object-fit: cover;
    }
    .empName{
      font-weight: 1000;
      font-size: 18px;
      margin-bottom: 2px;
      text-transform: lowercase;
    }
    .empRole{
      font-weight: 1000;
      color: var(--muted);
      margin-bottom: 14px;
    }

    /* Icon buttons (like screenshot circles) */
    .actions{
      display:flex;
      justify-content:center;
      gap: 12px;
      margin-top: 10px;
    }
    .icBtn{
      width: 42px;
      height: 42px;
      border-radius: 999px;
      border: none;
      display:flex;
      align-items:center;
      justify-content:center;
      cursor:pointer;
      box-shadow: 0 10px 20px rgba(2,6,23,.10);
      transition: transform .12s ease;
    }
    .icBtn:hover{ transform: translateY(-1px); }
    .icView{ background:#c7d2fe; }
    .icEdit{ background:#60a5fa; }
    .icDel{ background:#fb7185; }

    .icBtn svg{ width: 18px; height: 18px; }

    /* Add New tile */
    .cardAdd{
      background: transparent;
      border-radius: var(--radius);
      border: 3px dotted rgba(59,76,214,.65);
      box-shadow: none;
      min-height: 260px;
      display:flex;
      align-items:center;
      justify-content:center;
      cursor:pointer;
      transition: transform .12s ease;
    }
    .cardAdd:hover{ transform: translateY(-2px); }
    .addInner{
      text-align:center;
      font-weight: 1000;
      color: var(--primary);
      font-size: 22px;
    }
    .addInner .plus{
      width: 58px; height: 58px;
      border-radius: 999px;
      background: rgba(59,76,214,.12);
      display:flex;
      align-items:center;
      justify-content:center;
      margin: 0 auto 10px;
      font-size: 34px;
      line-height: 1;
    }

    /* Modal */
    .modalx{
      display:none;
      position:fixed;
      inset:0;
      background: rgba(0,0,0,.55);
      z-index: 9999;
      padding: 22px;
      overflow:auto;
    }
    .modalx.show{ display:block; }

    .modalCard{
      max-width: 740px;
      margin: 0 auto;
      background:#fff;
      border-radius: 18px;
      box-shadow: var(--shadow);
      border: 1px solid rgba(2,6,23,.12);
      overflow:hidden;
    }
    .modalTop{
      padding: 12px 14px;
      display:flex;
      justify-content:space-between;
      align-items:center;
      border-bottom: 1px solid rgba(2,6,23,.08);
      position: sticky;
      top: 0;
      background:#fff;
      z-index: 10000;
    }
    .modalTop .ttl{ font-weight:1000; }
    .modalBody{ padding: 18px; }

    .infoGrid{
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
      margin-top: 12px;
    }
    @media(max-width:700px){ .infoGrid{ grid-template-columns: 1fr; } }
    .infoItem{
      background:#f7f8ff;
      border: 1px solid rgba(2,6,23,.08);
      border-radius: 14px;
      padding: 10px 12px;
    }
    .k{ font-size: 12px; font-weight: 1000; color: var(--muted); }
    .v{ font-size: 15px; font-weight: 1000; }
    .pillSub{
      display:inline-flex;
      align-items:center;
      gap: 8px;
      background:#eef2ff;
      border: 1px solid rgba(59,76,214,.18);
      padding: 6px 10px;
      border-radius: 999px;
      font-weight: 900;
      margin: 6px 6px 0 0;
    }
  </style>
</head>

<body class="p-4">
<div class="wrap">

<?php if ($flash): ?>
<script>
  Swal.fire({
    icon: <?= json_encode($flash["type"]) ?>,
    title: <?= json_encode($flash["title"]) ?>,
    text: <?= json_encode($flash["msg"]) ?>,
    confirmButtonText: "Haye",
    confirmButtonColor: "#3b4cd6",
    width: 650
  });
</script>
<?php endif; ?>

<!-- TOP BAR -->
<div class="pageHead">
  <div class="crumb">
    Employees
    <span style="font-weight:900;color:#94a3b8;">|</span>
    <span style="display:flex;align-items:center;gap:8px;font-weight:1000;">
      <span style="font-size:20px;">🏠</span>
      <small>- All Employees</small>
    </span>
  </div>

  <div class="searchBox">
    <form method="GET" id="searchForm" class="searchBox" autocomplete="off">
      <div class="searchWrap">
        <div class="searchTag">Search Employee*</div>
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search Employee">
      </div>

      <button type="button" class="btnAll" id="btnAll">
        ⟳ &nbsp; All
      </button>

      <!-- OPTIONAL SUBJECT FILTER (you can keep or remove) -->
      <div class="filterRow" style="width:100%;justify-content:flex-end;">
        <select name="subject" id="subjectSel">
          <option value="">All Subjects</option>
          <?php foreach ($subjectOptions as $opt): ?>
            <option value="<?= h($opt) ?>" <?= ($subjectFilter === $opt ? "selected" : "") ?>>
              <?= h($opt) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
  </div>
</div>

<!-- GRID -->
<div class="grid">

  <!-- ADD NEW -->
  <a class="cardAdd" href="teachers_add.php" style="text-decoration:none;">
    <div class="addInner">
      <div class="plus">+</div>
      Add New
    </div>
  </a>

  <?php foreach ($teachers as $t): ?>
    <?php
      $name  = (string)($t["full_name"] ?? "teacher");
      $photo = trim((string)($t["photo_url"] ?? ""));
      $subjects = trim((string)($t["subjects_csv"] ?? ""));
      if ($subjects === "") $subjects = "—";

      $avatar = $photo !== "" ? $photo : avatarSvgDataUri($name);

      $payload = json_encode([
        "teacher_id" => (int)$t["teacher_id"],
        "full_name"  => $name,
        "username"   => (string)($t["username"] ?? ""),
        "phone"      => (string)($t["phone"] ?? ""),
        "email"      => (string)($t["email"] ?? ""),
        "status"     => (string)($t["status"] ?? ""),
        "specialization" => (string)($t["specialization"] ?? ""),
        "qualification"  => (string)($t["qualification"] ?? ""),
        "photo_url"  => $photo,
        "subjects_csv" => $subjects
      ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ?>

    <div class="cardEmp">
      <div class="avatar">
        <img src="<?= h($avatar) ?>" alt="avatar" onerror="this.src='<?= h(avatarSvgDataUri($name)) ?>'">
      </div>

      <div class="empName"><?= h($name) ?></div>
      <div class="empRole">Teacher</div>

      <div class="actions">
        <!-- VIEW -->
        <button class="icBtn icView btnView" type="button" data-t='<?= h($payload) ?>' title="View">
          <svg viewBox="0 0 24 24" fill="none" stroke="#1e293b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"></path>
            <circle cx="12" cy="12" r="3"></circle>
          </svg>
        </button>

        <!-- EDIT (Manage Subjects) -->
        <a class="icBtn icEdit" href="teacher_subjects.php?teacher_id=<?= (int)$t["teacher_id"] ?>&view=1" title="Manage Subjects" style="text-decoration:none;">
          <svg viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 20h9"></path>
            <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"></path>
          </svg>
        </a>

        <!-- DELETE -->
        <button class="icBtn icDel btnDelTeacher" type="button"
                data-id="<?= (int)$t["teacher_id"] ?>"
                data-name="<?= h($name) ?>"
                title="Delete">
          <svg viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="3 6 5 6 21 6"></polyline>
            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>
            <path d="M10 11v6"></path>
            <path d="M14 11v6"></path>
            <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"></path>
          </svg>
        </button>
      </div>
    </div>
  <?php endforeach; ?>

</div>

</div>

<!-- VIEW MODAL -->
<div class="modalx" id="viewModal">
  <div class="modalCard">
    <div class="modalTop">
      <div class="ttl">Teacher Details</div>
      <div class="d-flex gap-2">
        <a class="btn btn-primary btn-sm" id="vmManage" href="#">Manage Subjects</a>
        <button class="btn btn-outline-secondary btn-sm" id="vmClose" type="button">Close</button>
      </div>
    </div>

    <div class="modalBody">
      <div class="d-flex align-items-center gap-3 flex-wrap">
        <div class="avatar" style="width:110px;height:110px;">
          <img id="vmPhoto" src="" alt="photo">
        </div>
        <div>
          <div style="font-weight:1000;font-size:22px;" id="vmName">—</div>
          <div style="font-weight:1000;color:var(--muted);" id="vmRole">Teacher</div>
          <div style="font-weight:1000;margin-top:6px;" id="vmStatus">—</div>
        </div>
      </div>

      <div class="infoGrid">
        <div class="infoItem"><div class="k">Teacher ID</div><div class="v" id="vmId">—</div></div>
        <div class="infoItem"><div class="k">Username</div><div class="v" id="vmUsername">—</div></div>
        <div class="infoItem"><div class="k">Phone</div><div class="v" id="vmPhone">—</div></div>
        <div class="infoItem"><div class="k">Email</div><div class="v" id="vmEmail">—</div></div>
        <div class="infoItem"><div class="k">Specialization</div><div class="v" id="vmSpec">—</div></div>
        <div class="infoItem"><div class="k">Qualification</div><div class="v" id="vmQual">—</div></div>
      </div>

      <div style="margin-top:14px;">
        <div class="k" style="margin-bottom:6px;">Subjects</div>
        <div id="vmSubjects"></div>
      </div>
    </div>
  </div>
</div>

<script>
  // ===== LIVE SEARCH =====
  const form = document.getElementById("searchForm");
  const qInput = form.querySelector('input[name="q"]');
  const subjectSel = document.getElementById("subjectSel");
  const btnAll = document.getElementById("btnAll");
  let tmr = null;

  function submitDebounced(){
    if (tmr) clearTimeout(tmr);
    tmr = setTimeout(()=> form.submit(), 450);
  }

  qInput && qInput.addEventListener("input", submitDebounced);
  subjectSel && subjectSel.addEventListener("change", ()=> form.submit());

  btnAll && btnAll.addEventListener("click", ()=>{
    window.location.href = "teacher_subjects.php";
  });

  // ===== VIEW MODAL =====
  const modal = document.getElementById("viewModal");
  const closeBtn = document.getElementById("vmClose");
  const manageA  = document.getElementById("vmManage");

  function openModal(obj){
    document.getElementById("vmName").textContent = obj.full_name || "—";
    document.getElementById("vmId").textContent = (obj.teacher_id ?? "—");
    document.getElementById("vmUsername").textContent = (obj.username || "—");
    document.getElementById("vmPhone").textContent = (obj.phone || "—");
    document.getElementById("vmEmail").textContent = (obj.email || "—");
    document.getElementById("vmSpec").textContent = (obj.specialization || "—");
    document.getElementById("vmQual").textContent = (obj.qualification || "—");

    const st = (obj.status || "—");
    document.getElementById("vmStatus").innerHTML = (st === "ACTIVE")
      ? '<span style="color:#10b981;font-weight:1000;">Active ✓</span>'
      : st;

    // photo
    const img = document.getElementById("vmPhoto");
    const photo = (obj.photo_url || "").trim();
    if (photo !== "") img.src = photo;
    else img.src = "<?= h(avatarSvgDataUri("Teacher")) ?>";

    // subjects pills
    const wrap = document.getElementById("vmSubjects");
    wrap.innerHTML = "";
    const csv = (obj.subjects_csv || "").trim();
    if (!csv || csv === "—") {
      wrap.innerHTML = '<div class="k">—</div>';
    } else {
      csv.split(",").map(s=>s.trim()).filter(Boolean).forEach(s=>{
        const sp = document.createElement("span");
        sp.className = "pillSub";
        sp.textContent = s;
        wrap.appendChild(sp);
      });
    }

    const link = "teacher_subjects.php?teacher_id=" + encodeURIComponent(obj.teacher_id) + "&view=1";
    manageA.href = link;

    modal.classList.add("show");
  }
  function closeModal(){ modal.classList.remove("show"); }
  closeBtn && closeBtn.addEventListener("click", closeModal);
  modal.addEventListener("click", (e)=>{ if(e.target === modal) closeModal(); });

  document.querySelectorAll(".btnView").forEach(btn=>{
    btn.addEventListener("click", ()=>{
      try{
        const obj = JSON.parse(btn.dataset.t || "{}");
        openModal(obj);
      }catch(e){
        Swal.fire({ icon:"error", title:"Fariin", text:"Xogta lama akhrin karo.", confirmButtonText:"Haye" });
      }
    });
  });

  // ===== DELETE TEACHER CONFIRM =====
  document.querySelectorAll(".btnDelTeacher").forEach(btn=>{
    btn.addEventListener("click", ()=>{
      const id = btn.dataset.id;
      const name = btn.dataset.name || "";
      Swal.fire({
        icon: "warning",
        title: "Ma tirtiraysaa macallinkan?",
        text: name + " (ID: " + id + ")",
        showCancelButton: true,
        confirmButtonText: "Haa, tirtir",
        cancelButtonText: "Maya",
        confirmButtonColor: "#ef4444",
        cancelButtonColor: "#64748b",
        width: 650
      }).then(r=>{
        if(r.isConfirmed){
          window.location.href = "teacher_subjects.php?del_teacher=" + encodeURIComponent(id);
        }
      });
    });
  });
</script>

</body>
</html>
