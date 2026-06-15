<?php
// login.php

declare(strict_types=1);
session_start();
require_once __DIR__ . "/conncation.php";

// Haddii hore u login-gareeyay, u gudbi
if (isset($_SESSION["user_id"])) {
  $role = $_SESSION["role"] ?? "";
  if ($role === "ADMIN") header("Location: dashboardadmin.php");
  elseif ($role === "TEACHER") header("Location: dashboardteacher.php");
  elseif ($role === "RECEPTION") header("Location: dashboardreception.php");
  elseif ($role === "FINANCE") header("Location: dashboardfinance.php");
  else header("Location: dashboardstudent.php");
  exit;
}

$alert = null; // ['type'=>'error|success','title'=>'','text'=>'']

// Message ka iman kara redirect (unauthorized iwm)
if (isset($_GET["msg"])) {
  $m = $_GET["msg"];
  if ($m === "unauthorized") {
    $alert = ["type"=>"error","title"=>"Unauthorized","text"=>"You are not allowed to access that page. Please login again."];
  } elseif ($m === "logout") {
    $alert = ["type"=>"success","title"=>"Logged out","text"=>"You have been logged out successfully."];
  }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $username = trim($_POST["username"] ?? "");
  $password = trim($_POST["password"] ?? "");

  if ($username === "" || $password === "") {
    $alert = ["type"=>"error","title"=>"Missing Fields","text"=>"Please enter Username and Password."];
  } else {
    // Find user by username
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
    $res = $stmt->get_result();
    $user = $res->fetch_assoc();

    if (!$user) {
      $alert = ["type"=>"error","title"=>"Login Failed","text"=>"Username or password is incorrect."];
    } else {
      if ((int)$user["is_active"] !== 1) {
        $alert = ["type"=>"error","title"=>"Account Disabled","text"=>"Your account is inactive. Contact the admin."];
      } else {
        $hash = (string)$user["password_hash"];
        if (!password_verify($password, $hash)) {
          $alert = ["type"=>"error","title"=>"Login Failed","text"=>"Username or password is incorrect."];
        } else {
          // OK -> create session
          $_SESSION["user_id"]  = (int)$user["user_id"];
          $_SESSION["username"] = (string)$user["username"];
          $_SESSION["role"]     = (string)($user["role_name"] ?? "STUDENT");

          // Redirect by role
          $role = $_SESSION["role"];
          if ($role === "ADMIN") header("Location: dashboardadmin.php");
          elseif ($role === "TEACHER") header("Location: dashboardteacher.php");
          elseif ($role === "RECEPTION") header("Location: dashboardreception.php");
          elseif ($role === "FINANCE") header("Location: dashboardfinance.php");
          else header("Location: dashboardstudent.php");
          exit;
        }
      }
    }
  }
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

  <!-- Font Awesome (icons) -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <!-- SweetAlert2 -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <style>
    :root{
      --blue:#0b63ce;
      --blue2:#0a58ca;
      --bg:#eef4fb;
      --text:#223;
      --muted:#6b7280;
      --card:#ffffff;
      --border:#d7e2f0;
      --green:#18a957;
    }
    body{
      background: linear-gradient(180deg, #f5f9ff 0%, var(--bg) 100%);
      min-height: 100vh;
      display:flex;
      align-items:center;
      justify-content:center;
      color: var(--text);
    }
    .wrap{
      width: 1100px;
      max-width: 95%;
      display:grid;
      grid-template-columns: 1.05fr 0.95fr;
      gap: 28px;
      align-items:center;
    }
    .leftMock{
      border-radius: 22px;
      overflow:hidden;
      min-height: 520px;
      background:
        radial-gradient(circle at 30% 25%, rgba(255,255,255,.70) 0%, rgba(255,255,255,0) 50%),
        url("image/bg.jpg");
      background-size: cover;
      background-position: center;
      position: relative;
      box-shadow: 0 18px 50px rgba(0,0,0,.08);
    }
    /* Haddii aadan haysan image/bg.jpg, leftMock wuxuu noqon doonaa gradient */
    .leftMock::before{
      content:"";
      position:absolute;
      inset:0;
      background: linear-gradient(120deg, rgba(3,54,110,.40), rgba(255,255,255,.05));
      pointer-events:none;
    }
    .phoneCard{
      position:absolute;
      left: 24px;
      bottom: 24px;
      width: 280px;
      max-width: 78%;
      border-radius: 22px;
      background: rgba(255,255,255,.12);
      border: 1px solid rgba(255,255,255,.25);
      backdrop-filter: blur(8px);
      padding: 18px;
      color: #fff;
    }
    .phoneCard h5{ margin:0 0 6px 0; font-weight:700; }
    .phoneCard p{ margin:0; opacity:.9; font-size:14px; }

    .loginCard{
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 22px;
      padding: 28px 28px 20px;
      box-shadow: 0 18px 50px rgba(0,0,0,.06);
    }
    .brandRow{
      display:flex;
      align-items:center;
      gap: 14px;
      margin-bottom: 12px;
    }
    .brandRow img{
      width: 58px;
      height: 58px;
      object-fit: contain;
      border-radius: 12px;
      background:#fff;
      border: 1px solid var(--border);
      padding: 6px;
    }
    .brandRow .uName{
      font-size: 22px;
      font-weight: 800;
      line-height:1.15;
    }
    .brandRow .sub{
      font-size: 13px;
      color: var(--muted);
      margin-top: 2px;
      font-weight: 600;
    }

    .title{
      font-size: 34px;
      font-weight: 900;
      color: var(--green);
      margin: 10px 0 18px;
    }

    .form-control{
      height: 54px;
      border-radius: 999px;
      border: 1px solid var(--border);
      padding-left: 20px;
      font-size: 16px;
    }
    .form-control:focus{
      border-color: rgba(11,99,206,.35);
      box-shadow: 0 0 0 .2rem rgba(11,99,206,.12);
    }

    .btn-login{
      height: 56px;
      border-radius: 999px;
      background: var(--blue);
      border: none;
      font-weight: 800;
      letter-spacing: 1px;
      font-size: 16px;
    }
    .btn-login:hover{ background: var(--blue2); }

    .social{
      display:flex;
      justify-content:center;
      gap: 26px;
      margin-top: 18px;
    }
    .social a{
      color:#1d2b4f;
      opacity:.75;
      font-size: 18px;
      text-decoration:none;
    }
    .social a:hover{ opacity:1; }

    .copy{
      text-align:center;
      margin-top: 14px;
      color: var(--muted);
      font-size: 14px;
    }

    @media (max-width: 900px){
      .wrap{ grid-template-columns: 1fr; }
      .leftMock{ min-height: 240px; }
      .phoneCard{ display:none; }
    }
  </style>
</head>

<body>

<div class="wrap">

  <!-- LEFT (mock side like the screenshot) -->
  <div class="leftMock">
    <div class="phoneCard">
      <h5>HIGH School Portal</h5>
      <p>Login to manage students, attendance, exams and fees.</p>
    </div>
  </div>

  <!-- RIGHT (login form) -->
  <div class="loginCard">
    <div class="brandRow">
      <img src="image/logo.avif" alt="logo">
      <div>
        <div class="uName">HIGH School</div>
        <div class="sub">Student Management System</div>
      </div>
    </div>

    <div class="title">HIGH School</div>

    <form method="POST" autocomplete="off">
      <div class="mb-3">
        <input
          type="text"
          name="username"
          class="form-control"
          placeholder="Username"
          value="<?= isset($_POST['username']) ? e((string)$_POST['username']) : '' ?>"
        >
      </div>

      <div class="mb-3">
        <input
          type="password"
          name="password"
          class="form-control"
          placeholder="Password"
        >
      </div>

      <button type="submit" class="btn btn-primary w-100 btn-login">
        SIGN IN
      </button>
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
