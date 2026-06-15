<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . "/conncation.php";

if (!isset($_SESSION["user_id"]) || !in_array($_SESSION["role"] ?? "", ["ADMIN","FINANCE"], true)) {
  header("Location: login.php"); exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!function_exists("h")) {
  function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8"); }
}
function setFlash(string $type, string $title, string $text): void {
  $_SESSION["flash"] = ["type"=>$type, "title"=>$title, "text"=>$text];
}

function invoiceTotals(mysqli $conn, int $invoiceId): array {
  $stmt = $conn->prepare("SELECT IFNULL(SUM(amount),0) AS total FROM invoice_items WHERE invoice_id=?");
  $stmt->bind_param("i",$invoiceId);
  $stmt->execute();
  $total = (float)($stmt->get_result()->fetch_assoc()["total"] ?? 0);
  $stmt->close();

  $stmt = $conn->prepare("SELECT IFNULL(SUM(amount),0) AS paid FROM payments WHERE invoice_id=?");
  $stmt->bind_param("i",$invoiceId);
  $stmt->execute();
  $paid = (float)($stmt->get_result()->fetch_assoc()["paid"] ?? 0);
  $stmt->close();

  return ["total"=>$total, "paid"=>$paid, "balance"=>max(0,$total-$paid)];
}

function updateInvoiceStatus(mysqli $conn, int $invoiceId): void {
  $t = invoiceTotals($conn,$invoiceId);
  $status = "ISSUED";
  if ($t["total"] > 0 && $t["paid"] >= $t["total"]) $status = "PAID";
  else if ($t["paid"] > 0) $status = "PARTIAL";
  else $status = "ISSUED";

  $stmt = $conn->prepare("UPDATE student_invoices SET status=? WHERE invoice_id=?");
  $stmt->bind_param("si", $status, $invoiceId);
  $stmt->execute();
  $stmt->close();
}

