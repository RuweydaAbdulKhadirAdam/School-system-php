<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . "/conncation.php";

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

function cleanSpaces(?string $v): string {
  $v = trim((string)$v);
  $v = preg_replace('/\s+/', ' ', $v);
  return $v ?? "";
}
function nullIfEmpty(?string $v): ?string {
  $v = trim((string)$v);
  return $v === "" ? null : $v;
}
function minThreeWords(string $v): bool {
  $parts = array_values(array_filter(explode(" ", trim($v)), fn($x)=>$x!==""));
  return count($parts) >= 3;
}
function isValidSomaliPhone10(string $v): bool {
  $v = preg_replace('/\s+/', '', trim($v)) ?? $v;
  return (bool)preg_match('/^0\d{9}$/', $v);
}
function validateDobAge(?string $dob): array {
  $dob = trim((string)$dob);
  if ($dob === "") return [true, null]; // optional
  $dt = DateTime::createFromFormat("Y-m-d", $dob);
  if (!$dt || $dt->format("Y-m-d") !== $dob) return [false, "Taariikhda dhalashada (DOB) ma saxna. Fadlan dooro date sax ah."];

  $today = new DateTime("today");
  if ($dt > $today) return [false, "DOB ma noqon karto mustaqbal (future)."];

  $age = (int)$dt->diff($today)->y;
  if ($age < 8)  return [false, "Ardayga da'diisu waa inay ka weyn tahay ama la mid tahay 8 sano."];
  if ($age > 50) return [false, "Da'da la oggol yahay waa ilaa 50 sano."];

  return [true, null];
}

