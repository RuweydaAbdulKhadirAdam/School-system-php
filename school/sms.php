<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . "/conncation.php";

if (!isset($_SESSION["user_id"]) || !in_array($_SESSION["role"] ?? "", ["ADMIN","RECEPTION","FINANCE"], true)) {
  header("Location: login.php");
  exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!function_exists("h")) {
  function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8"); }
}

function setFlash(string $type, string $title, string $text): void {
  $_SESSION["flash"] = ["type"=>$type, "title"=>$title, "text"=>$text];
}

$userId = (int)($_SESSION["user_id"] ?? 0);

/* =========================
   ACTIONS
   ========================= */
try {
  // 1) Queue SMS (insert into sms_outbox)
  if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "send") {
    $toPhone = trim((string)($_POST["to_phone"] ?? ""));
    $msg     = trim((string)($_POST["message"] ?? ""));

    if ($toPhone === "" || $msg === "") {
      throw new RuntimeException("Phone and message are required.");
    }
    if (mb_strlen($msg) > 500) {
      throw new RuntimeException("Message is too long (max 500 chars).");
    }

    $stmt = $conn->prepare("INSERT INTO sms_outbox (to_phone, message, status, created_by) VALUES (?,?, 'PENDING', ?)");
    $stmt->bind_param("ssi", $toPhone, $msg, $userId);
    $stmt->execute();
    $stmt->close();

    setFlash("success", "Queued", "SMS has been queued (PENDING).");
    header("Location: sms.php");
    exit;
  }

  // 2) Mark status + create log
  if (isset($_GET["mark"]) && (int)$_GET["mark"] > 0 && isset($_GET["status"])) {
    $smsId  = (int)$_GET["mark"];
    $status = strtoupper(trim((string)$_GET["status"]));
    if (!in_array($status, ["SENT","FAILED","PENDING"], true)) {
      throw new RuntimeException("Invalid status.");
    }

    // Update outbox status
    $stmt = $conn->prepare("UPDATE sms_outbox SET status=? WHERE sms_id=?");
    $stmt->bind_param("si", $status, $smsId);
    $stmt->execute();
    $stmt->close();

    // Add log record (manual log)
    $provider = "MANUAL";
    $providerMsgId = "LOCAL-" . $smsId . "-" . date("YmdHis");
    $resp = "Status manually changed to: " . $status;

    $stmt = $conn->prepare("INSERT INTO sms_logs (sms_id, provider, provider_message_id, response_text) VALUES (?,?,?,?)");
    $stmt->bind_param("isss", $smsId, $provider, $providerMsgId, $resp);
    $stmt->execute();
    $stmt->close();

    setFlash("success", "Updated", "SMS status updated + log saved.");
    header("Location: sms.php");
    exit;
  }

  // 3) Delete from outbox (optional)
  if (isset($_GET["delete"]) && (int)$_GET["delete"] > 0) {
    $smsId = (int)$_GET["delete"];

    // deleting outbox will delete logs by FK (sms_logs.sms_id ON DELETE CASCADE)
    $stmt = $conn->prepare("DELETE FROM sms_outbox WHERE sms_id=?");
    $stmt->bind_param("i", $smsId);
    $stmt->execute();
    $stmt->close();

    setFlash("success","Deleted","SMS removed from outbox (logs removed too).");
    header("Location: sms.php");
    exit;
  }

} catch (Throwable $e) {
  setFlash("error","Error",$e->getMessage());
  header("Location: sms.php");
  exit;
}

/* =========================
   DATA: Outbox + Logs
   ========================= */
