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

/* ADD PAYMENT */
try {
  if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "add_payment") {
    $invoiceId = (int)($_POST["invoice_id"] ?? 0);
    $methodId  = (int)($_POST["method_id"] ?? 0);
    $amount    = (float)($_POST["amount"] ?? 0);
    $ref       = trim((string)($_POST["reference_no"] ?? ""));

    if ($invoiceId<=0 || $methodId<=0 || $amount<=0) throw new RuntimeException("Fill payment fields correctly.");

    // prevent paying cancelled invoice
    $st = $conn->query("SELECT status FROM student_invoices WHERE invoice_id=".(int)$invoiceId)->fetch_assoc();
    if (!$st) throw new RuntimeException("Invoice not found.");
    if (($st["status"] ?? "") === "CANCELLED") throw new RuntimeException("Cannot pay a CANCELLED invoice.");

    $receivedBy = (int)($_SESSION["user_id"] ?? 0);

    $stmt = $conn->prepare("
      INSERT INTO payments (invoice_id, method_id, amount, reference_no, received_by)
      VALUES (?,?,?,?,?)
    ");
    $stmt->bind_param("iidsi", $invoiceId, $methodId, $amount, $ref, $receivedBy);
    $stmt->execute();
    $stmt->close();

    updateInvoiceStatus($conn,$invoiceId);

    setFlash("success","Saved","Payment recorded successfully.");
    header("Location: finance_payments.php?invoice_id=".$invoiceId); exit;
  }
} catch (Throwable $e) {
  setFlash("error","Error",$e->getMessage());
  header("Location: finance_payments.php"); exit;
}

/* METHODS */
$methods = $conn->query("SELECT method_id, method_name FROM payment_methods ORDER BY method_name ASC")->fetch_all(MYSQLI_ASSOC);

