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
   Helpers
   ========================= */
function clean(string $v): string {
  return trim(preg_replace('/\s+/', ' ', $v));
}
function back(string $type, string $title, string $text): void {
  setAlert($type, $title, $text);
  header("Location: registration.php");
  exit;
}

/* =========================
   1) CHECK IF ANY ADMIN EXISTS
   - If no admin exists => allow first admin creation without login
   - If admin exists => only ADMIN can open this page
   ========================= */
$adminExists = false;

$chk = $conn->query("
  SELECT u.user_id
  FROM users u
  JOIN user_roles ur ON ur.user_id = u.user_id
  JOIN roles r ON r.role_id = ur.role_id
  WHERE r.role_name = 'ADMIN'
  LIMIT 1
");
if ($chk && $chk->num_rows > 0) {
  $adminExists = true;
}

/* =========================
   2) GUARD
   ========================= */
if ($adminExists) {
  // Admin already exists -> only logged-in admin can create new admin
  if (!isset($_SESSION["user_id"])) {
    setAlert("error", "Login Required", "Please login as ADMIN first.");
    header("Location: login.php");
    exit;
  }
  if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "ADMIN") {
    setAlert("error", "Unauthorized", "Only ADMIN can create a new admin.");
    header("Location: login.php");
    exit;
  }
}

/* =========================
   Fetch available roles for the form
   ========================= */
$roles = [];
$r = $conn->query("SELECT role_id, role_name FROM roles ORDER BY role_name");
if ($r) {
  while ($row = $r->fetch_assoc()) {
    $roles[] = $row;
  }
}

