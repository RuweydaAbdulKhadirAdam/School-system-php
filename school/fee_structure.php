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

/* DELETE */
try {
  if (isset($_GET["delete"]) && (int)$_GET["delete"] > 0) {
    $id = (int)$_GET["delete"];
    $stmt = $conn->prepare("DELETE FROM fee_structures WHERE fee_structure_id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    setFlash("success","Deleted","Fee structure deleted.");
    header("Location: fee_structure.php"); exit;
  }
} catch (Throwable $e) {
  setFlash("error","Error",$e->getMessage());
  header("Location: fee_structure.php"); exit;
}

/* SAVE (INSERT/UPDATE via unique key) */
try {
  if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "save") {
    $yearId = (int)($_POST["year_id"] ?? 0);
    $gradeId = (int)($_POST["grade_id"] ?? 0);
    $feeTypeId = (int)($_POST["fee_type_id"] ?? 0);
    $amount = (float)($_POST["amount"] ?? 0);

    if ($yearId<=0 || $gradeId<=0 || $feeTypeId<=0 || $amount<=0) {
      throw new RuntimeException("Fill all fields correctly.");
    }

    $sql = "
      INSERT INTO fee_structures (year_id, grade_id, fee_type_id, amount)
      VALUES (?,?,?,?)
      ON DUPLICATE KEY UPDATE amount=VALUES(amount)
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiid", $yearId, $gradeId, $feeTypeId, $amount);
    $stmt->execute();
    $stmt->close();

    setFlash("success","Saved","Fee structure saved successfully.");
    header("Location: fee_structure.php"); exit;
  }
} catch (Throwable $e) {
  setFlash("error","Error",$e->getMessage());
  header("Location: fee_structure.php"); exit;
}

/* DATA */
$years = $conn->query("SELECT year_id, year_name, is_current FROM academic_years ORDER BY is_current DESC, start_date DESC")->fetch_all(MYSQLI_ASSOC);
$grades = $conn->query("SELECT grade_id, grade_name, sort_order FROM grades ORDER BY sort_order ASC")->fetch_all(MYSQLI_ASSOC);
$feeTypes = $conn->query("SELECT fee_type_id, fee_type_name FROM fee_types ORDER BY fee_type_name ASC")->fetch_all(MYSQLI_ASSOC);

