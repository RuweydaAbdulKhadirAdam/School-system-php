<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . "/conncation.php";

/* =========================
   Flash Alerts (PRG)
   ========================= */
function setAlert(string $type, string $title, string $text): void {
  $_SESSION["flash_alert"] = ["type"=>$type, "title"=>$title, "text"=>$text];
}
function popAlert(): ?array {
  if (!isset($_SESSION["flash_alert"])) return null;
  $a = $_SESSION["flash_alert"];
  unset($_SESSION["flash_alert"]);
  return $a;
}
$alert = popAlert();

/* =========================
   If already logged in -> show choice (continue or switch account)
   ========================= */
$alreadyLoggedIn = false;
$alreadyTarget = '';
if (isset($_SESSION["user_id"]) && !empty($_SESSION["role"])) {
  $alreadyLoggedIn = true;
  $role = $_SESSION["role"] ?? "STUDENT";
  $map = [
    "ADMIN"     => "dashboardadmin.php",
    "TEACHER"   => "dashboardteacher.php",
    "RECEPTION" => "dashboardreception.php",
    "FINANCE"   => "dashboardfinance.php",
    "STUDENT"   => "dashboardstudent.php",
  ];
  $target = $map[$role] ?? "dashboardstudent.php";
  if (file_exists(__DIR__ . "/" . $target)) {
    $alreadyTarget = $target;
  }
}

/* =========================
   POST Login
   ========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $username = trim($_POST["username"] ?? "");
  $password = trim($_POST["password"] ?? "");

  if ($username === "" || $password === "") {
    setAlert("error", "Missing Fields", "Please enter Username and Password.");
    header("Location: login.php");
    exit;
  }

  $sql = "
    SELECT u.user_id, u.username, u.password_hash, u.is_active,
           r.role_name
    FROM users u
    LEFT JOIN user_roles ur ON ur.user_id = u.user_id
    LEFT JOIN roles r ON r.role_id = ur.role_id
    WHERE u.username = ?
    LIMIT 1
  ";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("s", $username);
  $stmt->execute();
  $res  = $stmt->get_result();
  $user = $res->fetch_assoc();

  if (!$user) {
    setAlert("error", "Login Failed", "Username or password is incorrect.");
    header("Location: login.php");
    exit;
  }

  if ((int)$user["is_active"] !== 1) {
    setAlert("error", "Account Disabled", "Your account is inactive. Contact admin.");
    header("Location: login.php");
    exit;
  }

  if (!password_verify($password, (string)$user["password_hash"])) {
    setAlert("error", "Login Failed", "Username or password is incorrect.");
    header("Location: login.php");
    exit;
  }

  // ✅ success
  $_SESSION["user_id"]  = (int)$user["user_id"];
  $_SESSION["username"] = (string)$user["username"];

  // Determine role(s) for this user more robustly. If user has multiple roles
  // prefer ADMIN, then RECEPTION, then FINANCE, TEACHER, STUDENT.
  $preferredRoles = ['ADMIN','RECEPTION','FINANCE','TEACHER','STUDENT'];
  $roleName = 'STUDENT';
  try {
    $uid = (int)$user['user_id'];
    $stmtR = $conn->prepare("SELECT r.role_name FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id WHERE ur.user_id = ?");
    $stmtR->bind_param('i', $uid);
    $stmtR->execute();
    $resR = $stmtR->get_result();
    $found = [];
    while ($rr = $resR->fetch_assoc()) {
      $found[] = (string)$rr['role_name'];
    }
    $stmtR->close();
    foreach ($preferredRoles as $pr) {
      if (in_array($pr, $found, true)) { $roleName = $pr; break; }
    }
    if (empty($found)) {
      // fallback to role from previous join (if any)
      $roleName = (string)($user['role_name'] ?? 'STUDENT');
    }
  } catch (Throwable $e) {
    $roleName = (string)($user['role_name'] ?? 'STUDENT');
  }

  $_SESSION["role"] = $roleName;

  $role = $_SESSION["role"];
  $map = [
    "ADMIN"     => "dashboardadmin.php",
    "TEACHER"   => "dashboardteacher.php",
    "RECEPTION" => "dashboardreception.php",
    "FINANCE"   => "dashboardfinance.php",
    "STUDENT"   => "dashboardstudent.php",
  ];
  $target = $map[$role] ?? "dashboardstudent.php";

  if (!file_exists(__DIR__ . "/" . $target)) {
    setAlert("success", "Login Success", "Login successful, but '$target' is missing. Create it in your folder.");
    header("Location: login.php");
    exit;
  }

  header("Location: $target");
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>HIGH School - Login</title>

  <!-- Local Bootstrap -->
  <link rel="stylesheet" href="bootstrap.css">

  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <!-- SweetAlert2 -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <style>
    :root{
      --blue:#0b63ce;
      --blue2:#0a58ca;
      --text:#111827;
      --muted:#6b7280;
      --card:#ffffff;
      --border:#d7e2f0;
      --green:#18a957;
      --pagebg: #f5f9ff;
    }

    *{ box-sizing:border-box; }
    body{
      margin:0;
      min-height:100vh;
      background: var(--pagebg);
      font-family: Arial, sans-serif;
      color: var(--text);
    }

    .wrap{
      width:100%;
      min-height:100vh;
      display:flex;
      align-items:stretch;
    }

    /* =========================
       LEFT SIDE (NO FRAME, NO NOTCH)
       Blur background + show image only
       ========================= */
    .leftArea{
      position:relative;
      flex: 1 1 auto;
      min-height:100vh;
      overflow:hidden;
      background:#eaf2ff;
    }

    .leftArea::before{
      content:"";
      position:absolute;
      inset:0;
      background: url("image/logo.avif") center/cover no-repeat;
      filter: blur(12px);
      transform: scale(1.12);
      opacity: .85;
    }

    .leftArea::after{
      content:"";
      position:absolute;
      inset:0;
      background: radial-gradient(circle at 30% 40%, rgba(0,0,0,.10) 0%, rgba(0,0,0,.35) 55%, rgba(0,0,0,.45) 100%);
    }

    .leftContent{
      position:relative;
      z-index:2;
      width:100%;
      height:100%;
      display:flex;
      align-items:center;
      justify-content:center;
      padding: 40px;
    }

    /* ✅ Only image (no frame) */
    .leftImage{
      width: min(520px, 80%);
      max-height: 78vh;
      object-fit: contain;
      filter: drop-shadow(0 28px 60px rgba(0,0,0,.35));
      border-radius: 10px; /* optional, can remove if you want sharp corners */
    }

    /* =========================
       RIGHT SIDE
       ========================= */
    .rightArea{
      flex: 0 0 640px;
      min-height:100vh;
      display:flex;
      align-items:center;
      justify-content:center;
      padding: 46px 44px;
      background: #f6fbff;
      border-left: 1px solid rgba(215,226,240,.95);
    }

    .rightInner{
      width: 100%;
      max-width: 520px;
    }

    .brandRow{
      display:flex;
      align-items:center;
      justify-content:center;
      gap: 16px;
      margin-bottom: 18px;
    }
    .brandRow img{
      width: 74px;
      height: 74px;
      object-fit: contain;
      border-radius: 50%;
      background:#fff;
      border: 1px solid var(--border);
      padding: 10px;
    }
    .brandText{
      line-height: 1.15;
      text-align:left;
    }
    .brandText .name{
      font-size: 28px;
      font-weight: 900;
      letter-spacing: .4px;
      text-transform: uppercase;
      color: #0f2b5c;
    }
    .brandText .sub{
      font-size: 12px;
      font-weight: 800;
      color: var(--muted);
      margin-top: 6px;
      text-transform: uppercase;
      letter-spacing: .8px;
    }

    .loginTitle{
      font-size: 40px;
      font-weight: 900;
      color: var(--green);
      text-align:left;
      margin: 10px 0 20px;
    }

    .form-control{
      height: 60px;
      border-radius: 999px;
      border: 1px solid var(--border);
      padding-left: 24px;
      font-size: 16px;
      background: #fff;
    }
    .form-control:focus{
      border-color: rgba(11,99,206,.35);
      box-shadow: 0 0 0 .22rem rgba(11,99,206,.12);
    }

    .btn-login{
      height: 66px;
      border-radius: 999px;
      background: var(--blue);
      border: none;
      font-weight: 900;
      letter-spacing: 1px;
      font-size: 16px;
      width:100%;
    }
    .btn-login:hover{ background: var(--blue2); }

    .social{
      display:flex;
      justify-content:center;
      gap: 28px;
      margin-top: 22px;
    }
    .social a{
      color:#1d2b4f;
      opacity:.78;
      font-size: 18px;
      text-decoration:none;
    }
    .social a:hover{ opacity: 1; }

    .copy{
      text-align:center;
      margin-top: 14px;
      color: var(--muted);
      font-size: 14px;
    }

    @media (max-width: 980px){
      .leftArea{ display:none; }
      .rightArea{
        flex: 1 1 auto;
        width:100%;
        border-left:none;
      }
      .loginTitle{ text-align:center; }
      .brandText{ text-align:center; }
      .brandRow{ justify-content:center; }
    }
  </style>