/* =========================
   3) Handle POST
   ========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

  $full_name  = clean($_POST["full_name"] ?? "");
  $username   = clean($_POST["username"] ?? "");
  $phone      = clean($_POST["phone"] ?? "");
  $email      = clean($_POST["email"] ?? "");
  $gender     = clean($_POST["gender"] ?? "");      // M / F / ""
  $photo_url  = clean($_POST["photo_url"] ?? "");   // optional
  $salary     = clean($_POST["salary_amount"] ?? "0");
  $hired_date = clean($_POST["hired_date"] ?? "");  // YYYY-MM-DD / empty
  $status     = clean($_POST["status"] ?? "ACTIVE"); // ACTIVE/INACTIVE
  $password   = (string)($_POST["password"] ?? "");
  $confirm    = (string)($_POST["confirm_password"] ?? "");

  // Required fields
  if ($full_name === "" || $username === "" || $password === "" || $confirm === "") {
    back("error", "Missing Fields", "Full Name, Username, Password and Confirm Password are required.");
  }

  // Full name 2 words min
  if (substr_count($full_name, " ") < 1) {
    back("error", "Invalid Name", "Full Name must be at least 2 words.");
  }

  if (strlen($username) < 3) {
    back("error", "Invalid Username", "Username must be at least 3 characters.");
  }

  if ($password !== $confirm) {
    back("error", "Password Mismatch", "Password and Confirm Password do not match.");
  }

  if (strlen($password) < 6) {
    back("error", "Weak Password", "Password must be at least 6 characters.");
  }

  // Gender check
  if ($gender !== "" && $gender !== "M" && $gender !== "F") {
    back("error", "Invalid Gender", "Gender must be M or F.");
  }

  // Status check
  if ($status !== "ACTIVE" && $status !== "INACTIVE") {
    $status = "ACTIVE";
  }

  // Salary numeric
  if ($salary === "") $salary = "0";
  if (!preg_match('/^\d+(\.\d{1,2})?$/', $salary)) {
    back("error", "Invalid Salary", "Salary must be a valid number (example: 200 or 200.50).");
  }

  // Email format
  if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    back("error", "Invalid Email", "Please enter a valid email address.");
  }

  // Hired date format
  if ($hired_date !== "" && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hired_date)) {
    back("error", "Invalid Date", "Hired date must be in YYYY-MM-DD format.");
  }

  // Username unique
  $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ? LIMIT 1");
  $stmt->bind_param("s", $username);
  $stmt->execute();
  $exists = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if ($exists) {
    back("error", "Username Taken", "This username already exists. Please choose another.");
  }

  // Get selected role_id from form (allow assigning roles via the form)
  $roleId = null;
  $roleName = null;
  if (isset($_POST['role_id'])) {
    $rid = (int)$_POST['role_id'];
    $stmt = $conn->prepare("SELECT role_id, role_name FROM roles WHERE role_id = ? LIMIT 1");
    $stmt->bind_param("i", $rid);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $row = $res->fetch_assoc()) {
      $roleId = (int)$row['role_id'];
      $roleName = $row['role_name'];
    }
    $stmt->close();
  }
  if (!$roleId) {
    back("error", "Role Missing", "Please select a valid role for the new user.");
  }

  // If creating the first admin (no admin exists yet), ensure the assigned role is ADMIN
  if (!$adminExists) {
    if ($roleName !== 'ADMIN') {
      $r = $conn->query("SELECT role_id FROM roles WHERE role_name='ADMIN' LIMIT 1");
      if ($r && $row = $r->fetch_assoc()) {
        $roleId = (int)$row['role_id'];
        $roleName = 'ADMIN';
      } else {
        back("error", "Role Missing", "ADMIN role not found. Please insert roles first.");
      }
    }
  }

  // Transaction
  $conn->begin_transaction();

  try {
    $hash = password_hash($password, PASSWORD_BCRYPT);

    // users
    $is_active = 1;
    $stmt = $conn->prepare("
      INSERT INTO users (username, password_hash, phone, email, is_active)
      VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("ssssi", $username, $hash, $phone, $email, $is_active);
    $stmt->execute();
    $newUserId = (int)$stmt->insert_id;
    $stmt->close();

    // user_roles
    $stmt = $conn->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $newUserId, $roleId);
    $stmt->execute();
    $stmt->close();

    // employees (ADMIN)
    $salaryDec = (float)$salary;

    if ($hired_date === "") {
      $stmt = $conn->prepare("
        INSERT INTO employees (user_id, full_name, phone, gender, photo_url, salary_amount, hired_date, status)
        VALUES (?, ?, ?, ?, ?, ?, NULL, ?)
      ");
      $stmt->bind_param("issssds", $newUserId, $full_name, $phone, $gender, $photo_url, $salaryDec, $status);
    } else {
      $stmt = $conn->prepare("
        INSERT INTO employees (user_id, full_name, phone, gender, photo_url, salary_amount, hired_date, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
      ");
      $stmt->bind_param("issssdss", $newUserId, $full_name, $phone, $gender, $photo_url, $salaryDec, $hired_date, $status);
    }
    $stmt->execute();
    $stmt->close();

    // activity_logs (optional)
    // If first admin created (no login), created_by will be NULL
    $createdBy = isset($_SESSION["user_id"]) ? (int)$_SESSION["user_id"] : null;

    if ($createdBy === null) {
      $stmt = $conn->prepare("
        INSERT INTO activity_logs (user_id, action, entity, entity_id, details)
        VALUES (NULL, 'CREATE_USER', 'users', ?, ?)
      ");
      $details = "Created FIRST ADMIN user: $username";
      $stmt->bind_param("is", $newUserId, $details);
    } else {
      $stmt = $conn->prepare("
        INSERT INTO activity_logs (user_id, action, entity, entity_id, details)
        VALUES (?, 'CREATE_USER', 'users', ?, ?)
      ");
      $details = "Created ADMIN user: $username";
      $stmt->bind_param("iis", $createdBy, $newUserId, $details);
    }
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    // After create:
    // - If this was first admin => go login
    // - If admin already exists => stay in registration
    if (!$adminExists) {
      setAlert("success", "Admin Created", "First admin created successfully. Please login now.");
      header("Location: login.php");
      exit;
    }

    setAlert("success", "Admin Created", "New ADMIN user has been created successfully.");
    header("Location: registration.php");
    exit;

  } catch (Throwable $e) {
    $conn->rollback();
    back("error", "Error", "Registration failed: " . $e->getMessage());
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Register Admin - HIGH School</title>

  <link rel="stylesheet" href="bootstrap.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <style>
    :root{
      --bg:#f5f9ff;
      --card:#ffffff;
      --border:#d7e2f0;
      --text:#0f172a;
      --muted:#64748b;
      --blue:#0b63ce;
      --blue2:#0a58ca;
      --dark:#0f2b5c;
      --green:#18a957;
    }
    body{
      background: var(--bg);
      font-family: Arial, sans-serif;
      color: var(--text);
      margin:0;
      min-height:100vh;
      display:flex;
      align-items:center;
      justify-content:center;
      padding: 20px;
    }
    .cardBox{
      width: 100%;
      max-width: 820px;
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 18px;
      box-shadow: 0 24px 70px rgba(2,6,23,.10);
      overflow:hidden;
    }
    .head{
      padding: 18px 20px;
      display:flex;
      align-items:center;
      justify-content:space-between;
      border-bottom: 1px solid var(--border);
      background: #f8fbff;
    }
    .head .title{
      font-weight: 900;
      color: var(--dark);
      font-size: 18px;
      margin:0;
      display:flex;
      align-items:center;
      gap:10px;
    }
    .content{ padding: 22px 20px 18px; }
    .note{
      background: rgba(11,99,206,.08);
      border: 1px solid rgba(11,99,206,.18);
      color: #0b3d91;
      border-radius: 14px;
      padding: 12px 14px;
      font-weight: 800;
      font-size: 13px;
      margin-bottom: 14px;
    }
    .form-control, .form-select{
      height: 54px;
      border-radius: 14px;
      border: 1px solid var(--border);
      padding-left: 14px;
      font-size: 15px;
      background: #fff;
    }
    .btn-main{
      height: 56px;
      border-radius: 14px;
      background: var(--blue);
      border:none;
      font-weight: 900;
      letter-spacing: .6px;
    }
    .btn-main:hover{ background: var(--blue2); }
    .footer{
      border-top: 1px solid var(--border);
      padding: 12px 18px;
      display:flex;
      justify-content:space-between;
      align-items:center;
      color: var(--muted);
      font-weight: 700;
      font-size: 13px;
      background: #fbfdff;
    }
    .badgeFirst{
      display:inline-block;
      padding: 6px 10px;
      border-radius: 999px;
      font-weight: 900;
      background: rgba(24,169,87,.12);
      color: var(--green);
      border: 1px solid rgba(24,169,87,.28);
      margin-left: 8px;
      font-size: 12px;
    }
  </style>
</head>
<body>

  <div class="cardBox">
    <div class="head">
      <p class="title">
        <i class="fa-solid fa-user-shield"></i> Register New ADMIN
        <?php if(!$adminExists): ?>
          <span class="badgeFirst">FIRST ADMIN</span>
        <?php endif; ?>
      </p>

      <div>
        <?php if($adminExists): ?>
         
         
          </a>
        <?php else: ?>
          <a href="login.php" class="btn btn-outline-primary">
            <i class="fa-solid fa-right-to-bracket"></i> Back to Login
          </a>
        <?php endif; ?>
      </div>
    </div>

    <div class="content">
      <div class="note">
        This form will create: <b>users</b> + <b>user_roles (selected role)</b> + <b>employees</b>.
        <?php if(!$adminExists): ?>
          <br>✅ No admin exists yet — you are creating the <b>first admin</b>.
        <?php endif; ?>
      </div>

      <form method="POST" autocomplete="off" novalidate>
        <div class="row g-3">

          <div class="col-md-6">
            <label class="form-label fw-bold">Full Name</label>
            <input type="text" name="full_name" class="form-control" placeholder="e.g. Ahmed Ali Hassan" required>
          </div>

          <div class="col-md-6">
            <label class="form-label fw-bold">Username</label>
            <input type="text" name="username" class="form-control" placeholder="e.g. admin2" required>
          </div>

          <div class="col-md-6">
            <label class="form-label fw-bold">Role</label>
            <select name="role_id" class="form-select" required>
              <option value="">Select role</option>
              <?php foreach($roles as $r): ?>
                <option value="<?= (int)$r['role_id'] ?>"><?= htmlspecialchars($r['role_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label fw-bold">Phone (optional)</label>
            <input type="text" name="phone" class="form-control" placeholder="e.g. 25261xxxxxxx">
          </div>

          <div class="col-md-6">
            <label class="form-label fw-bold">Email (optional)</label>
            <input type="email" name="email" class="form-control" placeholder="e.g. admin@school.com">
          </div>

          <div class="col-md-4">
            <label class="form-label fw-bold">Gender (optional)</label>
            <select name="gender" class="form-select">
              <option value="">Select</option>
              <option value="M">M</option>
              <option value="F">F</option>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label fw-bold">Salary Amount</label>
            <input type="text" name="salary_amount" class="form-control" value="0" placeholder="0">
          </div>

          <div class="col-md-4">
            <label class="form-label fw-bold">Hired Date (optional)</label>
            <input type="date" name="hired_date" class="form-control">
          </div>

          <div class="col-md-8">
            <label class="form-label fw-bold">Photo URL (optional)</label>
            <input type="text" name="photo_url" class="form-control" placeholder="e.g. image/admin.jpg or https://...">
          </div>

          <div class="col-md-4">
            <label class="form-label fw-bold">Status</label>
            <select name="status" class="form-select">
              <option value="ACTIVE" selected>ACTIVE</option>
              <option value="INACTIVE">INACTIVE</option>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label fw-bold">Password</label>
            <input type="password" name="password" class="form-control" placeholder="********" required>
          </div>

          <div class="col-md-6">
            <label class="form-label fw-bold">Confirm Password</label>
            <input type="password" name="confirm_password" class="form-control" placeholder="********" required>
          </div>

          <div class="col-12 mt-2">
            <button type="submit" class="btn btn-primary w-100 btn-main">
              <i class="fa-solid fa-user-plus"></i> CREATE ADMIN
            </button>
          </div>

        </div>
      </form>
    </div>

    <div class="footer">
      <div>Role: <b>Selectable via form</b></div>
      <div>© <?= date("Y") ?> HIGH School</div>
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
