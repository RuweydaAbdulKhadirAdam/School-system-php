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
function setFlash(string $type, string $title, string $msg): void {
  $_SESSION["flash"] = ["type"=>$type, "title"=>$title, "msg"=>$msg];
}
$flash = $_SESSION["flash"] ?? null;
unset($_SESSION["flash"]);

function cleanSpaces(string $v): string {
  $v = trim($v);
  $v = preg_replace('/\s+/', ' ', $v);
  return $v ?? "";
}

/* Placeholder avatar (no photo) */
function avatarSvgDataUri(string $name): string {
  $name = trim($name);
  $initial = mb_strtoupper(mb_substr($name, 0, 1));
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
  return "data:image/svg+xml;base64," . base64_encode($svg);
}

/* =====================
   DELETE teacher
   ===================== */
if (isset($_GET["delete"])) {
  $teacherId = (int)$_GET["delete"];
  if ($teacherId <= 0) {
    setFlash("error", "Fariin", "Teacher ID ma saxna.");
    header("Location: teachers.php"); exit;
  }

  $conn->begin_transaction();
  try {
    $st = $conn->prepare("
      SELECT u.user_id
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

    if (!$row) throw new Exception("Macallinka lama helin.");

    $userId = (int)$row["user_id"];

    $del = $conn->prepare("DELETE FROM users WHERE user_id=? LIMIT 1");
    $del->bind_param("i", $userId);
    $del->execute();
    $del->close();

    $conn->commit();
    setFlash("success", "Guul", "Macallinka waa la tirtiray si guul ah.");
  } catch (Throwable $e) {
    $conn->rollback();
    setFlash("error", "Fariin", "DB Error: ".$e->getMessage());
  }

  header("Location: teachers.php");
  exit;
}

/* =====================
   UPDATE (POST) from modal
   ===================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "update_teacher") {
  $teacherId      = (int)($_POST["teacher_id"] ?? 0);
  $username       = cleanSpaces($_POST["username"] ?? "");
  $newPassword    = (string)($_POST["new_password"] ?? "");
  $fullName       = cleanSpaces($_POST["full_name"] ?? "");
  $phone          = cleanSpaces($_POST["phone"] ?? "");
  $email          = cleanSpaces($_POST["email"] ?? "");
  $gender         = cleanSpaces($_POST["gender"] ?? "");
  $photoUrl       = cleanSpaces($_POST["photo_url"] ?? "");
  $salaryAmount   = cleanSpaces($_POST["salary_amount"] ?? "0");
  $hiredDate      = cleanSpaces($_POST["hired_date"] ?? "");
  $status         = cleanSpaces($_POST["status"] ?? "ACTIVE");
  $specialization = cleanSpaces($_POST["specialization"] ?? "");
  $qualification  = cleanSpaces($_POST["qualification"] ?? "");

  if ($teacherId <= 0) { setFlash("error","Fariin","Teacher ID ma saxna."); header("Location: teachers.php"); exit; }
  if ($username === "" || $fullName === "") { setFlash("error","Fariin","Username iyo Full Name waa qasab."); header("Location: teachers.php"); exit; }
  if ($gender !== "" && !in_array($gender, ["M","F"], true)) { setFlash("error","Fariin","Gender ma saxna."); header("Location: teachers.php"); exit; }
  if (!is_numeric($salaryAmount) || (float)$salaryAmount < 0) { setFlash("error","Fariin","Salary Amount ma saxna."); header("Location: teachers.php"); exit; }
  if ($hiredDate !== "" && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hiredDate)) { setFlash("error","Fariin","Hired Date format ma saxna."); header("Location: teachers.php"); exit; }
  if ($status !== "" && !in_array($status, ["ACTIVE","INACTIVE"], true)) { setFlash("error","Fariin","Status ma saxna."); header("Location: teachers.php"); exit; }

  $salary = round((float)$salaryAmount, 2);
  $genderN = ($gender === "") ? null : $gender;
  $photoN  = ($photoUrl === "") ? null : $photoUrl;
  $hiredN  = ($hiredDate === "") ? null : $hiredDate;
  $specN   = ($specialization === "") ? null : $specialization;
  $qualN   = ($qualification === "") ? null : $qualification;
  $emailN  = ($email === "") ? null : $email;

  $conn->begin_transaction();
  try {
    $st = $conn->prepare("
      SELECT t.teacher_id, e.employee_id, u.user_id
      FROM teachers t
      JOIN employees e ON e.employee_id = t.employee_id
      JOIN users u ON u.user_id = e.user_id
      WHERE t.teacher_id=?
      LIMIT 1
    ");
    $st->bind_param("i", $teacherId);
    $st->execute();
    $ids = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$ids) throw new Exception("Macallinka lama helin.");

    $userId = (int)$ids["user_id"];
    $employeeId = (int)$ids["employee_id"];

    if ($newPassword !== "") {
      $hash = password_hash($newPassword, PASSWORD_BCRYPT);
      $uu = $conn->prepare("UPDATE users SET username=?, phone=?, email=?, password_hash=? WHERE user_id=? LIMIT 1");
      $uu->bind_param("ssssi", $username, $phone, $emailN, $hash, $userId);
    } else {
      $uu = $conn->prepare("UPDATE users SET username=?, phone=?, email=? WHERE user_id=? LIMIT 1");
      $uu->bind_param("sssi", $username, $phone, $emailN, $userId);
    }
    $uu->execute();
    $uu->close();

    $ue = $conn->prepare("
      UPDATE employees
      SET full_name=?, phone=?, gender=?, photo_url=?, salary_amount=?, hired_date=?, status=?
      WHERE employee_id=? AND user_id=?
      LIMIT 1
    ");
    $ue->bind_param("ssssdssii", $fullName, $phone, $genderN, $photoN, $salary, $hiredN, $status, $employeeId, $userId);
    $ue->execute();
    $ue->close();

    $ut = $conn->prepare("
      UPDATE teachers
      SET specialization=?, qualification=?
      WHERE teacher_id=? AND employee_id=?
      LIMIT 1
    ");
    $ut->bind_param("ssii", $specN, $qualN, $teacherId, $employeeId);
    $ut->execute();
    $ut->close();

    $conn->commit();
    setFlash("success", "Guul", "Xogta macallinka waa la cusbooneysiiyay.");
  } catch (Throwable $e) {
    $conn->rollback();
    setFlash("error", "Fariin", "DB Error: ".$e->getMessage());
  }

  header("Location: teachers.php");
  exit;
}

/* =====================
   Fetch list (LIVE search)
   ===================== */
$q = cleanSpaces($_GET["q"] ?? "");
$where = "1";
$types = "";
$params = [];

if ($q !== "") {
  $where .= " AND (
    e.full_name LIKE ?
    OR u.username LIKE ?
    OR e.phone LIKE ?
    OR u.email LIKE ?
    OR CAST(t.teacher_id AS CHAR) LIKE ?
    OR COALESCE(t.specialization,'') LIKE ?
    OR COALESCE(t.qualification,'') LIKE ?
  )";
  $types .= "sssssss";
  $like = "%".$q."%";
  $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}

