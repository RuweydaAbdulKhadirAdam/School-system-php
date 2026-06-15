<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . "/conncation.php";

header("Content-Type: application/json; charset=utf-8");
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* =====================
   ADMIN / RECEPTION GUARD (AJAX)
   ===================== */
if (!isset($_SESSION["user_id"]) || !in_array($_SESSION["role"], ["ADMIN","RECEPTION"], true)) {
  http_response_code(401);
  echo json_encode(["ok"=>false, "msg"=>"Fadlan login samee (Unauthorized)."], JSON_UNESCAPED_UNICODE);
  exit;
}

/* =====================
   HELPERS
   ===================== */
function jfail(string $msg, int $code=400): void {
  http_response_code($code);
  echo json_encode(["ok"=>false, "msg"=>$msg], JSON_UNESCAPED_UNICODE);
  exit;
}
function cleanSpaces(?string $v): string {
  $v = trim((string)$v);
  return (string)preg_replace('/\s+/', ' ', $v);
}
function nullIfEmpty(?string $v): ?string {
  $v = trim((string)$v);
  return ($v === "") ? null : $v;
}
function isValidPhone(string $v): bool {
  return (bool)preg_match('/^[0-9+\s-]{6,30}$/', $v);
}
function minThreeWords(string $v): bool {
  $parts = array_values(array_filter(explode(" ", trim($v)), fn($x)=>$x!==""));
  return count($parts) >= 3;
}