</head>

<body>

<div class="wrap">

  <!-- LEFT (ONLY IMAGE, NO FRAME) -->
  <div class="leftArea">
    <div class="leftContent">
      <img class="leftImage" src="image/logo.avif" alt="High School Design">
    </div>
  </div>

  <!-- RIGHT -->
  <div class="rightArea">
    <div class="rightInner">

      <div class="brandRow">
        <img src="image/logo.avif" alt="School Logo">
        <div class="brandText">
          <div class="name">HIGH SCHOOL</div>
          <div class="sub">Student Management System</div>
        </div>
      </div>

      <div class="loginTitle">HIGH School Login</div>

      <form method="POST" autocomplete="off" novalidate>
        <div class="mb-3">
          <input type="text" name="username" class="form-control" placeholder="Username">
        </div>

        <div class="mb-3">
          <input type="password" name="password" class="form-control" placeholder="Password">
        </div>

        <button type="submit" class="btn btn-primary btn-login">SIGN IN</button>
      </form>

      <div class="social">
        <a href="#" title="Twitter"><i class="fa-brands fa-x-twitter"></i></a>
        <a href="#" title="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
        <a href="#" title="Instagram"><i class="fa-brands fa-instagram"></i></a>
        <a href="#" title="LinkedIn"><i class="fa-brands fa-linkedin-in"></i></a>
      </div>

      <div class="copy">© All rights reserved 2025</div>

    </div>
  </div>

</div>

<?php if ($alert): ?>
<script>
  Swal.fire({
    icon: <?= json_encode($alert["type"]) ?>,
    title: <?= json_encode($alert["title"]) ?>,
    text: <?= json_encode($alert["text"]) ?>,
    confirmButtonColor: "#0b63ce"
  });
</script>
<?php endif; ?>

</body>
</html>