$sql = "
SELECT
  t.teacher_id,
  u.user_id,
  u.username,
  u.email,
  u.phone AS user_phone,
  u.is_active,
  e.employee_id,
  e.full_name,
  e.phone,
  e.gender,
  e.photo_url,
  e.salary_amount,
  e.hired_date,
  e.status,
  t.specialization,
  t.qualification
FROM teachers t
JOIN employees e ON e.employee_id = t.employee_id
JOIN users u ON u.user_id = e.user_id
WHERE $where
ORDER BY t.teacher_id DESC
LIMIT 400
";

$stmt = $conn->prepare($sql);
if ($types !== "") $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Teachers</title>
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
      --shadow: 0 10px 30px rgba(2,6,23,.10);
      --radius: 22px;
    }
    body{ background:var(--bg); color:var(--text); }
    .wrap{ max-width: 1280px; margin: 0 auto; }

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
      flex-wrap:wrap;
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
      width: 560px;
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
      cursor:pointer;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:8px;
    }

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
      padding: 18px 16px 16px;
      text-align:center;
      min-height: 260px;
    }
    .avatar{
      width: 120px;
      height: 120px;
      border-radius: 999px;
      margin: 8px auto 14px;
      overflow:hidden;
      background: #eef2ff;
      display:flex;
      align-items:center;
      justify-content:center;
    }
    .avatar img{ width:100%; height:100%; object-fit: cover; }

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

    .actions{
      display:flex;
      justify-content:center;
      gap: 12px;
      margin-top: 8px;
    }
    .icBtn{
      width: 44px;
      height: 44px;
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
      text-decoration:none;
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

    /* ===== VIEW MODAL (Beautiful) ===== */
    .viewModal{
      display:none;
      position:fixed;
      inset:0;
      background: rgba(0,0,0,.55);
      z-index: 9999;
      padding: 22px;
      overflow:auto;
    }
    .viewModal.show{ display:block; }
    .viewCard{
      max-width: 920px;
      margin: 0 auto;
      background:#fff;
      border-radius: 18px;
      box-shadow: var(--shadow);
      border: 1px solid rgba(2,6,23,.12);
      overflow:hidden;
    }
    .viewTop{
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
    .viewTop .ttl{ font-weight:1000; font-size:16px; }
    .viewBody{ padding: 18px; }

    .heroRow{
      display:flex;
      align-items:center;
      gap: 14px;
      flex-wrap:wrap;
    }
    .heroAvatar{
      width: 110px; height:110px; border-radius: 999px;
      overflow:hidden; background:#eef2ff;
      border: 1px solid rgba(2,6,23,.08);
    }
    .heroAvatar img{ width:100%; height:100%; object-fit:cover; }
    .heroName{ font-weight:1000; font-size:22px; }
    .heroRole{ font-weight:1000; color:var(--muted); }
    .pill{
      display:inline-block;
      margin-top: 8px;
      padding: 6px 10px;
      border-radius: 999px;
      font-weight:1000;
      font-size: 12px;
      background:#f1f5f9;
      border: 1px solid rgba(2,6,23,.08);
    }
    .pill.ok{ background:#ecfdf5; border-color:#a7f3d0; color:#047857; }
    .pill.bad{ background:#fff1f2; border-color:#fecdd3; color:#be123c; }

    .infoGrid{
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
      margin-top: 14px;
    }
    @media(max-width:760px){ .infoGrid{ grid-template-columns: 1fr; } }

    .infoItem{
      background:#f7f8ff;
      border: 1px solid rgba(2,6,23,.08);
      border-radius: 14px;
      padding: 10px 12px;
    }
    .k{ font-size: 12px; font-weight: 1000; color: var(--muted); }
    .v{ font-size: 15px; font-weight: 1000; word-break: break-word; }

    /* ===== EDIT MODAL ===== */
    .editModal{
      display:none;
      position:fixed;
      inset:0;
      background: rgba(0,0,0,.60);
      z-index: 10000;
      align-items:flex-start;
      justify-content:center;
      padding: 18px;
      overflow:auto;
    }
    .editModal.show{ display:flex; }
    .editCard{
      width:min(1100px, 100%);
      background: var(--card);
      border: 1px solid rgba(2,6,23,.12);
      border-radius: 18px;
      box-shadow: var(--shadow);
      padding: 16px;
    }
    .hr{ height:1px; background: rgba(2,6,23,.10); margin:12px 0; }
    label{ font-weight: 1000; }
    .mutedTxt{ color:var(--muted); font-weight:900; font-size:12px; }
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

  <form method="GET" id="searchForm" class="searchBox" autocomplete="off">
    <div class="searchWrap">
      <div class="searchTag">Search Employee*</div>
      <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search Employee">
    </div>

    <button type="button" class="btnAll" id="btnAll">⟳ All</button>

    <a href="teachers_add.php" class="btnAll" style="background:#111827;">+ Add</a>
  </form>
</div>

<!-- GRID -->
<div class="grid">

  <!-- ADD NEW TILE -->
  <a class="cardAdd" href="teachers_add.php">
    <div class="addInner">
      <div class="plus">+</div>
      Add New
    </div>
  </a>

  <?php foreach($rows as $r): ?>
    <?php
      $name = (string)($r["full_name"] ?? "teacher");
      $photo = trim((string)($r["photo_url"] ?? ""));
      $avatar = $photo !== "" ? $photo : avatarSvgDataUri($name);

      $tid = (int)$r["teacher_id"];

      // Full payload for view modal + edit modal
      $payloadFull = json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ?>
    <div class="cardEmp">
      <div class="avatar">
        <img src="<?= h($avatar) ?>" alt="avatar" onerror="this.src='<?= h(avatarSvgDataUri($name)) ?>'">
      </div>

      <div class="empName"><?= h($name) ?></div>
      <div class="empRole">Teacher</div>

      <div class="actions">
        <!-- VIEW -->
        <button class="icBtn icView btnView" type="button" data-full='<?= h($payloadFull) ?>' title="View">
          <svg viewBox="0 0 24 24" fill="none" stroke="#1e293b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"></path>
            <circle cx="12" cy="12" r="3"></circle>
          </svg>
        </button>

        <!-- EDIT -->
        <button class="icBtn icEdit btnEdit" type="button" data-teacher='<?= h($payloadFull) ?>' title="Edit">
          <svg viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 20h9"></path>
            <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"></path>
          </svg>
        </button>

        <!-- DELETE -->
        <button class="icBtn icDel btnDel" type="button"
                data-id="<?= $tid ?>"
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

<!-- ===== VIEW MODAL ===== -->
<div class="viewModal" id="viewModal">
  <div class="viewCard">
    <div class="viewTop">
      <div class="ttl">Teacher Details</div>
      <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary btn-sm" id="vmClose" type="button">Close</button>
      </div>
    </div>

    <div class="viewBody">
      <div class="heroRow">
        <div class="heroAvatar"><img id="vmPhoto" src="" alt="photo"></div>
        <div>
          <div class="heroName" id="vmName">—</div>
          <div class="heroRole">Teacher</div>
          <div id="vmStatusPill"></div>
        </div>
      </div>

      <div class="infoGrid">
        <div class="infoItem"><div class="k">Teacher ID</div><div class="v" id="vmTid">—</div></div>
        <div class="infoItem"><div class="k">Employee ID</div><div class="v" id="vmEid">—</div></div>

        <div class="infoItem"><div class="k">Username</div><div class="v" id="vmUsername">—</div></div>
        <div class="infoItem"><div class="k">Email</div><div class="v" id="vmEmail">—</div></div>

        <div class="infoItem"><div class="k">Phone</div><div class="v" id="vmPhone">—</div></div>
        <div class="infoItem"><div class="k">Gender</div><div class="v" id="vmGender">—</div></div>

        <div class="infoItem"><div class="k">Salary</div><div class="v" id="vmSalary">—</div></div>
        <div class="infoItem"><div class="k">Hired Date</div><div class="v" id="vmHired">—</div></div>

        <div class="infoItem"><div class="k">Specialization</div><div class="v" id="vmSpec">—</div></div>
        <div class="infoItem"><div class="k">Qualification</div><div class="v" id="vmQual">—</div></div>

        <div class="infoItem"><div class="k">User Active</div><div class="v" id="vmActive">—</div></div>
        <div class="infoItem"><div class="k">User ID</div><div class="v" id="vmUid">—</div></div>
      </div>
    </div>
  </div>
</div>

<!-- ===== EDIT MODAL ===== -->
<div class="editModal" id="editModal">
  <div class="editCard">
    <div class="d-flex justify-content-between align-items-center">
      <div>
        <div style="font-weight:1000;font-size:18px;" id="mTitle">Edit Teacher</div>
        <div class="mutedTxt">Halkan ka sax xogta macallinka. Save kadib page-ku wuu refresh garaynayaa.</div>
      </div>
      <button class="btn btn-outline-secondary btn-sm" id="mClose" type="button">Close</button>
    </div>

    <div class="hr"></div>

    <form method="POST" id="editForm" autocomplete="off">
      <input type="hidden" name="action" value="update_teacher">
      <input type="hidden" name="teacher_id" id="f_teacher_id">

      <div class="row g-3">
        <div class="col-md-4">
          <label>Username *</label>
          <input class="form-control" name="username" id="f_username" required>
        </div>
        <div class="col-md-4">
          <label>Email</label>
          <input class="form-control" type="email" name="email" id="f_email">
        </div>
        <div class="col-md-4">
          <label>New Password (optional)</label>
          <input class="form-control" type="password" name="new_password" id="f_new_password" placeholder="leave empty to keep old">
        </div>

        <div class="col-md-6">
          <label>Full Name *</label>
          <input class="form-control" name="full_name" id="f_full_name" required>
        </div>

        <div class="col-md-3">
          <label>Phone</label>
          <input class="form-control" name="phone" id="f_phone">
        </div>

        <div class="col-md-3">
          <label>Gender</label>
          <select class="form-control" name="gender" id="f_gender">
            <option value="">--</option>
            <option value="M">Male</option>
            <option value="F">Female</option>
          </select>
        </div>

        <div class="col-md-6">
          <label>Photo URL</label>
          <input class="form-control" name="photo_url" id="f_photo_url">
        </div>

        <div class="col-md-3">
          <label>Salary Amount</label>
          <input class="form-control" type="number" step="0.01" min="0" name="salary_amount" id="f_salary_amount">
        </div>

        <div class="col-md-3">
          <label>Hired Date</label>
          <input class="form-control" type="date" name="hired_date" id="f_hired_date">
        </div>

        <div class="col-md-3">
          <label>Status</label>
          <select class="form-control" name="status" id="f_status">
            <option value="ACTIVE">ACTIVE</option>
            <option value="INACTIVE">INACTIVE</option>
          </select>
        </div>

        <div class="col-md-3">
          <label>Specialization</label>
          <input class="form-control" name="specialization" id="f_specialization">
        </div>

        <div class="col-md-6">
          <label>Qualification</label>
          <input class="form-control" name="qualification" id="f_qualification">
        </div>

        <div class="col-12 d-flex gap-2">
          <button class="btn btn-primary" type="submit">Save Changes</button>
          <button class="btn btn-outline-secondary" type="button" id="btnCancel">Cancel</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
  // ===== LIVE SEARCH =====
  const form = document.getElementById("searchForm");
  const qInput = form.querySelector('input[name="q"]');
  const btnAll = document.getElementById("btnAll");
  let tmr = null;

  function submitDebounced(){
    if (tmr) clearTimeout(tmr);
    tmr = setTimeout(()=> form.submit(), 450);
  }
  qInput && qInput.addEventListener("input", submitDebounced);
  btnAll && btnAll.addEventListener("click", ()=> window.location.href = "teachers.php");

  // ===== DELETE CONFIRM =====
  document.querySelectorAll(".btnDel").forEach(btn=>{
    btn.addEventListener("click", ()=>{
      const id = btn.dataset.id;
      const nm = btn.dataset.name || "macallinkan";
      Swal.fire({
        icon: "warning",
        title: "Ma tirtiraysaa?",
        text: "Ma hubtaa inaad tirtirto: " + nm + " (ID " + id + ")?",
        showCancelButton: true,
        confirmButtonText: "Haa, tirtir",
        cancelButtonText: "Maya",
        confirmButtonColor: "#ef4444",
        cancelButtonColor: "#64748b",
        width: 650
      }).then(r=>{
        if(r.isConfirmed){
          window.location.href = "teachers.php?delete=" + encodeURIComponent(id);
        }
      });
    });
  });

  // ===== VIEW MODAL (FULL DETAILS) =====
  const viewModal = document.getElementById("viewModal");
  const vmClose = document.getElementById("vmClose");

  function openView(obj){
    const name = (obj.full_name || "—");
    document.getElementById("vmName").textContent = name;

    document.getElementById("vmTid").textContent = (obj.teacher_id ?? "—");
    document.getElementById("vmEid").textContent = (obj.employee_id ?? "—");
    document.getElementById("vmUid").textContent = (obj.user_id ?? "—");

    document.getElementById("vmUsername").textContent = (obj.username || "—");
    document.getElementById("vmEmail").textContent = (obj.email || "—");
    document.getElementById("vmPhone").textContent = (obj.phone || obj.user_phone || "—");
    document.getElementById("vmGender").textContent = (obj.gender || "—");

    document.getElementById("vmSalary").textContent = (obj.salary_amount != null ? "$" + Number(obj.salary_amount).toFixed(2) : "—");
    document.getElementById("vmHired").textContent = (obj.hired_date || "—");
    document.getElementById("vmSpec").textContent = (obj.specialization || "—");
    document.getElementById("vmQual").textContent = (obj.qualification || "—");

    const active = (obj.is_active != null ? String(obj.is_active) : "—");
    document.getElementById("vmActive").textContent = active;

    const st = (obj.status || "—");
    const pill = document.getElementById("vmStatusPill");
    if(st === "ACTIVE"){
      pill.innerHTML = '<span class="pill ok">ACTIVE ✓</span>';
    }else if(st === "INACTIVE"){
      pill.innerHTML = '<span class="pill bad">INACTIVE ✕</span>';
    }else{
      pill.innerHTML = '<span class="pill">' + st + '</span>';
    }

    const img = document.getElementById("vmPhoto");
    const photo = (obj.photo_url || "").trim();
    if(photo !== "") img.src = photo;
    else img.src = "<?= h(avatarSvgDataUri("Teacher")) ?>";

    viewModal.classList.add("show");
  }
  function closeView(){ viewModal.classList.remove("show"); }
  vmClose && vmClose.addEventListener("click", closeView);
  viewModal.addEventListener("click", (e)=>{ if(e.target === viewModal) closeView(); });

  document.querySelectorAll(".btnView").forEach(btn=>{
    btn.addEventListener("click", ()=>{
      try{
        const obj = JSON.parse(btn.dataset.full || "{}");
        openView(obj);
      }catch(e){
        Swal.fire({ icon:"error", title:"Fariin", text:"Xogta lama akhrin karin.", confirmButtonText:"Haye" });
      }
    });
  });

  // ===== EDIT MODAL =====
  const editModal = document.getElementById("editModal");
  const mClose = document.getElementById("mClose");
  const btnCancel = document.getElementById("btnCancel");

  function openEdit(obj){
    document.getElementById("mTitle").textContent = "Edit Teacher: " + (obj.full_name || "—");
    document.getElementById("f_teacher_id").value = obj.teacher_id || "";
    document.getElementById("f_username").value = obj.username || "";
    document.getElementById("f_email").value = obj.email || "";
    document.getElementById("f_new_password").value = "";

    document.getElementById("f_full_name").value = obj.full_name || "";
    document.getElementById("f_phone").value = obj.phone || "";
    document.getElementById("f_gender").value = obj.gender || "";
    document.getElementById("f_photo_url").value = obj.photo_url || "";
    document.getElementById("f_salary_amount").value = (obj.salary_amount ?? 0);
    document.getElementById("f_hired_date").value = obj.hired_date || "";
    document.getElementById("f_status").value = obj.status || "ACTIVE";
    document.getElementById("f_specialization").value = obj.specialization || "";
    document.getElementById("f_qualification").value = obj.qualification || "";

    editModal.classList.add("show");
  }
  function closeEdit(){ editModal.classList.remove("show"); }

  mClose && mClose.addEventListener("click", closeEdit);
  btnCancel && btnCancel.addEventListener("click", closeEdit);
  editModal.addEventListener("click", (e)=>{ if(e.target === editModal) closeEdit(); });

  document.querySelectorAll(".btnEdit").forEach(btn=>{
    btn.addEventListener("click", ()=>{
      try{
        const obj = JSON.parse(btn.dataset.teacher || "{}");
        openEdit(obj);
      }catch(e){
        Swal.fire({ icon:"error", title:"Fariin", text:"Xogta macallinka lama akhrin karin.", confirmButtonText:"Haye" });
      }
    });
  });
</script>

</body>
</html>