/** same URL normalize as add */
function normalizePhotoUrl(string $url): string {
  $url = trim($url);
  if ($url === "") return "";

  $url = preg_replace('/\s+/', '', $url) ?? $url;

  if (!preg_match('~^https?://~i', $url)) {
    $url = "https://" . $url;
  }

  $parts = parse_url($url);
  if (!$parts || empty($parts["host"])) return "";

  $scheme = strtolower((string)($parts["scheme"] ?? ""));
  if (!in_array($scheme, ["http","https"], true)) return "";

  if (filter_var($url, FILTER_VALIDATE_URL) === false) return "";

  return $url;
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
function hasColumn(mysqli $conn, string $table, string $column): bool {
  $dbRow = $conn->query("SELECT DATABASE() AS db")->fetch_assoc();
  $db = $dbRow["db"] ?? "";
  if ($db === "") return false;

  $st = $conn->prepare("
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?
    LIMIT 1
  ");
  $st->bind_param("sss", $db, $table, $column);
  $st->execute();
  $ok = (bool)$st->get_result()->fetch_assoc();
  $st->close();
  return $ok;
}

/** Capacity check for update: exclude current enrollment id */
function assertSectionHasSpaceForUpdate(mysqli $conn, int $yearId, int $sectionId, int $excludeEnrollmentId = 0): void {
  if (!hasColumn($conn, "sections", "capacity_max")) return;

  $st = $conn->prepare("SELECT capacity_max FROM sections WHERE section_id=? LIMIT 1");
  $st->bind_param("i", $sectionId);
  $st->execute();
  $sec = $st->get_result()->fetch_assoc();
  $st->close();

  if (!$sec) throw new Exception("Class/Section lama helin (DB).");

  $cap = (int)($sec["capacity_max"] ?? 0);
  if ($cap <= 0) return;

  if ($excludeEnrollmentId > 0) {
    $st = $conn->prepare("
      SELECT COUNT(*) AS c
      FROM enrollments
      WHERE year_id=? AND section_id=? AND status='ENROLLED'
        AND enrollment_id<>?
    ");
    $st->bind_param("iii", $yearId, $sectionId, $excludeEnrollmentId);
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

  if ($cnt >= $cap) {
    throw new Exception("Class-kaan wuu buuxaa. Fadlan dooro class kale (Capacity: {$cap}).");
  }
}

/* =====================
   REQUIRE POST
   ===================== */
if ($_SERVER["REQUEST_METHOD"] !== "POST") jfail("Request-ka ma saxna (Method).", 405);

/* =====================
   INPUTS
   ===================== */
$studentId = (int)($_POST["student_id"] ?? 0);
$invoiceId = (int)($_POST["invoice_id"] ?? 0);
if ($studentId <= 0) jfail("Student ID ma saxna.");

/* ---- Personal ---- */
$admissionNo  = cleanSpaces($_POST["admission_no"] ?? "");
$first        = cleanSpaces($_POST["first_name"] ?? "");
$middle       = cleanSpaces($_POST["middle_name"] ?? "");
$last         = cleanSpaces($_POST["last_name"] ?? "");
$mother       = cleanSpaces($_POST["mother_full_name"] ?? "");
$gender       = cleanSpaces($_POST["gender"] ?? "");
$dob          = cleanSpaces($_POST["dob"] ?? "");
$nationality  = cleanSpaces($_POST["nationality"] ?? "");
$pob          = cleanSpaces($_POST["place_of_birth"] ?? "");
$photoUrlRaw  = (string)($_POST["profile_photo_url"] ?? "");

/* ---- Contact ---- */
$phone        = cleanSpaces($_POST["phone"] ?? "");
$address      = cleanSpaces($_POST["address"] ?? "");
$emgName      = cleanSpaces($_POST["emergency_contact_name"] ?? "");
$emgPhone     = cleanSpaces($_POST["emergency_contact_phone"] ?? "");

/* ---- Academic ---- */
$yearId       = (int)($_POST["year_id"] ?? 0);
$sectionId    = (int)($_POST["section_id"] ?? 0);
$rollNo       = cleanSpaces($_POST["roll_no"] ?? "");

/* ---- Financial ---- */
$tuitionTotal = cleanSpaces($_POST["tuition_total"] ?? "");
$paidAmount   = cleanSpaces($_POST["paid_amount"] ?? "");
$methodId     = (int)($_POST["method_id"] ?? 0);
$referenceNo  = cleanSpaces($_POST["reference_no"] ?? "");

/* =====================
   VALIDATIONS
   ===================== */
if ($first === "" || $middle === "" || $last === "" || $mother === "") jfail("Fadlan buuxi meelaha qasabka ah (First/Middle/Last & Mother).");
if (!minThreeWords($mother)) jfail("Mother Full Name waa inuu noqdaa ugu yaraan 3 eray (3 words min).");
if ($gender !== "" && !in_array($gender, ["M","F"], true)) jfail("Gender ma saxna.");
if ($phone !== "" && !isValidPhone($phone)) jfail("Phone number-ka ma saxna.");
if ($emgPhone !== "" && !isValidPhone($emgPhone)) jfail("Emergency phone number-ka ma saxna.");

if ($yearId <= 0 || $sectionId <= 0) jfail("Academic: Academic Year iyo Section waa qasab.");
if (!yearExists($conn, $yearId)) jfail("Academic Year-kaan DB kuma jiro.");
if (!sectionExists($conn, $sectionId)) jfail("Class/Section-kan DB kuma jiro.");

/* ✅ URL normalize + validate */
$photoUrl = normalizePhotoUrl($photoUrlRaw);
if (trim($photoUrlRaw) !== "" && $photoUrl === "") {
  jfail("Profile Photo URL ma saxna. Geli sida: https://site.com/photo.jpg (ama ka tag bannaan).", 422);
}

/* Financial numeric validation */
$tuitionTotalNum = null;
if ($tuitionTotal !== "") {
  if (!is_numeric($tuitionTotal) || (float)$tuitionTotal < 0) jfail("Tuition Total waa inuu noqdaa number sax ah.");
  $tuitionTotalNum = round((float)$tuitionTotal, 2);
}
$paidAmountNum = 0.00;
if ($paidAmount !== "") {
  if (!is_numeric($paidAmount) || (float)$paidAmount < 0) jfail("Paid Amount waa inuu noqdaa number sax ah.");
  $paidAmountNum = round((float)$paidAmount, 2);
}
if ($paidAmountNum > 0 && $methodId <= 0) jfail("Haddii Paid Amount > 0, Payment Method waa qasab.");

/* Need TUITION fee_type_id if we touch tuition */
$tuitionFeeTypeId = null;
if ($tuitionTotalNum !== null) {
  $res = $conn->query("SELECT fee_type_id FROM fee_types WHERE fee_type_name='TUITION' LIMIT 1");
  $row = $res ? $res->fetch_assoc() : null;
  $tuitionFeeTypeId = $row ? (int)$row["fee_type_id"] : null;
  if (!$tuitionFeeTypeId) jfail("Fee type 'TUITION' lama helin (fee_types).");
}

/* Convert empty -> NULL */
$admissionNoN = nullIfEmpty($admissionNo);
$genderN      = nullIfEmpty($gender);
$dobN         = nullIfEmpty($dob);
$natN         = nullIfEmpty($nationality);
$pobN         = nullIfEmpty($pob);
$photoN       = nullIfEmpty($photoUrl);

$phoneN       = nullIfEmpty($phone);
$addrN        = nullIfEmpty($address);
$emgNameN     = nullIfEmpty($emgName);
$emgPhoneN    = nullIfEmpty($emgPhone);

$rollNoN      = nullIfEmpty($rollNo);
$refN         = nullIfEmpty($referenceNo);

$createdBy = (int)($_SESSION["user_id"] ?? 0);

/* =====================
   TRANSACTION
   ===================== */
$conn->begin_transaction();

try {
  /* Ensure student exists */
  $chk = $conn->prepare("SELECT student_id FROM students WHERE student_id=? LIMIT 1");
  $chk->bind_param("i", $studentId);
  $chk->execute();
  $exists = $chk->get_result()->fetch_assoc();
  $chk->close();
  if (!$exists) throw new Exception("Arday lama helin (ID: {$studentId}).");

  /* Update students */
  $up = $conn->prepare("
    UPDATE students SET
      admission_no=?,
      first_name=?,
      middle_name=?,
      last_name=?,
      mother_full_name=?,
      gender=?,
      dob=?,
      nationality=?,
      place_of_birth=?,
      profile_photo_url=?,
      phone=?,
      address=?,
      emergency_contact_name=?,
      emergency_contact_phone=?
    WHERE student_id=?
    LIMIT 1
  ");
  $up->bind_param(
    "ssssssssssssssi",
    $admissionNoN, $first, $middle, $last, $mother,
    $genderN, $dobN, $natN, $pobN, $photoN,
    $phoneN, $addrN, $emgNameN, $emgPhoneN,
    $studentId
  );
  $up->execute();
  $up->close();

  /**
   * ENROLLMENT UPSERT:
   * - find existing enrollment for (student_id, year_id)
   * - if found: update it
   * - else: insert new
   */
  $existingEnrollmentId = 0;

  $findE = $conn->prepare("
    SELECT enrollment_id
    FROM enrollments
    WHERE student_id=? AND year_id=?
    ORDER BY enrollment_id DESC
    LIMIT 1
  ");
  $findE->bind_param("ii", $studentId, $yearId);
  $findE->execute();
  $rowE = $findE->get_result()->fetch_assoc();
  $findE->close();
  if ($rowE) $existingEnrollmentId = (int)$rowE["enrollment_id"];

  // Capacity check (exclude current enrollment if exists)
  assertSectionHasSpaceForUpdate($conn, $yearId, $sectionId, $existingEnrollmentId);

  if ($existingEnrollmentId > 0) {
    $eu = $conn->prepare("
      UPDATE enrollments
      SET section_id=?, roll_no=?, status='ENROLLED'
      WHERE enrollment_id=? AND student_id=? AND year_id=?
      LIMIT 1
    ");
    $eu->bind_param("isiii", $sectionId, $rollNoN, $existingEnrollmentId, $studentId, $yearId);
    $eu->execute();
    $eu->close();

    $realEnrollmentId = $existingEnrollmentId;
  } else {
    $insE = $conn->prepare("
      INSERT INTO enrollments (student_id, year_id, section_id, roll_no, status)
      VALUES (?, ?, ?, ?, 'ENROLLED')
    ");
    $insE->bind_param("iiis", $studentId, $yearId, $sectionId, $rollNoN);
    $insE->execute();
    $realEnrollmentId = (int)$insE->insert_id;
    $insE->close();
  }

  /* Finance (optional) */
  $realInvoiceId = $invoiceId;

  if ($tuitionTotalNum !== null) {
    if ($realInvoiceId <= 0) {
      $invoiceNo = "INV-" . $realEnrollmentId . "-" . date("YmdHis");
      $status = "ISSUED";

      $insInv = $conn->prepare("
        INSERT INTO student_invoices (enrollment_id, invoice_no, issue_date, due_date, status, created_by)
        VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), ?, ?)
      ");
      $insInv->bind_param("issi", $realEnrollmentId, $invoiceNo, $status, $createdBy);
      $insInv->execute();
      $realInvoiceId = (int)$insInv->insert_id;
      $insInv->close();
    }

    // Upsert invoice item (tuition)
    $chkItem = $conn->prepare("
      SELECT item_id FROM invoice_items
      WHERE invoice_id=? AND fee_type_id=?
      LIMIT 1
    ");
    $chkItem->bind_param("ii", $realInvoiceId, $tuitionFeeTypeId);
    $chkItem->execute();
    $item = $chkItem->get_result()->fetch_assoc();
    $chkItem->close();

    if ($item) {
      $itemId = (int)$item["item_id"];
      $uItem = $conn->prepare("UPDATE invoice_items SET amount=?, description='Tuition Fee' WHERE item_id=? LIMIT 1");
      $uItem->bind_param("di", $tuitionTotalNum, $itemId);
      $uItem->execute();
      $uItem->close();
    } else {
      $insItem = $conn->prepare("
        INSERT INTO invoice_items (invoice_id, fee_type_id, description, amount)
        VALUES (?, ?, 'Tuition Fee', ?)
      ");
      $insItem->bind_param("iid", $realInvoiceId, $tuitionFeeTypeId, $tuitionTotalNum);
      $insItem->execute();
      $insItem->close();
    }

    if ($paidAmountNum > 0) {
      $insPay = $conn->prepare("
        INSERT INTO payments (invoice_id, method_id, amount, reference_no, received_by)
        VALUES (?, ?, ?, ?, ?)
      ");
      $insPay->bind_param("iidsi", $realInvoiceId, $methodId, $paidAmountNum, $refN, $createdBy);
      $insPay->execute();
      $insPay->close();
    }

    // Recalculate invoice status
    $rs = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS t FROM invoice_items WHERE invoice_id=?");
    $rs->bind_param("i", $realInvoiceId);
    $rs->execute();
    $totTu = (float)($rs->get_result()->fetch_assoc()["t"] ?? 0);
    $rs->close();

    $rs = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS p FROM payments WHERE invoice_id=?");
    $rs->bind_param("i", $realInvoiceId);
    $rs->execute();
    $totPaid = (float)($rs->get_result()->fetch_assoc()["p"] ?? 0);
    $rs->close();

    $newStatus = "ISSUED";
    if ($totTu > 0 && $totPaid >= $totTu) $newStatus = "PAID";
    else if ($totPaid > 0 && $totPaid < $totTu) $newStatus = "PARTIAL";

    $uInv = $conn->prepare("UPDATE student_invoices SET status=? WHERE invoice_id=? LIMIT 1");
    $uInv->bind_param("si", $newStatus, $realInvoiceId);
    $uInv->execute();
    $uInv->close();
  }

  $conn->commit();
  echo json_encode(["ok"=>true, "msg"=>"Xogta ardayga waa la cusbooneysiiyey ✅"], JSON_UNESCAPED_UNICODE);
  exit;

} catch (mysqli_sql_exception $e) {
  $conn->rollback();
  if ((int)$e->getCode() === 1062) {
    jfail("Duplicate: Ardaygan sanadkan hore ayuu ugu diiwaangashanaa. Kaliya EDIT samee.", 409);
  }
  jfail("Qalad DB: " . $e->getMessage(), 500);
} catch (Throwable $e) {
  $conn->rollback();
  jfail($e->getMessage(), 500);
}
