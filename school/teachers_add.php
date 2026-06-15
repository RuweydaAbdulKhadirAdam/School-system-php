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
function jredirect(): void {
  header("Location: teachers_add.php");
  exit;
}
function roleIdForTeacher(mysqli $conn): int {
  $q = $conn->query("SELECT role_id FROM roles WHERE role_name='TEACHER' LIMIT 1");
  $r = $q ? $q->fetch_assoc() : null;
  return $r ? (int)$r["role_id"] : 0;
}

/* ==============
   Handle POST (CREATE)
   ============== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $fullName      = cleanSpaces($_POST["full_name"] ?? "");
  $username      = cleanSpaces($_POST["username"] ?? "");
  $password      = (string)($_POST["password"] ?? "");
  $phone         = cleanSpaces($_POST["phone"] ?? "");
  $email         = cleanSpaces($_POST["email"] ?? "");
  $gender        = cleanSpaces($_POST["gender"] ?? "");
  $photoUrl      = cleanSpaces($_POST["photo_url"] ?? "");
  $salaryAmount  = cleanSpaces($_POST["salary_amount"] ?? "0");
  $hiredDate     = cleanSpaces($_POST["hired_date"] ?? "");
  $status        = cleanSpaces($_POST["status"] ?? "ACTIVE");
  $specialization= cleanSpaces($_POST["specialization"] ?? "");
  $qualification = cleanSpaces($_POST["qualification"] ?? "");

  // subjects: comma separated
  $subjectsRaw = cleanSpaces($_POST["subjects"] ?? "");
  $subjectsArr = [];
  if ($subjectsRaw !== "") {
    $parts = array_map("trim", explode(",", $subjectsRaw));
    foreach ($parts as $p) {
      $p = cleanSpaces($p);
      if ($p !== "") $subjectsArr[] = $p;
    }
    $subjectsArr = array_values(array_unique($subjectsArr));
  }

  // validations
  if ($fullName === "" || $username === "" || $password === "") {
    setFlash("error", "Fariin", "Fadlan buuxi Full Name, Username, iyo Password.");
    jredirect();
  }
  if ($gender !== "" && !in_array($gender, ["M","F"], true)) {
    setFlash("error", "Fariin", "Gender ma saxna.");
    jredirect();
  }
  if ($status !== "" && !in_array($status, ["ACTIVE","INACTIVE"], true)) {
    setFlash("error", "Fariin", "Status ma saxna.");
    jredirect();
  }
  if ($salaryAmount === "") $salaryAmount = "0";
  if (!is_numeric($salaryAmount) || (float)$salaryAmount < 0) {
    setFlash("error", "Fariin", "Salary Amount waa inuu noqdaa number sax ah.");
    jredirect();
  }
  if ($hiredDate !== "" && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hiredDate)) {
    setFlash("error", "Fariin", "Hired Date format ma saxna (YYYY-MM-DD).");
    jredirect();
  }

  $teacherRoleId = roleIdForTeacher($conn);
  if ($teacherRoleId <= 0) {
    setFlash("error", "Fariin", "Role 'TEACHER' lama helin (roles table).");
    jredirect();
  }

  $passwordHash = password_hash($password, PASSWORD_BCRYPT);

  $conn->begin_transaction();
  try {
    // 1) create user
    $insU = $conn->prepare("
      INSERT INTO users (username, password_hash, phone, email, is_active)
      VALUES (?, ?, ?, ?, 1)
    ");
    if (!$insU) throw new Exception("Prepare users failed: ".$conn->error);
    $insU->bind_param("ssss", $username, $passwordHash, $phone, $email);
    if (!$insU->execute()) throw new Exception("Insert user failed: ".$insU->error);
    $userId = (int)$insU->insert_id;
    $insU->close();

    // 2) user_roles
    $insUR = $conn->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
    if (!$insUR) throw new Exception("Prepare user_roles failed: ".$conn->error);
    $insUR->bind_param("ii", $userId, $teacherRoleId);
    if (!$insUR->execute()) throw new Exception("Insert user_roles failed: ".$insUR->error);
    $insUR->close();

    // 3) employees
    $salary = round((float)$salaryAmount, 2);
    $genderN = ($gender === "") ? null : $gender;
    $hiredN  = ($hiredDate === "") ? null : $hiredDate;
    $photoN  = ($photoUrl === "") ? null : $photoUrl;

    $insE = $conn->prepare("
      INSERT INTO employees (user_id, full_name, phone, gender, photo_url, salary_amount, hired_date, status)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$insE) throw new Exception("Prepare employees failed: ".$conn->error);

    // bind with nullables carefully
    $insE->bind_param(
      "issssdss",
      $userId,
      $fullName,
      $phone,
      $genderN,
      $photoN,
      $salary,
      $hiredN,
      $status
    );
    if (!$insE->execute()) throw new Exception("Insert employee failed: ".$insE->error);
    $employeeId = (int)$insE->insert_id;
    $insE->close();

    // 4) teachers
    $specN = ($specialization === "") ? null : $specialization;
    $qualN = ($qualification === "") ? null : $qualification;

    $insT = $conn->prepare("
      INSERT INTO teachers (employee_id, specialization, qualification)
      VALUES (?, ?, ?)
    ");
    if (!$insT) throw new Exception("Prepare teachers failed: ".$conn->error);
    $insT->bind_param("iss", $employeeId, $specN, $qualN);
    if (!$insT->execute()) throw new Exception("Insert teacher failed: ".$insT->error);
    $teacherId = (int)$insT->insert_id;
    $insT->close();

    // 5) teacher_subjects
    if (count($subjectsArr) > 0) {
      $insS = $conn->prepare("INSERT INTO teacher_subjects (teacher_id, subject_name) VALUES (?, ?)");
      if (!$insS) throw new Exception("Prepare teacher_subjects failed: ".$conn->error);
      foreach ($subjectsArr as $sub) {
        $insS->bind_param("is", $teacherId, $sub);
        if (!$insS->execute()) throw new Exception("Insert subject failed: ".$insS->error);
      }
      $insS->close();
    }

    $conn->commit();
    setFlash("success", "Guul", "Macallinka waa la diiwaangeliyey si guul ah.");
    header("Location: teachers.php");
    exit;

  } catch (Throwable $e) {
    $conn->rollback();
    setFlash("error", "Fariin", "DB Error: ".$e->getMessage());
    jredirect();
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Add Teacher</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="stylesheet" href="bootstrap.css" />
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    :root{ --bg:#f4f7ff; --card:#fff; --text:#0f172a; --muted:#64748b; --border:#dbe6f7; --shadow:0 10px 30px rgba(2,6,23,.08); }
    body{ background:var(--bg); color:var(--text); }
    .cardx{ background:var(--card); border:1px solid var(--border); border-radius:18px; padding:18px; box-shadow:var(--shadow); }
    label{ font-weight:900; }
    .muted{ color:var(--muted); font-weight:700; }
  </style>
</head>
<body class="p-4">

<?php if ($flash): ?>
<script>
  Swal.fire({
    icon: <?= json_encode($flash["type"]) ?>,
    title: <?= json_encode($flash["title"]) ?>,
    text: <?= json_encode($flash["msg"]) ?>,
    confirmButtonText: "Haye",
    confirmButtonColor: "#2563eb",
    width: 650
  });
</script>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h3 class="mb-1">Register Teacher</h3>
    <div class="muted">Waxaad halkan ka diiwaangelinaysaa macallin (users → employees → teachers) + subjects.</div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary" href="teachers.php">⬅ Back to List</a>
  </div>
</div>

<div class="cardx">
  <form method="POST" autocomplete="off">
    <div class="row g-3">

      <div class="col-md-6">
        <label>Full Name *</label>
        <input class="form-control" name="full_name" required placeholder="e.g. Ahmed Ali Yusuf">
      </div>

      <div class="col-md-3">
        <label>Username *</label>
        <input class="form-control" name="username" required placeholder="e.g. teacher_ahmed">
      </div>

      <div class="col-md-3">
        <label>Password *</label>
        <input type="password" class="form-control" name="password" required placeholder="********">
      </div>

      <div class="col-md-4">
        <label>Phone</label>
        <input class="form-control" name="phone" placeholder="+25261xxxxxxx">
      </div>

      <div class="col-md-4">
        <label>Email</label>
        <input type="email" class="form-control" name="email" placeholder="example@mail.com">
      </div>

      <div class="col-md-2">
        <label>Gender</label>
        <select class="form-control" name="gender">
          <option value="">--</option>
          <option value="M">Male</option>
          <option value="F">Female</option>
        </select>
      </div>

      <div class="col-md-2">
        <label>Status</label>
        <select class="form-control" name="status">
          <option value="ACTIVE">ACTIVE</option>
          <option value="INACTIVE">INACTIVE</option>
        </select>
      </div>

      <div class="col-md-6">
        <label>Photo URL</label>
        <input class="form-control" name="photo_url" placeholder="https://.../photo.jpg">
      </div>

      <div class="col-md-3">
        <label>Salary Amount</label>
        <input class="form-control" name="salary_amount" type="number" step="0.01" min="0" value="0">
      </div>

      <div class="col-md-3">
        <label>Hired Date</label>
        <input class="form-control" name="hired_date" type="date">
      </div>

      <div class="col-md-6">
        <label>Specialization</label>
        <input class="form-control" name="specialization" placeholder="e.g. Mathematics">
      </div>

      <div class="col-md-6">
        <label>Qualification</label>
        <input class="form-control" name="qualification" placeholder="e.g. Bachelor of Education">
      </div>

      <div class="col-12">
        <label>Subjects (comma separated)</label>
        <input class="form-control" name="subjects" placeholder="Math, English, Science">
        <div class="muted mt-1" style="font-size:12px;">Tusaale: Math, English, Science (kala saar comma ,)</div>
      </div>

      <div class="col-12 d-flex gap-2">
        <button class="btn btn-primary" type="submit">Save Teacher</button>
        <a class="btn btn-outline-secondary" href="teachers.php">Cancel</a>
      </div>

    </div>
  </form>
</div>

</body>
</html>