$outbox = $conn->query("
  SELECT o.sms_id, o.to_phone, o.message, o.status, o.created_at,
         u.username AS created_by_name
  FROM sms_outbox o
  LEFT JOIN users u ON u.user_id = o.created_by
  ORDER BY o.sms_id DESC
  LIMIT 250
")->fetch_all(MYSQLI_ASSOC);

$logs = $conn->query("
  SELECT l.sms_log_id, l.sms_id, l.provider, l.provider_message_id, l.response_text, l.logged_at,
         o.to_phone
  FROM sms_logs l
  JOIN sms_outbox o ON o.sms_id = l.sms_id
  ORDER BY l.sms_log_id DESC
  LIMIT 250
")->fetch_all(MYSQLI_ASSOC);

$flash = $_SESSION["flash"] ?? null;
unset($_SESSION["flash"]);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>SMS Center</title>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <style>
    :root{
      --bg:#071226; --card:#0e1c3a; --card2:#0a1631;
      --text:#e8f0ff; --muted:rgba(232,240,255,.7);
      --border:rgba(255,255,255,.10);
      --blue:#2d6cff; --green:#22c55e; --red:#ef4444; --amber:#f59e0b;
      --radius:16px;
    }
    *{box-sizing:border-box}
    body{margin:0; font-family:system-ui,Segoe UI,Arial; background:var(--bg); color:var(--text)}
    .wrap{max-width:1400px; margin:0 auto; padding:22px}

    .top{
      display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;
      margin-bottom:14px;
    }
    .title{margin:0; font-size:20px; font-weight:900}
    .row{display:flex; gap:10px; align-items:center; flex-wrap:wrap}

    .card{
      background:linear-gradient(180deg,var(--card),var(--card2));
      border:1px solid var(--border);
      border-radius:var(--radius);
      box-shadow:0 18px 45px rgba(0,0,0,.35);
      padding:16px;
    }

    .btn{
      border:0; border-radius:12px; padding:10px 14px;
      font-weight:900; cursor:pointer; text-decoration:none; display:inline-block;
    }
    .btn-primary{background:var(--blue); color:white}
    .btn-ghost{background:transparent; border:1px solid var(--border); color:var(--text)}
    .btn-danger{background:transparent; border:1px solid rgba(239,68,68,.45); color:#fecaca}
    .btn-warn{background:transparent; border:1px solid rgba(245,158,11,.55); color:#fde68a}
    .btn-ok{background:transparent; border:1px solid rgba(34,197,94,.55); color:#bbf7d0}

    input,textarea{
      width:100%;
      padding:10px 12px;
      border-radius:12px;
      border:1px solid var(--border);
      background:#071226;
      color:var(--text);
      outline:none;
    }
    textarea{min-height:120px; resize:vertical}
    label{font-size:12px; color:var(--muted); font-weight:800}

    .grid2{display:grid; grid-template-columns: 1fr 1fr; gap:14px;}
    @media(max-width:1100px){ .grid2{grid-template-columns:1fr;} }

    .pill{
      display:inline-block; padding:4px 10px; border-radius:999px;
      border:1px solid var(--border); font-size:12px; color:var(--muted); font-weight:900;
    }

    table{width:100%; border-collapse:collapse}
    th,td{padding:10px; border-bottom:1px solid var(--border); font-size:14px; vertical-align:top}
    th{text-align:left; color:var(--muted); font-size:12px; text-transform:uppercase; letter-spacing:.05em}

    .badge{
      display:inline-block; padding:4px 10px; border-radius:999px;
      font-size:12px; font-weight:900; border:1px solid var(--border);
    }
    .b-pending{border-color:rgba(45,108,255,.55); color:#bfdbfe}
    .b-sent{border-color:rgba(34,197,94,.55); color:#bbf7d0}
    .b-failed{border-color:rgba(239,68,68,.55); color:#fecaca}

    .search{max-width:360px}
    .muted{color:var(--muted); font-weight:800}
    .msg{
      max-width:600px;
      white-space:pre-wrap;
      word-break:break-word;
      color:#dbeafe;
      font-weight:700;
    }
  </style>
</head>
<body>

<div class="wrap">

  <div class="top">
    <h1 class="title">📲 SMS Center (Outbox + Logs)</h1>
    <div class="row">
      <a class="btn btn-ghost" href="dashboardadmin.php">⬅ Back</a>
      <input id="outboxSearch" class="search" type="text" placeholder="Search outbox (phone/message/status)..." />
      <input id="logSearch" class="search" type="text" placeholder="Search logs (phone/provider/response)..." />
    </div>
  </div>

  <div class="grid2">

    <!-- SEND SMS -->
    <div class="card">
      <div class="row" style="justify-content:space-between;">
        <div class="pill">Send / Queue SMS (Saved as PENDING)</div>
        <div class="pill">Role: <?= h((string)($_SESSION["role"] ?? "")) ?></div>
      </div>

      <form method="post" style="margin-top:12px;" onsubmit="return confirmSend();">
        <input type="hidden" name="action" value="send"/>

        <div>
          <label>Phone Number</label>
          <input name="to_phone" type="text" placeholder="e.g. +25261xxxxxxx or 061xxxxxxx" required />
          <div class="muted" style="margin-top:6px;">Tip: Use Somalia format. Example: 061xxxxxxx</div>
        </div>

        <div style="margin-top:10px;">
          <label>Message</label>
          <textarea name="message" placeholder="Write SMS message..." required></textarea>
          <div class="muted" style="margin-top:6px;">Max 500 characters</div>
        </div>

        <div class="row" style="margin-top:12px;">
          <button class="btn btn-primary" type="submit">Queue SMS</button>
          <span class="muted">It will appear in Outbox below.</span>
        </div>
      </form>
    </div>

    <!-- LOGS -->
    <div class="card">
      <div class="row" style="justify-content:space-between;">
        <div class="pill">SMS Logs (provider responses / manual updates)</div>
        <div class="pill">Total: <?= count($logs) ?></div>
      </div>

      <div style="overflow:auto; margin-top:10px; max-height:420px;">
        <table id="logsTable">
          <thead>
            <tr>
              <th>#</th>
              <th>SMS ID</th>
              <th>Phone</th>
              <th>Provider</th>
              <th>Provider Msg ID</th>
              <th>Response</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($logs as $l): ?>
              <tr>
                <td><?= (int)$l["sms_log_id"] ?></td>
                <td><?= (int)$l["sms_id"] ?></td>
                <td><?= h($l["to_phone"]) ?></td>
                <td><b><?= h($l["provider"] ?: "—") ?></b></td>
                <td class="muted"><?= h($l["provider_message_id"] ?: "—") ?></td>
                <td class="muted"><?= h($l["response_text"] ?: "—") ?></td>
                <td class="muted"><?= h($l["logged_at"]) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if(!$logs): ?>
              <tr><td colspan="7" class="muted">No logs yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>

  <!-- OUTBOX -->
  <div class="card" style="margin-top:14px;">
    <div class="row" style="justify-content:space-between;">
      <div class="pill">Outbox (Queue)</div>
      <div class="pill">Total: <?= count($outbox) ?></div>
    </div>

    <div style="overflow:auto; margin-top:10px;">
      <table id="outboxTable">
        <thead>
          <tr>
            <th>ID</th>
            <th>To</th>
            <th>Message</th>
            <th>Status</th>
            <th>Created By</th>
            <th>Date</th>
            <th style="width:260px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($outbox as $o):
            $st=(string)$o["status"];
            $cls="b-pending";
            if($st==="SENT") $cls="b-sent";
            else if($st==="FAILED") $cls="b-failed";
          ?>
            <tr>
              <td><?= (int)$o["sms_id"] ?></td>
              <td><b><?= h($o["to_phone"]) ?></b></td>
              <td class="msg"><?= h($o["message"]) ?></td>
              <td><span class="badge <?= $cls ?>"><?= h($st) ?></span></td>
              <td class="muted"><?= h($o["created_by_name"] ?: "—") ?></td>
              <td class="muted"><?= h($o["created_at"]) ?></td>
              <td class="row" style="gap:8px;">
                <a class="btn btn-ok" href="sms.php?mark=<?= (int)$o["sms_id"] ?>&status=SENT">Mark SENT</a>
                <a class="btn btn-warn" href="sms.php?mark=<?= (int)$o["sms_id"] ?>&status=FAILED">Mark FAILED</a>
                <a class="btn btn-danger" href="sms.php?delete=<?= (int)$o["sms_id"] ?>" onclick="return confirm('Delete SMS + logs?')">Delete</a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if(!$outbox): ?>
            <tr><td colspan="7" class="muted">No outbox messages.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<script>
  function confirmSend(){
    // small check + confirm
    return true;
  }

  // Live search Outbox
  const outboxSearch = document.getElementById("outboxSearch");
  const outboxTable  = document.getElementById("outboxTable");
  if(outboxSearch && outboxTable){
    outboxSearch.addEventListener("input", () => {
      const q = outboxSearch.value.toLowerCase().trim();
      [...outboxTable.querySelectorAll("tbody tr")].forEach(tr => {
        tr.style.display = tr.innerText.toLowerCase().includes(q) ? "" : "none";
      });
    });
  }

  // Live search Logs
  const logSearch = document.getElementById("logSearch");
  const logsTable = document.getElementById("logsTable");
  if(logSearch && logsTable){
    logSearch.addEventListener("input", () => {
      const q = logSearch.value.toLowerCase().trim();
      [...logsTable.querySelectorAll("tbody tr")].forEach(tr => {
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