/* CREATE INVOICE (auto items from fee_structure) */
try {
  if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "create_invoice") {
    $enrollmentId = (int)($_POST["enrollment_id"] ?? 0);
    if ($enrollmentId <= 0) throw new RuntimeException("Select student first.");

    $dueDate = trim((string)($_POST["due_date"] ?? ""));
    if ($dueDate === "") $dueDate = date("Y-m-d");

    // get enrollment info: year_id + grade_id
    $stmt = $conn->prepare("
      SELECT e.enrollment_id, e.year_id, sec.section_id, g.grade_id,
             CONCAT(s.first_name,' ',s.middle_name,' ',s.last_name) AS student_name
      FROM enrollments e
      JOIN students s ON s.student_id=e.student_id
      JOIN sections sec ON sec.section_id=e.section_id
      JOIN grades g ON g.grade_id=sec.grade_id
      WHERE e.enrollment_id=? AND e.status='ENROLLED'
      LIMIT 1
    ");
    $stmt->bind_param("i",$enrollmentId);
    $stmt->execute();
    $enr = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$enr) throw new RuntimeException("Enrollment not found.");

    $yearId  = (int)$enr["year_id"];
    $gradeId = (int)$enr["grade_id"];

    // fee structures for this year+grade
    $stmt = $conn->prepare("
      SELECT fs.fee_type_id, fs.amount, ft.fee_type_name
      FROM fee_structures fs
      JOIN fee_types ft ON ft.fee_type_id=fs.fee_type_id
      WHERE fs.year_id=? AND fs.grade_id=?
      ORDER BY ft.fee_type_name ASC
    ");
    $stmt->bind_param("ii",$yearId,$gradeId);
    $stmt->execute();
    $fees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if (!$fees) throw new RuntimeException("No fee structure found for this student grade/year. Create it first in fee_structure.php.");

    $conn->begin_transaction();

    // invoice_no
    $invoiceNo = "INV-" . date("Ymd-His") . "-" . $enrollmentId;
    $issueDate = date("Y-m-d");
    $createdBy = (int)($_SESSION["user_id"] ?? 0);

    $stmt = $conn->prepare("
      INSERT INTO student_invoices (enrollment_id, invoice_no, issue_date, due_date, status, created_by)
      VALUES (?,?,?,?, 'ISSUED', ?)
    ");
    $stmt->bind_param("isssi", $enrollmentId, $invoiceNo, $issueDate, $dueDate, $createdBy);
    $stmt->execute();
    $invoiceId = (int)$conn->insert_id;
    $stmt->close();

    // insert items
    $stmt = $conn->prepare("INSERT INTO invoice_items (invoice_id, fee_type_id, description, amount) VALUES (?,?,?,?)");
    foreach ($fees as $f) {
      $feeTypeId = (int)$f["fee_type_id"];
      $desc = (string)$f["fee_type_name"];
      $amt = (float)$f["amount"];
      $stmt->bind_param("iisd", $invoiceId, $feeTypeId, $desc, $amt);
      $stmt->execute();
    }
    $stmt->close();

    $conn->commit();

    updateInvoiceStatus($conn,$invoiceId);

    setFlash("success","Created","Invoice created + items added automatically.");
    header("Location: finance_invoices.php?invoice_id=".$invoiceId); exit;
  }
} catch (Throwable $e) {
  try { $conn->rollback(); } catch (Throwable $x) {}
  setFlash("error","Error",$e->getMessage());
  header("Location: finance_invoices.php"); exit;
}

/* CANCEL invoice */
try {
  if (isset($_GET["cancel"]) && (int)$_GET["cancel"]>0) {
    $invoiceId = (int)$_GET["cancel"];
    $stmt = $conn->prepare("UPDATE student_invoices SET status='CANCELLED' WHERE invoice_id=?");
    $stmt->bind_param("i",$invoiceId);
    $stmt->execute();
    $stmt->close();
    setFlash("success","Cancelled","Invoice cancelled.");
    header("Location: finance_invoices.php?invoice_id=".$invoiceId); exit;
  }
} catch (Throwable $e) {
  setFlash("error","Error",$e->getMessage());
  header("Location: finance_invoices.php"); exit;
}

/* VIEW invoice details */
$viewInvoiceId = (int)($_GET["invoice_id"] ?? 0);
$invoiceHeader = null;
$invoiceItems = [];
$invoiceMoney = ["total"=>0,"paid"=>0,"balance"=>0];

if ($viewInvoiceId > 0) {
  $stmt = $conn->prepare("
    SELECT i.invoice_id, i.invoice_no, i.issue_date, i.due_date, i.status, i.enrollment_id,
           s.student_id, s.admission_no,
           CONCAT(s.first_name,' ',s.middle_name,' ',s.last_name) AS student_name,
           y.year_name,
           CONCAT(g.grade_name,'-',sec.section_name) AS class_name
    FROM student_invoices i
    JOIN enrollments e ON e.enrollment_id=i.enrollment_id
    JOIN students s ON s.student_id=e.student_id
    JOIN academic_years y ON y.year_id=e.year_id
    JOIN sections sec ON sec.section_id=e.section_id
    JOIN grades g ON g.grade_id=sec.grade_id
    WHERE i.invoice_id=?
    LIMIT 1
  ");
  $stmt->bind_param("i",$viewInvoiceId);
  $stmt->execute();
  $invoiceHeader = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if ($invoiceHeader) {
    $stmt = $conn->prepare("
      SELECT it.item_id, ft.fee_type_name, it.description, it.amount
      FROM invoice_items it
      JOIN fee_types ft ON ft.fee_type_id=it.fee_type_id
      WHERE it.invoice_id=?
      ORDER BY ft.fee_type_name ASC
    ");
    $stmt->bind_param("i",$viewInvoiceId);
    $stmt->execute();
    $invoiceItems = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $invoiceMoney = invoiceTotals($conn,$viewInvoiceId);
    updateInvoiceStatus($conn,$viewInvoiceId);

    $invoiceHeader["status"] = $conn->query("SELECT status FROM student_invoices WHERE invoice_id=".(int)$viewInvoiceId)->fetch_assoc()["status"] ?? $invoiceHeader["status"];
  }
}

/* STUDENT SEARCH LIST (for creating invoice) */
$students = $conn->query("
  SELECT e.enrollment_id, e.year_id,
         s.student_id, s.admission_no,
         CONCAT(s.first_name,' ',s.middle_name,' ',s.last_name) AS student_name,
         y.year_name,
         CONCAT(g.grade_name,'-',sec.section_name) AS class_name
  FROM enrollments e
  JOIN students s ON s.student_id=e.student_id
  JOIN academic_years y ON y.year_id=e.year_id
  JOIN sections sec ON sec.section_id=e.section_id
  JOIN grades g ON g.grade_id=sec.grade_id
  WHERE e.status='ENROLLED'
  ORDER BY y.is_current DESC, y.start_date DESC, g.sort_order ASC, sec.section_name ASC, student_name ASC
")->fetch_all(MYSQLI_ASSOC);

/* RECENT invoices list */
$recent = $conn->query("
  SELECT i.invoice_id, i.invoice_no, i.issue_date, i.due_date, i.status,
         CONCAT(s.first_name,' ',s.middle_name,' ',s.last_name) AS student_name,
         CONCAT(g.grade_name,'-',sec.section_name) AS class_name,
         y.year_name,
         (SELECT IFNULL(SUM(amount),0) FROM invoice_items WHERE invoice_id=i.invoice_id) AS total,
         (SELECT IFNULL(SUM(amount),0) FROM payments WHERE invoice_id=i.invoice_id) AS paid
  FROM student_invoices i
  JOIN enrollments e ON e.enrollment_id=i.enrollment_id
  JOIN students s ON s.student_id=e.student_id
  JOIN academic_years y ON y.year_id=e.year_id
  JOIN sections sec ON sec.section_id=e.section_id
  JOIN grades g ON g.grade_id=sec.grade_id
  ORDER BY i.invoice_id DESC
  LIMIT 200
")->fetch_all(MYSQLI_ASSOC);

$flash = $_SESSION["flash"] ?? null;
unset($_SESSION["flash"]);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Finance Invoices</title>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    :root{
      --bg:#071226; --card:#0e1c3a; --card2:#0a1631;
      --text:#e8f0ff; --muted:rgba(232,240,255,.7);
      --border:rgba(255,255,255,.10); --blue:#2d6cff; --green:#22c55e; --red:#ef4444; --amber:#f59e0b;
      --radius:16px;
    }
    *{box-sizing:border-box}
    body{margin:0; font-family:system-ui,Segoe UI,Arial; background:var(--bg); color:var(--text)}
    .wrap{max-width:1350px; margin:0 auto; padding:22px}
    .top{display:flex; gap:12px; align-items:center; justify-content:space-between; flex-wrap:wrap}
    .title{font-size:20px; font-weight:900; margin:0}
    .card{background:linear-gradient(180deg,var(--card),var(--card2)); border:1px solid var(--border); border-radius:var(--radius);
      box-shadow:0 18px 45px rgba(0,0,0,.35); padding:16px}
    .row{display:flex; gap:10px; align-items:center; flex-wrap:wrap}
    .btn{border:0; border-radius:12px; padding:10px 14px; font-weight:900; cursor:pointer; text-decoration:none; display:inline-block}
    .btn-primary{background:var(--blue); color:white}
    .btn-ghost{background:transparent; border:1px solid var(--border); color:var(--text)}
    .btn-danger{background:transparent; border:1px solid rgba(239,68,68,.45); color:#fecaca}
    .btn-warn{background:transparent; border:1px solid rgba(245,158,11,.55); color:#fde68a}
    input,select{width:100%; padding:10px 12px; border-radius:12px; border:1px solid var(--border); background:#071226; color:var(--text); outline:none}
    label{font-size:12px; color:var(--muted); font-weight:800}
    .grid2{display:grid; grid-template-columns: 1.1fr .9fr; gap:14px; margin-top:14px}
    @media(max-width:1100px){ .grid2{grid-template-columns:1fr} }
    table{width:100%; border-collapse:collapse}
    th,td{padding:10px; border-bottom:1px solid var(--border); font-size:14px}
    th{text-align:left; color:var(--muted); font-size:12px; text-transform:uppercase; letter-spacing:.05em}
    .pill{display:inline-block; padding:4px 10px; border-radius:999px; border:1px solid var(--border); font-size:12px; color:var(--muted); font-weight:900}
    .badge{display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:900; border:1px solid var(--border)}
    .paid{border-color:rgba(34,197,94,.55); color:#bbf7d0}
    .partial{border-color:rgba(245,158,11,.55); color:#fde68a}
    .issued{border-color:rgba(45,108,255,.55); color:#bfdbfe}
    .cancel{border-color:rgba(239,68,68,.55); color:#fecaca}
    .search{max-width:360px}
    .statbar{display:flex; gap:10px; flex-wrap:wrap; margin-top:10px}
    .stat{background:#071226; border:1px solid var(--border); border-radius:14px; padding:10px 12px; font-weight:900}
  </style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <h1 class="title">🧾 Finance — Invoices (Auto items from Fee Structure)</h1>
    <div class="row">
      <input id="studentSearch" class="search" type="text" placeholder="Search student by Name / ID / Admission / Class..." />
      <a class="btn btn-ghost" href="dashboardadmin.php">⬅ Back</a>
      <a class="btn btn-ghost" href="finance_payments.php">💵 Payments</a>
      <a class="btn btn-ghost" href="fee_structure.php">💰 Fee Structure</a>
    </div>
  </div>

  <div class="grid2">

    <!-- CREATE INVOICE -->
    <div class="card">
      <div class="row" style="justify-content:space-between;">
        <div class="pill">1) Search student then select, 2) Create invoice → items auto added</div>
        <div class="pill">Students: <?= count($students) ?></div>
      </div>

      <div style="overflow:auto; margin-top:10px; max-height:340px;">
        <table id="studentsTable">
          <thead>
            <tr>
              <th>Student</th>
              <th>ID</th>
              <th>Admission</th>
              <th>Class</th>
              <th>Year</th>
              <th style="width:120px;">Select</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($students as $s): ?>
              <tr>
                <td><b><?= h($s["student_name"]) ?></b></td>
                <td><?= (int)$s["student_id"] ?></td>
                <td><?= h($s["admission_no"] ?: "—") ?></td>
                <td><?= h($s["class_name"]) ?></td>
                <td><?= h($s["year_name"]) ?></td>
                <td>
                  <button class="btn btn-primary" type="button"
                    onclick="pickStudent(
                      <?= (int)$s['enrollment_id'] ?>,
                      <?= (int)$s['student_id'] ?>,
                      <?= json_encode($s['admission_no'] ?? '') ?>,
                      <?= json_encode($s['student_name']) ?>,
                      <?= json_encode($s['class_name']) ?>,
                      <?= json_encode($s['year_name']) ?>
                    )">Pick</button>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if(!$students): ?>
              <tr><td colspan="6" style="color:var(--muted);">No enrolled students.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="card" style="margin-top:14px; background:#071226;">
        <form method="post">
          <input type="hidden" name="action" value="create_invoice"/>
          <input type="hidden" name="enrollment_id" id="enrollment_id" value=""/>

          <div class="row">
            <div style="flex:1;">
              <label>Selected Student</label>
              <input id="selectedStudent" type="text" value="No student selected" readonly />
            </div>
            <div style="width:220px;">
              <label>Due Date</label>
              <input name="due_date" type="date" value="<?= h(date("Y-m-d")) ?>"/>
            </div>
          </div>

          <div class="statbar">
            <div class="stat">Student ID: <span id="stId">—</span></div>
            <div class="stat">Admission: <span id="stAdm">—</span></div>
            <div class="stat">Class: <span id="stClass">—</span></div>
            <div class="stat">Year: <span id="stYear">—</span></div>
          </div>

          <div class="row" style="margin-top:12px;">
            <button class="btn btn-primary" type="submit" onclick="return ensurePick()">Create Invoice</button>
            <span style="color:var(--muted); font-weight:800;">Invoice items will be taken from fee_structure.php</span>
          </div>
        </form>
      </div>
    </div>

    <!-- INVOICE VIEW + LIST -->
    <div class="card">
      <div class="row" style="justify-content:space-between;">
        <div class="pill">Recent Invoices (Live search below)</div>
        <input id="invoiceSearch" class="search" type="text" placeholder="Search invoice no / student / class..." />
      </div>

      <div style="overflow:auto; margin-top:10px; max-height:260px;">
        <table id="invoiceTable">
          <thead>
            <tr>
              <th>Invoice</th>
              <th>Student</th>
              <th>Class</th>
              <th>Total</th>
              <th>Paid</th>
              <th>Balance</th>
              <th>Status</th>
              <th style="width:120px;">Open</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($recent as $r):
              $total=(float)$r["total"]; $paid=(float)$r["paid"]; $bal=max(0,$total-$paid);
              $st = (string)$r["status"];
              $cls = "issued";
              if ($st==="PAID") $cls="paid";
              else if ($st==="PARTIAL") $cls="partial";
              else if ($st==="CANCELLED") $cls="cancel";
            ?>
              <tr>
                <td><b><?= h($r["invoice_no"]) ?></b></td>
                <td><?= h($r["student_name"]) ?></td>
                <td><?= h($r["class_name"]) ?></td>
                <td>$<?= number_format($total,2) ?></td>
                <td>$<?= number_format($paid,2) ?></td>
                <td>$<?= number_format($bal,2) ?></td>
                <td><span class="badge <?= $cls ?>"><?= h($st) ?></span></td>
                <td><a class="btn btn-ghost" href="finance_invoices.php?invoice_id=<?= (int)$r["invoice_id"] ?>">View</a></td>
              </tr>
            <?php endforeach; ?>
            <?php if(!$recent): ?>
              <tr><td colspan="8" style="color:var(--muted);">No invoices yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- VIEW SELECTED INVOICE -->
      <div class="card" style="margin-top:14px; background:#071226;">
        <div class="row" style="justify-content:space-between;">
          <div class="pill">Invoice Details</div>
          <?php if($invoiceHeader): ?>
            <div class="row">
              <?php if(($invoiceHeader["status"] ?? "") !== "CANCELLED"): ?>
                <a class="btn btn-warn" href="finance_invoices.php?cancel=<?= (int)$invoiceHeader["invoice_id"] ?>&invoice_id=<?= (int)$invoiceHeader["invoice_id"] ?>"
                   onclick="return confirm('Cancel this invoice?')">Cancel</a>
              <?php endif; ?>
              <a class="btn btn-primary" href="finance_payments.php?invoice_id=<?= (int)$invoiceHeader["invoice_id"] ?>">Add Payment</a>
            </div>
          <?php endif; ?>
        </div>

        <?php if(!$invoiceHeader): ?>
          <div style="margin-top:10px; color:var(--muted); font-weight:800;">
            Select an invoice from the list (View button).
          </div>
        <?php else: ?>
          <div style="margin-top:10px;">
            <div class="statbar">
              <div class="stat">Invoice: <b><?= h($invoiceHeader["invoice_no"]) ?></b></div>
              <div class="stat">Student: <b><?= h($invoiceHeader["student_name"]) ?></b></div>
              <div class="stat">ID: <b><?= (int)$invoiceHeader["student_id"] ?></b></div>
              <div class="stat">Admission: <b><?= h($invoiceHeader["admission_no"] ?: "—") ?></b></div>
              <div class="stat">Class: <b><?= h($invoiceHeader["class_name"]) ?></b></div>
              <div class="stat">Year: <b><?= h($invoiceHeader["year_name"]) ?></b></div>
              <div class="stat">Due: <b><?= h($invoiceHeader["due_date"]) ?></b></div>
            </div>

            <div class="statbar" style="margin-top:10px;">
              <div class="stat">Total: <b>$<?= number_format((float)$invoiceMoney["total"],2) ?></b></div>
              <div class="stat">Paid: <b style="color:var(--green)">$<?= number_format((float)$invoiceMoney["paid"],2) ?></b></div>
              <div class="stat">Balance: <b style="color:var(--amber)">$<?= number_format((float)$invoiceMoney["balance"],2) ?></b></div>
              <div class="stat">Status: <b><?= h((string)$invoiceHeader["status"]) ?></b></div>
            </div>

            <div style="overflow:auto; margin-top:12px;">
              <table>
                <thead>
                  <tr>
                    <th>Fee Type</th>
                    <th>Description</th>
                    <th>Amount</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($invoiceItems as $it): ?>
                    <tr>
                      <td><?= h($it["fee_type_name"]) ?></td>
                      <td><?= h($it["description"] ?: "—") ?></td>
                      <td><b>$<?= number_format((float)$it["amount"],2) ?></b></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if(!$invoiceItems): ?>
                    <tr><td colspan="3" style="color:var(--muted);">No items.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>
      </div>

    </div>

  </div>
</div>

<script>
  function pickStudent(enrollmentId, studentId, admissionNo, studentName, className, yearName){
    document.getElementById("enrollment_id").value = enrollmentId;
    document.getElementById("selectedStudent").value = studentName;

    document.getElementById("stId").innerText = studentId;
    document.getElementById("stAdm").innerText = admissionNo ? admissionNo : "—";
    document.getElementById("stClass").innerText = className;
    document.getElementById("stYear").innerText = yearName;

    Swal.fire({icon:"success", title:"Selected", text: studentName, timer:1200, showConfirmButton:false});
  }

  function ensurePick(){
    const v = document.getElementById("enrollment_id").value;
    if(!v){
      Swal.fire({icon:"warning", title:"Select Student", text:"Pick a student first from the table."});
      return false;
    }
    return true;
  }

  // Live search students
  const ss = document.getElementById("studentSearch");
  const st = document.getElementById("studentsTable");
  if (ss && st){
    ss.addEventListener("input", () => {
      const q = ss.value.toLowerCase().trim();
      [...st.querySelectorAll("tbody tr")].forEach(tr => {
        tr.style.display = tr.innerText.toLowerCase().includes(q) ? "" : "none";
      });
    });
  }

  // Live search invoices
  const isb = document.getElementById("invoiceSearch");
  const itb = document.getElementById("invoiceTable");
  if (isb && itb){
    isb.addEventListener("input", () => {
      const q = isb.value.toLowerCase().trim();
      [...itb.querySelectorAll("tbody tr")].forEach(tr => {
        tr.style.display = tr.innerText.toLowerCase().includes(q) ? "" : "none";
      });
    });
  }

  <?php if (!empty($flash)): ?>
  Swal.fire({
    icon: <?= json_encode($flash["type"]) ?>,
    title: <?= json_encode($flash["title"]) ?>,
    text: <?= json_encode($flash["text"]) ?>,
    timer: 2300,
    showConfirmButton: false
  });
  <?php endif; ?>
</script>
</body>
</html>