function hasColumn(mysqli $conn, string $table, string $column): bool {
  $dbRow = $conn->query("SELECT DATABASE() AS db")->fetch_assoc();
  $db = $dbRow["db"] ?? "";
  if ($db === "") return false;

  $st = $conn->prepare("
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?
    LIMIT 1
  ");
  $st->bind_param("sss", $db, $table, $column);
  $st->execute();
  $ok = (bool)$st->get_result()->fetch_assoc();
  $st->close();
  return $ok;
}

function generateAdmissionNo(mysqli $conn): string {
  $year = (int)date("Y");

  $stmt = $conn->prepare("
    INSERT INTO admission_sequences (year, last_no)
    VALUES (?, 0)
    ON DUPLICATE KEY UPDATE year = year
  ");
  $stmt->bind_param("i", $year);
  $stmt->execute();
  $stmt->close();

  $stmt = $conn->prepare("SELECT last_no FROM admission_sequences WHERE year=? FOR UPDATE");
  $stmt->bind_param("i", $year);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  $lastNo = $row ? (int)$row["last_no"] : 0;
  $nextNo = $lastNo + 1;

  $stmt = $conn->prepare("UPDATE admission_sequences SET last_no=? WHERE year=?");
  $stmt->bind_param("ii", $nextNo, $year);
  $stmt->execute();
  $stmt->close();

  return "ADM-" . $year . "-" . str_pad((string)$nextNo, 4, "0", STR_PAD_LEFT);
}

function yearExists(mysqli $conn, int $yearId): bool {
  $st = $conn->prepare("SELECT year_id FROM academic_years WHERE year_id=? LIMIT 1");
  $st->bind_param("i", $yearId);
  $st->execute();
  $ok = (bool)$st->get_result()->fetch_assoc();
  $st->close();
  return $ok;
}
function sectionExists(mysqli $conn, int $sectionId): bool {
  $st = $conn->prepare("SELECT section_id FROM sections WHERE section_id=? LIMIT 1");
  $st->bind_param("i", $sectionId);
  $st->execute();
  $ok = (bool)$st->get_result()->fetch_assoc();
  $st->close();
  return $ok;
}

/** Capacity check */
function assertSectionHasSpace(mysqli $conn, int $yearId, int $sectionId, ?int $excludeStudentId = null): void {
  if (!hasColumn($conn, "sections", "capacity_max")) return;

  $st = $conn->prepare("SELECT capacity_max FROM sections WHERE section_id=? LIMIT 1");
  $st->bind_param("i", $sectionId);
  $st->execute();
  $sec = $st->get_result()->fetch_assoc();
  $st->close();

  if (!$sec) throw new Exception("Class/Section lama helin (DB).");

  $cap = (int)($sec["capacity_max"] ?? 0);
  if ($cap <= 0) return;

  if ($excludeStudentId) {
    $st = $conn->prepare("
      SELECT COUNT(*) AS c
      FROM enrollments
      WHERE year_id=? AND section_id=? AND status='ENROLLED' AND student_id <> ?
    ");
    $st->bind_param("iii", $yearId, $sectionId, $excludeStudentId);
  } else {
    $st = $conn->prepare("
      SELECT COUNT(*) AS c
      FROM enrollments
      WHERE year_id=? AND section_id=? AND status='ENROLLED'
    ");
    $st->bind_param("ii", $yearId, $sectionId);
  }
  $st->execute();
  $cnt = (int)($st->get_result()->fetch_assoc()["c"] ?? 0);
  $st->close();

  if ($cnt >= $cap) throw new Exception("Class-kaan wuu buuxaa. Fadlan dooro class kale (Capacity: {$cap}).");
}

/**
 * ✅ FILE UPLOAD (Student Photo)
 * - uses: /uploads/students
 * - returns relative path to save in DB: uploads/students/xxx.jpg
 */
function uploadStudentPhoto(array $file): ?string {
  if (!isset($file["error"]) || $file["error"] === UPLOAD_ERR_NO_FILE) return null;

  if ($file["error"] !== UPLOAD_ERR_OK) {
    throw new Exception("Sawirka upload-giisa wuu fashilmay. Fadlan mar kale isku day.");
  }

  $max = 2 * 1024 * 1024;
  if ((int)$file["size"] > $max) throw new Exception("Sawirka wuu weyn yahay. Max waa 2MB.");

  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = $finfo->file($file["tmp_name"]) ?: "";

  $allowed = [
    "image/jpeg" => "jpg",
    "image/png"  => "png",
    "image/webp" => "webp"
  ];
  if (!isset($allowed[$mime])) throw new Exception("Nooca sawirka lama oggola. Kaliya JPG/PNG/WEBP.");

  $dir = __DIR__ . "/uploads/students";
  if (!is_dir($dir)) {
    if (!mkdir($dir, 0775, true)) {
      throw new Exception("Folder-ka uploads/students lama abuuri karo. Hubi permission-ka server-ka.");
    }
  }

  $ext = $allowed[$mime];
  $name = "std_" . date("Ymd_His") . "_" . bin2hex(random_bytes(6)) . "." . $ext;

  $destAbs = $dir . "/" . $name;
  if (!move_uploaded_file($file["tmp_name"], $destAbs)) {
    throw new Exception("Ma kaydin karo sawirka. Hubi folder permission.");
  }

  return "uploads/students/" . $name;
}

/* =====================
   MODE: ADD or UPDATE
   - update: students_add.php?id=123
   ===================== */
$editId = (int)($_GET["id"] ?? 0);
$isEdit = ($editId > 0);

/* =====================
   FLASH + OLD FORM
   ===================== */
$flash = $_SESSION["flash"] ?? null;
unset($_SESSION["flash"]);

$old = $_SESSION["old_form"] ?? [];
unset($_SESSION["old_form"]);

/* =====================
   LOAD DROPDOWNS
   ===================== */
$years = [];
$res = $conn->query("SELECT year_id, year_name, is_current FROM academic_years ORDER BY is_current DESC, start_date DESC");
if ($res) while ($r = $res->fetch_assoc()) $years[] = $r;

$sections = [];
$res = $conn->query("
  SELECT s.section_id, s.section_name, g.grade_name
  FROM sections s
  JOIN grades g ON g.grade_id = s.grade_id
  ORDER BY g.sort_order ASC, s.section_name ASC
");
if ($res) while ($r = $res->fetch_assoc()) $sections[] = $r;

$methods = [];
$res = $conn->query("SELECT method_id, method_name FROM payment_methods ORDER BY method_name ASC");
if ($res) while ($r = $res->fetch_assoc()) $methods[] = $r;

$tuitionFeeTypeId = null;
$res = $conn->query("SELECT fee_type_id FROM fee_types WHERE fee_type_name='TUITION' LIMIT 1");
if ($res) {
  $row = $res->fetch_assoc();
  $tuitionFeeTypeId = $row ? (int)$row["fee_type_id"] : null;
}

/* =====================
   LOAD EXISTING STUDENT (for edit)
   ===================== */
$current = null;
$currentEnroll = null;
$currentPhoto = "";

if ($isEdit) {
  $st = $conn->prepare("
    SELECT student_id, admission_no, first_name, middle_name, last_name, mother_full_name,
           gender, dob, nationality, place_of_birth, profile_photo_url,
           phone, address, emergency_contact_name, emergency_contact_phone
    FROM students
    WHERE student_id=?
    LIMIT 1
  ");
  $st->bind_param("i", $editId);
  $st->execute();
  $current = $st->get_result()->fetch_assoc();
  $st->close();

  if (!$current) {
    $_SESSION["flash"] = ["type"=>"error", "msg"=>"Ardayga lama helin (ID: {$editId})."];
    header("Location: students.php");
    exit;
  }
  $currentPhoto = (string)($current["profile_photo_url"] ?? "");

  // pick current year enrollment if exists, else last enrolled enrollment
  $curYearId = 0;
  $r = $conn->query("SELECT year_id FROM academic_years WHERE is_current=1 ORDER BY start_date DESC LIMIT 1")->fetch_assoc();
  if ($r && (int)$r["year_id"] > 0) $curYearId = (int)$r["year_id"];

  if ($curYearId > 0) {
    $st = $conn->prepare("
      SELECT enrollment_id, year_id, section_id, roll_no
      FROM enrollments
      WHERE student_id=? AND year_id=? AND status='ENROLLED'
      ORDER BY enrollment_id DESC
      LIMIT 1
    ");
    $st->bind_param("ii", $editId, $curYearId);
    $st->execute();
    $currentEnroll = $st->get_result()->fetch_assoc();
    $st->close();
  }
  if (!$currentEnroll) {
    $st = $conn->prepare("
      SELECT enrollment_id, year_id, section_id, roll_no
      FROM enrollments
      WHERE student_id=? AND status='ENROLLED'
      ORDER BY enrollment_id DESC
      LIMIT 1
    ");
    $st->bind_param("i", $editId);
    $st->execute();
    $currentEnroll = $st->get_result()->fetch_assoc();
    $st->close();
  }

  // if no old_form, prefill from DB
  if (empty($old)) {
    $old = [
      "first_name" => $current["first_name"] ?? "",
      "middle_name" => $current["middle_name"] ?? "",
      "last_name" => $current["last_name"] ?? "",
      "mother_full_name" => $current["mother_full_name"] ?? "",
      "gender" => $current["gender"] ?? "",
      "dob" => $current["dob"] ?? "",
      "nationality" => $current["nationality"] ?? "",
      "place_of_birth" => $current["place_of_birth"] ?? "",
      "phone" => $current["phone"] ?? "",
      "address" => $current["address"] ?? "",
      "emergency_contact_name" => $current["emergency_contact_name"] ?? "",
      "emergency_contact_phone" => $current["emergency_contact_phone"] ?? "",
      "year_id" => $currentEnroll["year_id"] ?? "",
      "section_id" => $currentEnroll["section_id"] ?? "",
      "roll_no" => $currentEnroll["roll_no"] ?? "",
      // finance fields left empty by default
      "tuition_total" => "",
      "paid_amount" => "",
      "method_id" => "",
      "reference_no" => "",
    ];
  }
}

/* =====================
   HANDLE POST (ADD/UPDATE)
   ===================== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

  // save old values
  $_SESSION["old_form"] = [
    "first_name" => $_POST["first_name"] ?? "",
    "middle_name" => $_POST["middle_name"] ?? "",
    "last_name" => $_POST["last_name"] ?? "",
    "mother_full_name" => $_POST["mother_full_name"] ?? "",
    "gender" => $_POST["gender"] ?? "",
    "dob" => $_POST["dob"] ?? "",
    "nationality" => $_POST["nationality"] ?? "",
    "place_of_birth" => $_POST["place_of_birth"] ?? "",
    "phone" => $_POST["phone"] ?? "",
    "address" => $_POST["address"] ?? "",
    "emergency_contact_name" => $_POST["emergency_contact_name"] ?? "",
    "emergency_contact_phone" => $_POST["emergency_contact_phone"] ?? "",
    "year_id" => $_POST["year_id"] ?? "",
    "section_id" => $_POST["section_id"] ?? "",
    "roll_no" => $_POST["roll_no"] ?? "",
    "tuition_total" => $_POST["tuition_total"] ?? "",
    "paid_amount" => $_POST["paid_amount"] ?? "",
    "method_id" => $_POST["method_id"] ?? "",
    "reference_no" => $_POST["reference_no"] ?? "",
  ];

  $first  = cleanSpaces($_POST["first_name"] ?? "");
  $middle = cleanSpaces($_POST["middle_name"] ?? "");
  $last   = cleanSpaces($_POST["last_name"] ?? "");
  $mother = cleanSpaces($_POST["mother_full_name"] ?? "");

  $yearId    = (int)($_POST["year_id"] ?? 0);
  $sectionId = (int)($_POST["section_id"] ?? 0);

  $gender      = cleanSpaces($_POST["gender"] ?? "");
  $dob         = cleanSpaces($_POST["dob"] ?? "");
  $nationality = cleanSpaces($_POST["nationality"] ?? "");
  $pob         = cleanSpaces($_POST["place_of_birth"] ?? "");

  $phone    = cleanSpaces($_POST["phone"] ?? "");
  $address  = cleanSpaces($_POST["address"] ?? "");
  $emgName  = cleanSpaces($_POST["emergency_contact_name"] ?? "");
  $emgPhone = cleanSpaces($_POST["emergency_contact_phone"] ?? "");
  $rollNo   = cleanSpaces($_POST["roll_no"] ?? "");

  $tuitionTotal = cleanSpaces($_POST["tuition_total"] ?? "");
  $paidAmount   = cleanSpaces($_POST["paid_amount"] ?? "");
  $methodId     = (int)($_POST["method_id"] ?? 0);
  $referenceNo  = cleanSpaces($_POST["reference_no"] ?? "");

  // validations
  if ($first==="" || $middle==="" || $last==="" || $mother==="") {
    $_SESSION["flash"] = ["type"=>"error", "msg"=>"Qaybta (Student Information): Fadlan buuxi First/Middle/Last & Mother Full Name."];
    header("Location: " . ($isEdit ? "students_add.php?id=".$editId : "students_add.php")); exit;
  }
  if (!minThreeWords($mother)) {
    $_SESSION["flash"] = ["type"=>"error", "msg"=>"Mother Full Name waa inuu noqdaa ugu yaraan 3 eray (tusaale: Amina Ali Hassan)."];
    header("Location: " . ($isEdit ? "students_add.php?id=".$editId : "students_add.php")); exit;
  }
  if ($gender !== "" && !in_array($gender, ["M","F"], true)) {
    $_SESSION["flash"] = ["type"=>"error", "msg"=>"Gender ma saxna. Dooro Male ama Female."];
    header("Location: " . ($isEdit ? "students_add.php?id=".$editId : "students_add.php")); exit;
  }

  [$dobOk, $dobMsg] = validateDobAge($dob);
  if (!$dobOk) {
    $_SESSION["flash"] = ["type"=>"error", "msg"=>"Qaybta (DOB): ".$dobMsg];
    header("Location: " . ($isEdit ? "students_add.php?id=".$editId : "students_add.php")); exit;
  }

  if ($phone !== "" && !isValidSomaliPhone10($phone)) {
    $_SESSION["flash"] = ["type"=>"error", "msg"=>"Mobile Number ma saxna. Geli 10 digit oo sida: 0615247548."];
    header("Location: " . ($isEdit ? "students_add.php?id=".$editId : "students_add.php")); exit;
  }
  if ($emgPhone !== "" && !isValidSomaliPhone10($emgPhone)) {
    $_SESSION["flash"] = ["type"=>"error", "msg"=>"Emergency Phone ma saxna. Geli 10 digit oo sida: 0615247548."];
    header("Location: " . ($isEdit ? "students_add.php?id=".$editId : "students_add.php")); exit;
  }

  if ($yearId <= 0 || $sectionId <= 0) {
    $_SESSION["flash"] = ["type"=>"error", "msg"=>"Qaybta (Academic): Academic Year iyo Class waa qasab (Required)."];
    header("Location: " . ($isEdit ? "students_add.php?id=".$editId : "students_add.php")); exit;
  }
  if (!yearExists($conn, $yearId)) {
    $_SESSION["flash"] = ["type"=>"error", "msg"=>"Academic Year-kaan DB kuma jiro. Fadlan dooro mid sax ah."];
    header("Location: " . ($isEdit ? "students_add.php?id=".$editId : "students_add.php")); exit;
  }
  if (!sectionExists($conn, $sectionId)) {
    $_SESSION["flash"] = ["type"=>"error", "msg"=>"Class/Section-kan DB kuma jiro. Fadlan dooro class sax ah."];
    header("Location: " . ($isEdit ? "students_add.php?id=".$editId : "students_add.php")); exit;
  }

  // finance
  $tuitionTotalNum = null;
  if ($tuitionTotal !== "") {
    if (!is_numeric($tuitionTotal) || (float)$tuitionTotal < 0) {
      $_SESSION["flash"] = ["type"=>"error", "msg"=>"Tuition Total waa inuu noqdaa number sax ah (>= 0)."];
      header("Location: " . ($isEdit ? "students_add.php?id=".$editId : "students_add.php")); exit;
    }
    $tuitionTotalNum = round((float)$tuitionTotal, 2);
  }

  $paidAmountNum = 0.00;
  if ($paidAmount !== "") {
    if (!is_numeric($paidAmount) || (float)$paidAmount < 0) {
      $_SESSION["flash"] = ["type"=>"error", "msg"=>"Paid Amount waa inuu noqdaa number sax ah (>= 0)."];
      header("Location: " . ($isEdit ? "students_add.php?id=".$editId : "students_add.php")); exit;
    }
    $paidAmountNum = round((float)$paidAmount, 2);
  }

  if ($paidAmountNum > 0 && $methodId <= 0) {
    $_SESSION["flash"] = ["type"=>"error", "msg"=>"Haddii Paid Amount > 0, Payment Method waa qasab."];
    header("Location: " . ($isEdit ? "students_add.php?id=".$editId : "students_add.php")); exit;
  }

  if ($tuitionTotalNum !== null && $tuitionFeeTypeId === null) {
    $_SESSION["flash"] = ["type"=>"error", "msg"=>"Fee type 'TUITION' lama helin (fee_types)."];
    header("Location: " . ($isEdit ? "students_add.php?id=".$editId : "students_add.php")); exit;
  }

  $g         = nullIfEmpty($gender);
  $d         = nullIfEmpty($dob);
  $natN      = nullIfEmpty($nationality);
  $pobN      = nullIfEmpty($pob);

  $phoneN    = nullIfEmpty(preg_replace('/\s+/', '', $phone) ?? $phone);
  $addrN     = nullIfEmpty($address);
  $emgNameN  = nullIfEmpty($emgName);
  $emgPhoneN = nullIfEmpty(preg_replace('/\s+/', '', $emgPhone) ?? $emgPhone);

  $rollNoN   = nullIfEmpty($rollNo);
  $refN      = nullIfEmpty($referenceNo);

  $conn->begin_transaction();

  try {
    // capacity check (exclude current student when editing)
    assertSectionHasSpace($conn, $yearId, $sectionId, $isEdit ? $editId : null);

    // upload new photo (optional)
    $newPhoto = uploadStudentPhoto($_FILES["profile_photo"] ?? []);

    if (!$isEdit) {
      // ADD
      $admissionNo = generateAdmissionNo($conn);

      $stmt = $conn->prepare("
        INSERT INTO students
          (admission_no, first_name, middle_name, last_name, mother_full_name, gender, dob,
           nationality, place_of_birth, profile_photo_url,
           phone, address, emergency_contact_name, emergency_contact_phone)
        VALUES
          (?, ?, ?, ?, ?, ?, ?,
           ?, ?, ?,
           ?, ?, ?, ?)
      ");
      $stmt->bind_param(
        "ssssssssssssss",
        $admissionNo, $first, $middle, $last, $mother, $g, $d,
        $natN, $pobN, $newPhoto,
        $phoneN, $addrN, $emgNameN, $emgPhoneN
      );
      $stmt->execute();
      $studentId = (int)$stmt->insert_id;
      $stmt->close();

      $stmt = $conn->prepare("
        INSERT INTO enrollments (student_id, year_id, section_id, roll_no, status)
        VALUES (?, ?, ?, ?, 'ENROLLED')
      ");
      $stmt->bind_param("iiis", $studentId, $yearId, $sectionId, $rollNoN);
      $stmt->execute();
      $enrollmentId = (int)$stmt->insert_id;
      $stmt->close();

      // optional finance
      if ($tuitionTotalNum !== null) {
        $invStatus = "ISSUED";
        if ($paidAmountNum >= $tuitionTotalNum && $tuitionTotalNum > 0) $invStatus = "PAID";
        else if ($paidAmountNum > 0 && $paidAmountNum < $tuitionTotalNum) $invStatus = "PARTIAL";

        $invoiceNo = "INV-" . $enrollmentId . "-" . date("YmdHis");

        $stmt = $conn->prepare("
          INSERT INTO student_invoices
            (enrollment_id, invoice_no, issue_date, due_date, status, created_by)
          VALUES
            (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), ?, ?)
        ");
        $createdBy = (int)($_SESSION["user_id"] ?? 0);
        $stmt->bind_param("issi", $enrollmentId, $invoiceNo, $invStatus, $createdBy);
        $stmt->execute();
        $invoiceId = (int)$stmt->insert_id;
        $stmt->close();

        $stmt = $conn->prepare("
          INSERT INTO invoice_items (invoice_id, fee_type_id, description, amount)
          VALUES (?, ?, 'Tuition Fee', ?)
        ");
        $stmt->bind_param("iid", $invoiceId, $tuitionFeeTypeId, $tuitionTotalNum);
        $stmt->execute();
        $stmt->close();

        if ($paidAmountNum > 0) {
          $stmt = $conn->prepare("
            INSERT INTO payments (invoice_id, method_id, amount, reference_no, received_by)
            VALUES (?, ?, ?, ?, ?)
          ");
          $receivedBy = (int)($_SESSION["user_id"] ?? 0);
          $stmt->bind_param("iidsi", $invoiceId, $methodId, $paidAmountNum, $refN, $receivedBy);
          $stmt->execute();
          $stmt->close();
        }
      }

      $conn->commit();
      unset($_SESSION["old_form"]);
      $_SESSION["flash"] = ["type"=>"success", "msg"=>"Ardayga waa la diiwaangeliyey ✅ (Admission No: {$admissionNo})."];
      header("Location: students.php");
      exit;

    } else {
      // UPDATE
      $studentId = $editId;

      // read existing photo
      $st = $conn->prepare("SELECT profile_photo_url FROM students WHERE student_id=? LIMIT 1");
      $st->bind_param("i", $studentId);
      $st->execute();
      $row = $st->get_result()->fetch_assoc();
      $st->close();
      $oldPhoto = $row ? (string)($row["profile_photo_url"] ?? "") : "";

      $finalPhoto = $newPhoto !== null ? $newPhoto : $oldPhoto;

      $stmt = $conn->prepare("
        UPDATE students
        SET first_name=?, middle_name=?, last_name=?, mother_full_name=?,
            gender=?, dob=?, nationality=?, place_of_birth=?,
            profile_photo_url=?,
            phone=?, address=?, emergency_contact_name=?, emergency_contact_phone=?
        WHERE student_id=?
        LIMIT 1
      ");
      $stmt->bind_param(
        "sssssssssssssi",
        $first, $middle, $last, $mother,
        $g, $d, $natN, $pobN,
        $finalPhoto,
        $phoneN, $addrN, $emgNameN, $emgPhoneN,
        $studentId
      );
      $stmt->execute();
      $stmt->close();

      // enrollment: ensure only ONE enrollment per (student_id, year_id) by using UPDATE/INSERT
      $st = $conn->prepare("
        SELECT enrollment_id
        FROM enrollments
        WHERE student_id=? AND year_id=? AND status='ENROLLED'
        ORDER BY enrollment_id DESC
        LIMIT 1
      ");
      $st->bind_param("ii", $studentId, $yearId);
      $st->execute();
      $enr = $st->get_result()->fetch_assoc();
      $st->close();

      if ($enr) {
        $enrollmentId = (int)$enr["enrollment_id"];
        $st = $conn->prepare("
          UPDATE enrollments
          SET section_id=?, roll_no=?, status='ENROLLED'
          WHERE enrollment_id=?
          LIMIT 1
        ");
        $st->bind_param("isi", $sectionId, $rollNoN, $enrollmentId);
        $st->execute();
        $st->close();
      } else {
        // insert new year enrollment for this student
        $st = $conn->prepare("
          INSERT INTO enrollments (student_id, year_id, section_id, roll_no, status)
          VALUES (?, ?, ?, ?, 'ENROLLED')
        ");
        $st->bind_param("iiis", $studentId, $yearId, $sectionId, $rollNoN);
        $st->execute();
        $st->close();
      }

      // optional finance when updating (creates new invoice if tuition_total filled)
      if ($tuitionTotalNum !== null) {
        // find enrollment_id for that year
        $st = $conn->prepare("
          SELECT enrollment_id FROM enrollments
          WHERE student_id=? AND year_id=? AND status='ENROLLED'
          ORDER BY enrollment_id DESC
          LIMIT 1
        ");
        $st->bind_param("ii", $studentId, $yearId);
        $st->execute();
        $enr2 = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$enr2) throw new Exception("Enrollment lama helin si invoice loo sameeyo.");

        $enrollmentId = (int)$enr2["enrollment_id"];
        $invStatus = "ISSUED";
        if ($paidAmountNum >= $tuitionTotalNum && $tuitionTotalNum > 0) $invStatus = "PAID";
        else if ($paidAmountNum > 0 && $paidAmountNum < $tuitionTotalNum) $invStatus = "PARTIAL";

        $invoiceNo = "INV-" . $enrollmentId . "-" . date("YmdHis");

        $stmt = $conn->prepare("
          INSERT INTO student_invoices
            (enrollment_id, invoice_no, issue_date, due_date, status, created_by)
          VALUES
            (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), ?, ?)
        ");
        $createdBy = (int)($_SESSION["user_id"] ?? 0);
        $stmt->bind_param("issi", $enrollmentId, $invoiceNo, $invStatus, $createdBy);
        $stmt->execute();
        $invoiceId = (int)$stmt->insert_id;
        $stmt->close();

        $stmt = $conn->prepare("
          INSERT INTO invoice_items (invoice_id, fee_type_id, description, amount)
          VALUES (?, ?, 'Tuition Fee', ?)
        ");
        $stmt->bind_param("iid", $invoiceId, $tuitionFeeTypeId, $tuitionTotalNum);
        $stmt->execute();
        $stmt->close();

        if ($paidAmountNum > 0) {
          $stmt = $conn->prepare("
            INSERT INTO payments (invoice_id, method_id, amount, reference_no, received_by)
            VALUES (?, ?, ?, ?, ?)
          ");
          $receivedBy = (int)($_SESSION["user_id"] ?? 0);
          $stmt->bind_param("iidsi", $invoiceId, $methodId, $paidAmountNum, $refN, $receivedBy);
          $stmt->execute();
          $stmt->close();
        }
      }

      $conn->commit();
      unset($_SESSION["old_form"]);
      $_SESSION["flash"] = ["type"=>"success", "msg"=>"Ardayga waa la update-gareeyey ✅"];
      header("Location: students.php");
      exit;
    }

  } catch (mysqli_sql_exception $e) {
    $conn->rollback();
    if ((int)$e->getCode() === 1062) {
      $_SESSION["flash"] = ["type"=>"error", "msg"=>"Ardaygan sanadkan hore ayaa loogu daray class-kan. Kaliya EDIT samee."];
      header("Location: " . ($isEdit ? "students_add.php?id=".$editId : "students_add.php")); exit;
    }
    $_SESSION["flash"] = ["type"=>"error", "msg"=>"Qalad DB: " . $e->getMessage()];
    header("Location: " . ($isEdit ? "students_add.php?id=".$editId : "students_add.php")); exit;
  } catch (Throwable $e) {
    $conn->rollback();
    $_SESSION["flash"] = ["type"=>"error", "msg"=>$e->getMessage()];
    header("Location: " . ($isEdit ? "students_add.php?id=".$editId : "students_add.php")); exit;
  }
}

/* show photo preview on edit */
$photoPreview = "";
if ($isEdit && isset($current["profile_photo_url"])) {
  $photoPreview = trim((string)$current["profile_photo_url"]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title><?= $isEdit ? "Update Student" : "Admission Form" ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <link rel="stylesheet" href="bootstrap.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <style>
    :root{
      --bg:#f4f7ff; --card:#ffffff; --text:#0f172a; --muted:#64748b;
      --border:#e6edf8; --purple:#6d5efc; --shadow:0 16px 45px rgba(2,6,23,.08);
      --radius:18px;
    }
    body{ background:var(--bg); color:var(--text); }
    .wrap{ max-width:1180px; margin:18px auto; padding:0 14px 34px; }
    .crumbbar{
      background:var(--card); border:1px solid var(--border); border-radius:var(--radius);
      box-shadow:var(--shadow); padding:12px 14px; display:flex; align-items:center;
      justify-content:space-between; gap:12px; flex-wrap:wrap;
    }
    .crumb{ display:flex; align-items:center; gap:10px; font-weight:1000; color:#334155; }
    .crumb small{ color:var(--muted); font-weight:900; }
    .topBtns{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
    .btnPill{ border-radius:999px !important; font-weight:1000; padding:10px 14px; }

    .heroTitle{ text-align:center; margin:18px 0 8px; font-weight:1000; font-size:44px; letter-spacing:.5px; }
    .legend{ display:flex; justify-content:center; gap:18px; color:#475569; font-weight:1000; margin-bottom:18px; align-items:center; }
    .lgBar{ width:34px; height:8px; border-radius:999px; display:inline-block; background:#94a3b8; }
    .lgReq{ background:var(--purple); } .lgOpt{ background:#94a3b8; }

    .section{
      background:var(--card); border:1px solid var(--border); border-radius:var(--radius);
      box-shadow:var(--shadow); padding:18px; margin-top:14px;
    }
    .secHead{
      display:flex; align-items:center; gap:10px; font-weight:1000; font-size:18px;
      margin-bottom:14px; padding-bottom:10px; border-bottom:1px solid rgba(148,163,184,.25);
    }
    .secNo{
      width:26px; height:26px; border-radius:999px; background:#0f172a; color:#fff;
      display:flex; align-items:center; justify-content:center; font-weight:1000; font-size:14px;
    }

    .grid3{ display:grid; grid-template-columns: 1fr 1fr 1fr; gap:16px 18px; align-items:end; }
    @media(max-width:980px){ .grid3{ grid-template-columns:1fr 1fr; } }
    @media(max-width:620px){ .grid3{ grid-template-columns:1fr; } }

    .field{ position:relative; }
    .tag{
      position:absolute; top:-10px; left:18px; background:var(--purple); color:#fff;
      font-weight:1000; font-size:12px; padding:4px 10px; border-radius:999px;
      box-shadow:0 10px 25px rgba(2,6,23,.10); z-index:2; display:inline-flex; align-items:center; gap:6px; white-space:nowrap;
    }
    .tag.opt{ background:#64748b; }
    .reqStar{ color:#fff; opacity:.95; }

    .inputWrap{
      border:2px solid rgba(109,94,252,.45); background:#ffffff; border-radius:999px;
      padding:14px 14px; display:flex; align-items:center; gap:10px; transition:.18s ease;
    }
    .inputWrap:focus-within{ border-color: rgba(109,94,252,.90); box-shadow:0 0 0 6px rgba(109,94,252,.12); }
    .inputWrap input, .inputWrap select{
      border:0 !important; outline:0 !important; box-shadow:none !important; width:100%;
      font-weight:900; background:transparent !important;
    }
    .inputWrap input::placeholder{ color:#94a3b8; font-weight:900; }

    .colSpan2{ grid-column: span 2; }
    .colSpan3{ grid-column: span 3; }
    @media(max-width:980px){ .colSpan2,.colSpan3{ grid-column: span 2; } }
    @media(max-width:620px){ .colSpan2,.colSpan3{ grid-column: span 1; } }

    .fileHint{
      display:inline-block; margin-top:8px; background:#f59e0b; color:#111827;
      font-weight:1000; font-size:12px; padding:3px 10px; border-radius:999px;
    }

    .photoPrev{
      display:flex; align-items:center; gap:12px; margin-top:10px;
      background:#f8fafc; border:1px solid var(--border); border-radius:14px; padding:10px 12px;
      font-weight:900; color:#334155;
    }
    .photoPrev img{
      width:56px; height:56px; border-radius:999px; object-fit:cover; border:1px solid var(--border);
    }

    .actionsBottom{ display:flex; justify-content:center; gap:14px; margin-top:20px; }
    .btnReset{
      background:#fbbf24; border:0; color:#111827; padding:12px 22px; border-radius:999px;
      font-weight:1000; min-width:160px; box-shadow:0 16px 45px rgba(2,6,23,.10);
    }
    .btnSubmit{
      background:linear-gradient(90deg, #6d5efc, #8b5cf6);
      border:0; color:#fff; padding:12px 30px; border-radius:999px;
      font-weight:1000; min-width:200px; box-shadow:0 16px 45px rgba(109,94,252,.25);
    }
    .muted{ color:var(--muted); font-weight:900; }
    .help{ font-size:12px; margin-top:6px; color:var(--muted); font-weight:900; }

    body.dark{ background:#0b1220; color:#e5e7eb; }
    body.dark .crumbbar, body.dark .section{ background:#0f1a2f; border-color:rgba(255,255,255,.10); }
    body.dark .inputWrap{ background:#0b1220; }
    body.dark .heroTitle{ color:#e5e7eb; }
  </style>
</head>
<body class="p-3">

<div class="wrap">

  <div class="crumbbar">
    <div class="crumb">
      <span style="font-size:20px;">Students</span>
      <span class="muted">|</span>
      <small><?= $isEdit ? "✏️ Update Student" : "🏠 - Admission Form" ?></small>
    </div>
    <div class="topBtns">
      <a href="students.php" class="btn btn-outline-secondary btnPill">← Back</a>
    </div>
  </div>

  <div class="heroTitle"><?= $isEdit ? "Update Student" : "Admission Form" ?></div>
  <div class="legend">
    <span><span class="lgBar lgReq"></span> Required*</span>
    <span><span class="lgBar lgOpt"></span> Optional</span>
  </div>

  <form method="POST" enctype="multipart/form-data" autocomplete="off">

    <div class="section">
      <div class="secHead"><div class="secNo">1</div><div>Student Information</div></div>

      <div class="grid3">
        <div class="field">
          <div class="tag">Student First Name<span class="reqStar">*</span></div>
          <div class="inputWrap">
            <input name="first_name" required placeholder="Name of Student" value="<?= h($old["first_name"] ?? "") ?>">
          </div>
        </div>

        <div class="field">
          <div class="tag">Student Middle Name<span class="reqStar">*</span></div>
          <div class="inputWrap">
            <input name="middle_name" required placeholder="Middle Name" value="<?= h($old["middle_name"] ?? "") ?>">
          </div>
        </div>

        <div class="field">
          <div class="tag">Student Last Name<span class="reqStar">*</span></div>
          <div class="inputWrap">
            <input name="last_name" required placeholder="Last Name" value="<?= h($old["last_name"] ?? "") ?>">
          </div>
        </div>

        <div class="field colSpan2">
          <div class="tag">Mother Full Name (3 words min)<span class="reqStar">*</span></div>
          <div class="inputWrap">
            <input name="mother_full_name" required placeholder="Ex: Amina Ali Hassan" value="<?= h($old["mother_full_name"] ?? "") ?>">
          </div>
          <div class="help">Mother Full Name waa inuu noqdaa ugu yaraan 3 eray.</div>
        </div>

        <div class="field">
          <div class="tag opt">Gender</div>
          <div class="inputWrap">
            <select name="gender">
              <option value="" <?= (($old["gender"] ?? "")==="" ? "selected" : "") ?>>Gender</option>
              <option value="M" <?= (($old["gender"] ?? "")==="M" ? "selected" : "") ?>>Male</option>
              <option value="F" <?= (($old["gender"] ?? "")==="F" ? "selected" : "") ?>>Female</option>
            </select>
          </div>
        </div>

        <div class="field">
          <div class="tag opt">Date Of Birth (8–50 years)</div>
          <div class="inputWrap">
            <input type="date" name="dob" value="<?= h($old["dob"] ?? "") ?>">
          </div>
          <div class="help">Da’da la oggol yahay: 8 sano ilaa 50 sano.</div>
        </div>

        <div class="field">
          <div class="tag opt">Nationality</div>
          <div class="inputWrap">
            <input name="nationality" placeholder="Somali" value="<?= h($old["nationality"] ?? "") ?>">
          </div>
        </div>

        <div class="field">
          <div class="tag opt">Place of Birth</div>
          <div class="inputWrap">
            <input name="place_of_birth" placeholder="Mogadishu" value="<?= h($old["place_of_birth"] ?? "") ?>">
          </div>
        </div>

        <div class="field">
          <div class="tag opt">Mobile No. (10 digits)</div>
          <div class="inputWrap">
            <input name="phone" placeholder="0615247548" value="<?= h($old["phone"] ?? "") ?>">
          </div>
          <div class="help">Tusaale: 0615247548 (10 number oo keliya).</div>
        </div>

        <div class="field colSpan3">
          <div class="tag opt">Address</div>
          <div class="inputWrap">
            <input name="address" placeholder="Address" value="<?= h($old["address"] ?? "") ?>">
          </div>
        </div>

        <div class="field colSpan2">
          <div class="tag opt">Picture Upload (JPG/PNG/WEBP)</div>
          <div class="inputWrap">
            <input type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp">
          </div>
          <span class="fileHint">Max 2MB • JPG/PNG/WEBP • uploads/students/</span>

          <?php if($isEdit && $photoPreview !== ""): ?>
            <div class="photoPrev">
              <img src="<?= h($photoPreview) ?>" onerror="this.style.display='none';">
              <div>
                <div style="font-weight:1000;">Current Photo</div>
                <div class="muted" style="font-size:12px;"><?= h($photoPreview) ?></div>
              </div>
            </div>
          <?php endif; ?>
        </div>

        <div class="field">
          <div class="tag opt">Registration No</div>
          <div class="inputWrap">
            <input value="<?= $isEdit ? h((string)($current["admission_no"] ?? "Auto")) : "Auto Generated" ?>" readonly style="cursor:not-allowed; opacity:.75;">
          </div>
          <div class="help">Admission No auto ayuu u sameysmaa marka Submit la dhaho.</div>
        </div>
      </div>
    </div>

    <div class="section">
      <div class="secHead"><div class="secNo">2</div><div>Contact / Emergency</div></div>

      <div class="grid3">
        <div class="field">
          <div class="tag opt">Emergency Contact Name</div>
          <div class="inputWrap">
            <input name="emergency_contact_name" placeholder="Amina Hassan" value="<?= h($old["emergency_contact_name"] ?? "") ?>">
          </div>
        </div>
        <div class="field">
          <div class="tag opt">Emergency Contact Phone (10 digits)</div>
          <div class="inputWrap">
            <input name="emergency_contact_phone" placeholder="0615247548" value="<?= h($old["emergency_contact_phone"] ?? "") ?>">
          </div>
        </div>
      </div>
    </div>

    <div class="section">
      <div class="secHead"><div class="secNo">3</div><div>Academic Information</div></div>

      <div class="grid3">
        <div class="field">
          <div class="tag">Academic Year<span class="reqStar">*</span></div>
          <div class="inputWrap">
            <select name="year_id" required>
              <option value="">Select</option>
              <?php foreach($years as $y): ?>
                <?php
                  $sel = ((string)($old["year_id"] ?? "") === (string)$y["year_id"]);
                  $autoSel = ((int)$y["is_current"]===1 && ($old["year_id"] ?? "")==="" && !$isEdit);
                ?>
                <option value="<?= (int)$y["year_id"] ?>" <?= ($sel || $autoSel ? "selected" : "") ?>>
                  <?= h($y["year_name"]) ?><?= ((int)$y["is_current"]===1 ? " (Current)" : "") ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="field">
          <div class="tag">Select Class<span class="reqStar">*</span></div>
          <div class="inputWrap">
            <select name="section_id" required>
              <option value="">Select</option>
              <?php foreach($sections as $s): ?>
                <?php $sel = ((string)($old["section_id"] ?? "") === (string)$s["section_id"]); ?>
                <option value="<?= (int)$s["section_id"] ?>" <?= ($sel ? "selected" : "") ?>>
                  <?= h($s["grade_name"]." - ".$s["section_name"]) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="field">
          <div class="tag opt">Roll No</div>
          <div class="inputWrap">
            <input name="roll_no" placeholder="F2B-015" value="<?= h($old["roll_no"] ?? "") ?>">
          </div>
        </div>
      </div>
    </div>

    <div class="section">
      <div class="secHead"><div class="secNo">4</div><div>Fee / Finance (Optional)</div></div>

      <div class="grid3">
        <div class="field">
          <div class="tag opt">Tuition Total (USD)</div>
          <div class="inputWrap">
            <input type="number" step="0.01" min="0" name="tuition_total" placeholder="300.00" value="<?= h($old["tuition_total"] ?? "") ?>">
          </div>
        </div>

        <div class="field">
          <div class="tag opt">Paid Amount (USD)</div>
          <div class="inputWrap">
            <input type="number" step="0.01" min="0" name="paid_amount" placeholder="200.00" value="<?= h($old["paid_amount"] ?? "") ?>">
          </div>
        </div>

        <div class="field">
          <div class="tag opt">Payment Method</div>
          <div class="inputWrap">
            <select name="method_id">
              <option value="">Select</option>
              <?php foreach($methods as $m): ?>
                <?php $sel = ((string)($old["method_id"] ?? "") === (string)$m["method_id"]); ?>
                <option value="<?= (int)$m["method_id"] ?>" <?= ($sel ? "selected" : "") ?>>
                  <?= h($m["method_name"]) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="field colSpan3">
          <div class="tag opt">Reference No</div>
          <div class="inputWrap">
            <input name="reference_no" placeholder="EVC-TRX-123456" value="<?= h($old["reference_no"] ?? "") ?>">
          </div>
          <div class="help">Haddii Paid Amount > 0 → Payment Method waa qasab.</div>
        </div>
      </div>

      <div class="actionsBottom">
        <button type="reset" class="btnReset">↺ Reset</button>
        <button type="submit" class="btnSubmit"><?= $isEdit ? "✓ Update Student" : "✓ Submit" ?></button>
      </div>
    </div>

  </form>
</div>

<script>
  const FLASH = <?= json_encode($flash, JSON_UNESCAPED_UNICODE) ?>;
  if (FLASH) {
    Swal.fire({
      icon: FLASH.type || "info",
      title: (FLASH.type === "success" ? "Guul ✅" : "Cilad ❌"),
      text: FLASH.msg || "",
      confirmButtonText: "Haye",
      confirmButtonColor: "#6d5efc",
      width: 650
    });
  }
</script>

</body>
</html>