/* INVOICE LIST (with student details) */
$invoices = $conn->query("
  SELECT i.invoice_id, i.invoice_no, i.issue_date, i.due_date, i.status,
         s.student_id, s.admission_no,
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
  LIMIT 300
")->fetch_all(MYSQLI_ASSOC);

/* VIEW selected invoice */
$viewInvoiceId = (int)($_GET["invoice_id"] ?? 0);
$inv = null;
$items = [];
$money = ["total"=>0,"paid"=>0,"balance"=>0];
$payHistory = [];

if ($viewInvoiceId > 0) {
  $stmt = $conn->prepare("
    SELECT i.invoice_id, i.invoice_no, i.issue_date, i.due_date, i.status,
           s.student_id, s.admission_no,
           CONCAT(s.first_name,' ',s.middle_name,' ',s.last_name) AS student_name,
           CONCAT(g.grade_name,'-',sec.section_name) AS class_name,
           y.year_name
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
  $inv = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if ($inv) {
    $stmt = $conn->prepare("
      SELECT ft.fee_type_name, it.description, it.amount
      FROM invoice_items it
      JOIN fee_types ft ON ft.fee_type_id=it.fee_type_id
      WHERE it.invoice_id=?
      ORDER BY ft.fee_type_name ASC
    ");
    $stmt->bind_param("i",$viewInvoiceId);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conn->prepare("
      SELECT p.payment_id, p.amount, p.paid_date, p.reference_no, pm.method_name
      FROM payments p
      JOIN payment_methods pm ON pm.method_id=p.method_id
      WHERE p.invoice_id=?
      ORDER BY p.payment_id DESC
    ");
    $stmt->bind_param("i",$viewInvoiceId);
    $stmt->execute();
    $payHistory = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $money = invoiceTotals($conn,$viewInvoiceId);
    updateInvoiceStatus($conn,$viewInvoiceId);

    $inv["status"] = $conn->query("SELECT status FROM student_invoices WHERE invoice_id=".(int)$viewInvoiceId)->fetch_assoc()["status"] ?? $inv["status"];
  }
}

$flash = $_SESSION["flash"] ?? null;
unset($_SESSION["flash"]);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Finance Payments</title>
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
    <h1 class="title">💵 Finance — Payments (Invoice + Student Search)</h1>
    <div class="row">
      <input id="invSearch" class="search" type="text" placeholder="Search invoice / student / ID / admission / class..." />
      <a class="btn btn-ghost" href="dashboardadmin.php">⬅ Back</a>
      <a class="btn btn-ghost" href="finance_invoices.php">🧾 Invoices</a>
      <a class="btn btn-ghost" href="fee_structure.php">💰 Fee Structure</a>
    </div>
  </div>

  <div class="grid2">
    <!-- INVOICE LIST -->
    <div class="card">
      <div class="row" style="justify-content:space-between;">
        <div class="pill">Pick invoice then record payment</div>
        <div class="pill">Invoices: <?= count($invoices) ?></div>
      </div>

      <div style="overflow:auto; margin-top:10px; max-height:520px;">
        <table id="invTable">
          <thead>
            <tr>
              <th>Invoice</th>
              <th>Student</th>
              <th>ID</th>
              <th>Admission</th>
              <th>Class</th>
              <th>Total</th>
              <th>Paid</th>
              <th>Balance</th>
              <th>Status</th>
              <th style="width:110px;">Open</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($invoices as $r):
              $total=(float)$r["total"]; $paid=(float)$r["paid"]; $bal=max(0,$total-$paid);
              $st=(string)$r["status"];
              $cls="issued";
              if($st==="PAID") $cls="paid";
              else if($st==="PARTIAL") $cls="partial";
              else if($st==="CANCELLED") $cls="cancel";
            ?>
              <tr>
                <td><b><?= h($r["invoice_no"]) ?></b></td>
                <td><?= h($r["student_name"]) ?></td>
                <td><?= (int)$r["student_id"] ?></td>
                <td><?= h($r["admission_no"] ?: "—") ?></td>
                <td><?= h($r["class_name"]) ?></td>
                <td>$<?= number_format($total,2) ?></td>
                <td>$<?= number_format($paid,2) ?></td>
                <td>$<?= number_format($bal,2) ?></td>
                <td><span class="badge <?= $cls ?>"><?= h($st) ?></span></td>
                <td><a class="btn btn-ghost" href="finance_payments.php?invoice_id=<?= (int)$r["invoice_id"] ?>">Pick</a></td>
              </tr>
            <?php endforeach; ?>
            <?php if(!$invoices): ?>
              <tr><td colspan="10" style="color:var(--muted);">No invoices yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- PAYMENT FORM + DETAILS -->
    <div class="card">
      <div class="row" style="justify-content:space-between;">
        <div class="pill">Invoice Payment</div>
        <?php if($inv): ?>
          <a class="btn btn-ghost" href="finance_invoices.php?invoice_id=<?= (int)$inv["invoice_id"] ?>">Open Invoice</a>
        <?php endif; ?>
      </div>

      <?php if(!$inv): ?>
        <div style="margin-top:10px; color:var(--muted); font-weight:800;">
          Pick invoice from left table (Pick button).
        </div>
      <?php else: ?>
        <div class="statbar" style="margin-top:10px;">
          <div class="stat">Invoice: <b><?= h($inv["invoice_no"]) ?></b></div>
          <div class="stat">Student: <b><?= h($inv["student_name"]) ?></b></div>
          <div class="stat">ID: <b><?= (int)$inv["student_id"] ?></b></div>
          <div class="stat">Admission: <b><?= h($inv["admission_no"] ?: "—") ?></b></div>
          <div class="stat">Class: <b><?= h($inv["class_name"]) ?></b></div>
          <div class="stat">Year: <b><?= h($inv["year_name"]) ?></b></div>
          <div class="stat">Due: <b><?= h($inv["due_date"]) ?></b></div>
        </div>

        <?php
          $st=(string)$inv["status"];
          $cls="issued";
          if($st==="PAID") $cls="paid";
          else if($st==="PARTIAL") $cls="partial";
          else if($st==="CANCELLED") $cls="cancel";
        ?>
        <div class="statbar" style="margin-top:10px;">
          <div class="stat">Total: <b>$<?= number_format((float)$money["total"],2) ?></b></div>
          <div class="stat">Paid: <b style="color:var(--green)">$<?= number_format((float)$money["paid"],2) ?></b></div>
          <div class="stat">Balance: <b style="color:var(--amber)">$<?= number_format((float)$money["balance"],2) ?></b></div>
          <div class="stat">Status: <span class="badge <?= $cls ?>"><?= h($st) ?></span></div>
        </div>

        <div class="card" style="margin-top:12px; background:#071226;">
          <form method="post" onsubmit="return checkPay();">
            <input type="hidden" name="action" value="add_payment"/>
            <input type="hidden" name="invoice_id" value="<?= (int)$inv["invoice_id"] ?>"/>

            <div class="row">
              <div style="flex:1;">
                <label>Payment Method</label>
                <select name="method_id" required>
                  <option value="">-- Select method --</option>
                  <?php foreach($methods as $m): ?>
                    <option value="<?= (int)$m["method_id"] ?>"><?= h($m["method_name"]) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div style="width:220px;">
                <label>Amount</label>
                <input id="payAmount" name="amount" type="number" step="0.01" min="0" placeholder="e.g. 10" required />
              </div>
            </div>

            <div style="margin-top:10px;">
              <label>Reference No (optional)</label>
              <input name="reference_no" type="text" placeholder="EVC Txn / Bank ref..." />
            </div>

            <div class="row" style="margin-top:12px;">
              <button class="btn btn-primary" type="submit">Save Payment</button>
              <?php if($inv["status"] === "CANCELLED"): ?>
                <span style="color:#fecaca; font-weight:900;">This invoice is CANCELLED (cannot pay).</span>
              <?php endif; ?>
            </div>
          </form>
        </div>

        <div class="card" style="margin-top:12px; background:#071226;">
          <div class="pill">Invoice Items</div>
          <div style="overflow:auto; margin-top:10px;">
            <table>
              <thead><tr><th>Fee Type</th><th>Description</th><th>Amount</th></tr></thead>
              <tbody>
              <?php foreach($items as $it): ?>
                <tr>
                  <td><?= h($it["fee_type_name"]) ?></td>
                  <td><?= h($it["description"] ?: "—") ?></td>
                  <td><b>$<?= number_format((float)$it["amount"],2) ?></b></td>
                </tr>
              <?php endforeach; ?>
              <?php if(!$items): ?>
                <tr><td colspan="3" style="color:var(--muted);">No items.</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card" style="margin-top:12px; background:#071226;">
          <div class="pill">Payment History</div>
          <div style="overflow:auto; margin-top:10px;">
            <table>
              <thead><tr><th>Method</th><th>Amount</th><th>Ref</th><th>Date</th></tr></thead>
              <tbody>
              <?php foreach($payHistory as $p): ?>
                <tr>
                  <td><?= h($p["method_name"]) ?></td>
                  <td><b>$<?= number_format((float)$p["amount"],2) ?></b></td>
                  <td><?= h($p["reference_no"] ?: "—") ?></td>
                  <td><?= h($p["paid_date"]) ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if(!$payHistory): ?>
                <tr><td colspan="4" style="color:var(--muted);">No payments yet.</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

      <?php endif; ?>
    </div>
  </div>
</div>

<script>
  // Live search invoices table
  const sb = document.getElementById("invSearch");
  const tb = document.getElementById("invTable");
  if (sb && tb){
    sb.addEventListener("input", () => {
      const q = sb.value.toLowerCase().trim();
      [...tb.querySelectorAll("tbody tr")].forEach(tr => {
        tr.style.display = tr.innerText.toLowerCase().includes(q) ? "" : "none";
      });
    });
  }

  function checkPay(){
    const invStatus = <?= json_encode($inv["status"] ?? "") ?>;
    if(invStatus === "CANCELLED"){
      Swal.fire({icon:"error", title:"Cancelled", text:"This invoice is cancelled. Cannot pay."});
      return false;
    }
    const a = parseFloat(document.getElementById("payAmount")?.value || "0");
    if(a <= 0){
      Swal.fire({icon:"warning", title:"Amount", text:"Enter valid payment amount."});
      return false;
    }
    return true;
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