$list = $conn->query("
  SELECT fs.fee_structure_id, fs.amount,
         y.year_name, y.is_current,
         g.grade_name,
         ft.fee_type_name
  FROM fee_structures fs
  JOIN academic_years y ON y.year_id=fs.year_id
  JOIN grades g ON g.grade_id=fs.grade_id
  JOIN fee_types ft ON ft.fee_type_id=fs.fee_type_id
  ORDER BY y.is_current DESC, y.start_date DESC, g.sort_order ASC, ft.fee_type_name ASC
")->fetch_all(MYSQLI_ASSOC);

$flash = $_SESSION["flash"] ?? null;
unset($_SESSION["flash"]);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Fee Structure</title>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    :root{
      --bg:#071226; --card:#0e1c3a; --card2:#0a1631;
      --text:#e8f0ff; --muted:rgba(232,240,255,.7);
      --border:rgba(255,255,255,.10); --blue:#2d6cff; --green:#22c55e; --red:#ef4444;
      --radius:16px;
    }
    *{box-sizing:border-box}
    body{margin:0; font-family:system-ui,Segoe UI,Arial; background:var(--bg); color:var(--text)}
    .wrap{max-width:1200px; margin:0 auto; padding:22px}
    .top{display:flex; gap:12px; align-items:center; justify-content:space-between; flex-wrap:wrap}
    .title{font-size:20px; font-weight:900; margin:0}
    .card{background:linear-gradient(180deg,var(--card),var(--card2)); border:1px solid var(--border); border-radius:var(--radius);
      box-shadow:0 18px 45px rgba(0,0,0,.35); padding:16px}
    .grid{display:grid; grid-template-columns: 1fr 1fr 1fr 1fr auto; gap:10px; align-items:end}
    @media(max-width:980px){ .grid{grid-template-columns:1fr 1fr; } }
    label{font-size:12px; color:var(--muted); font-weight:800}
    select,input{width:100%; padding:10px 12px; border-radius:12px; border:1px solid var(--border); background:#071226; color:var(--text); outline:none}
    .btn{border:0; border-radius:12px; padding:10px 14px; font-weight:900; cursor:pointer}
    .btn-primary{background:var(--blue); color:white}
    .btn-danger{background:transparent; border:1px solid rgba(239,68,68,.45); color:#fecaca}
    .btn-ghost{background:transparent; border:1px solid var(--border); color:var(--text); text-decoration:none; display:inline-block}
    .row{display:flex; gap:10px; align-items:center; flex-wrap:wrap}
    .search{max-width:320px}
    table{width:100%; border-collapse:collapse}
    th,td{padding:10px; border-bottom:1px solid var(--border); font-size:14px}
    th{text-align:left; color:var(--muted); font-size:12px; text-transform:uppercase; letter-spacing:.05em}
    .pill{display:inline-block; padding:4px 10px; border-radius:999px; border:1px solid var(--border); font-size:12px; color:var(--muted); font-weight:900}
    .amt{font-weight:900}
    .actions{display:flex; gap:8px; align-items:center}
  </style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <h1 class="title">💰 Fee Structure (Year + Grade + Fee Type)</h1>
    <div class="row">
      <input id="searchBox" class="search" type="text" placeholder="Live search year/grade/type..." />
      <a class="btn btn-ghost" href="dashboardadmin.php">⬅ Back</a>
    </div>
  </div>

  <div class="card" style="margin-top:14px;">
    <form method="post" class="grid">
      <input type="hidden" name="action" value="save"/>
      <div>
        <label>Academic Year</label>
        <select name="year_id" required>
          <option value="">-- Select year --</option>
          <?php foreach($years as $y): ?>
            <option value="<?= (int)$y["year_id"] ?>">
              <?= h($y["year_name"]) ?><?= ((int)$y["is_current"]===1) ? " (current)" : "" ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Grade</label>
        <select name="grade_id" required>
          <option value="">-- Select grade --</option>
          <?php foreach($grades as $g): ?>
            <option value="<?= (int)$g["grade_id"] ?>"><?= h($g["grade_name"]) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Fee Type</label>
        <select name="fee_type_id" required>
          <option value="">-- Select type --</option>
          <?php foreach($feeTypes as $t): ?>
            <option value="<?= (int)$t["fee_type_id"] ?>"><?= h($t["fee_type_name"]) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Amount</label>
        <input name="amount" type="number" step="0.01" min="0" placeholder="e.g. 25" required />
      </div>
      <button class="btn btn-primary" type="submit">Save</button>
    </form>
  </div>

  <div class="card" style="margin-top:14px;">
    <div class="row" style="justify-content:space-between;">
      <div class="pill">Tip: Adding same Year+Grade+Type will update amount automatically</div>
      <div class="pill">Total: <?= count($list) ?></div>
    </div>

    <div style="overflow:auto; margin-top:10px;">
      <table id="fsTable">
        <thead>
          <tr>
            <th>Year</th>
            <th>Grade</th>
            <th>Fee Type</th>
            <th>Amount</th>
            <th style="width:120px;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($list as $r): ?>
            <tr>
              <td><?= h($r["year_name"]) ?><?= ((int)$r["is_current"]===1) ? " (current)" : "" ?></td>
              <td><?= h($r["grade_name"]) ?></td>
              <td><?= h($r["fee_type_name"]) ?></td>
              <td class="amt">$<?= number_format((float)$r["amount"],2) ?></td>
              <td class="actions">
                <a class="btn btn-danger" href="fee_structure.php?delete=<?= (int)$r["fee_structure_id"] ?>" onclick="return confirm('Delete this fee structure?')">Delete</a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if(!$list): ?>
            <tr><td colspan="5" style="color:var(--muted);">No fee structures yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
  const sb = document.getElementById('searchBox');
  const tb = document.getElementById('fsTable');
  if (sb && tb) {
    sb.addEventListener('input', () => {
      const q = sb.value.toLowerCase().trim();
      [...tb.querySelectorAll('tbody tr')].forEach(tr => {
        tr.style.display = tr.innerText.toLowerCase().includes(q) ? '' : 'none';
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
