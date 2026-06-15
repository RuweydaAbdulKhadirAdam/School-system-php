<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . "/conncation.php";

/* =========================
   ADMIN GUARD
   ========================= */
if (!isset($_SESSION["user_id"])) {
  header("Location: login.php");
  exit;
}
if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "ADMIN") {
  header("Location: login.php");
  exit;
}

/* =========================
   HELPERS
   ========================= */
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8"); }

function setAlert(string $type, string $title, string $text): void {
  $_SESSION["flash_alert"] = ["type"=>$type, "title"=>$title, "text"=>$text];
}
function popAlert(): ?array {
  if (!isset($_SESSION["flash_alert"])) return null;
  $a = $_SESSION["flash_alert"];
  unset($_SESSION["flash_alert"]);
  return $a;
}

function isValidYearName(string $s): bool {
  return (bool)preg_match('/^\d{4}([\-\/]\d{4})?$/', $s);
}

function normalizeDate(string $s): ?string {
  $s = trim($s);
  if ($s === "") return null;
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
  [$y,$m,$d] = array_map("intval", explode("-", $s));
  if (!checkdate($m, $d, $y)) return null;
  return $s;
}

/* =========================
   VIEW (form | list)
   ========================= */
$view = $_GET["view"] ?? "form";
if (!in_array($view, ["form","list"], true)) $view = "form";

/* =========================
   ACTIONS
   ========================= */
$action = $_POST["action"] ?? $_GET["action"] ?? "";

/* ---------- ADD ---------- */
if ($action === "add") {
  $year_name  = trim((string)($_POST["year_name"] ?? ""));
  $start_date = normalizeDate((string)($_POST["start_date"] ?? ""));
  $end_date   = normalizeDate((string)($_POST["end_date"] ?? ""));
  $is_current = isset($_POST["is_current"]) ? 1 : 0;

  if ($year_name === "" || !$start_date || !$end_date) {
    setAlert("error", "Required", "Please fill Year Name, Start Date and End Date.");
    header("Location: academic.php?view=form");
    exit;
  }
  if (!isValidYearName($year_name)) {
    setAlert("error", "Invalid Year", "Year Name example: 2025-2026 or 2025/2026 or 2025.");
    header("Location: academic.php?view=form");
    exit;
  }
  if ($start_date > $end_date) {
    setAlert("error", "Invalid Range", "Start date must be before End date.");
    header("Location: academic.php?view=form");
    exit;
  }

  if ($is_current === 1) {
    $conn->query("UPDATE academic_years SET is_current=0");
  }

  $stmt = $conn->prepare("INSERT INTO academic_years (year_name, start_date, end_date, is_current) VALUES (?,?,?,?)");
  if (!$stmt) {
    setAlert("error", "DB Error", "Prepare failed: " . $conn->error);
    header("Location: academic.php?view=form");
    exit;
  }

  $stmt->bind_param("sssi", $year_name, $start_date, $end_date, $is_current);
  if ($stmt->execute()) {
    setAlert("success", "Saved", "Academic Year created successfully.");
    $stmt->close();
    header("Location: academic.php?view=list");
    exit;
  } else {
    setAlert("error", "Failed", "Could not create year. Maybe year name already exists.");
  }
  $stmt->close();

  header("Location: academic.php?view=form");
  exit;
}

/* ---------- UPDATE ---------- */
if ($action === "update") {
  $year_id    = (int)($_POST["year_id"] ?? 0);
  $year_name  = trim((string)($_POST["year_name"] ?? ""));
  $start_date = normalizeDate((string)($_POST["start_date"] ?? ""));
  $end_date   = normalizeDate((string)($_POST["end_date"] ?? ""));

  if ($year_id <= 0 || $year_name === "" || !$start_date || !$end_date) {
    setAlert("error", "Required", "All fields are required.");
    header("Location: academic.php?view=form");
    exit;
  }
  if (!isValidYearName($year_name)) {
    setAlert("error", "Invalid Year", "Year Name example: 2025-2026 or 2025/2026 or 2025.");
    header("Location: academic.php?view=form&edit=".$year_id);
    exit;
  }
  if ($start_date > $end_date) {
    setAlert("error", "Invalid Range", "Start date must be before End date.");
    header("Location: academic.php?view=form&edit=".$year_id);
    exit;
  }

  $stmt = $conn->prepare("UPDATE academic_years SET year_name=?, start_date=?, end_date=? WHERE year_id=?");
  if (!$stmt) {
    setAlert("error", "DB Error", "Prepare failed: " . $conn->error);
    header("Location: academic.php?view=form&edit=".$year_id);
    exit;
  }

  $stmt->bind_param("sssi", $year_name, $start_date, $end_date, $year_id);
  if ($stmt->execute()) {
    setAlert("success", "Updated", "Academic Year updated successfully.");
    $stmt->close();
    header("Location: academic.php?view=list");
    exit;
  } else {
    setAlert("error", "Failed", "Update failed. Maybe year name already exists.");
  }
  $stmt->close();

  header("Location: academic.php?view=form&edit=".$year_id);
  exit;
}

/* ---------- SET CURRENT ---------- */
if ($action === "set_current") {
  $year_id = (int)($_GET["year_id"] ?? 0);
  if ($year_id <= 0) {
    setAlert("error", "Invalid", "Invalid year id.");
    header("Location: academic.php?view=list");
    exit;
  }

  $conn->query("UPDATE academic_years SET is_current=0");
  $stmt = $conn->prepare("UPDATE academic_years SET is_current=1 WHERE year_id=?");
  if ($stmt) {
    $stmt->bind_param("i", $year_id);
    $stmt->execute();
    $stmt->close();
  }

  setAlert("success", "Current Year", "Selected academic year is now current.");
  header("Location: academic.php?view=list");
  exit;
}

/* ---------- DELETE ---------- */
if ($action === "delete") {
  $year_id = (int)($_GET["year_id"] ?? 0);
  if ($year_id <= 0) {
    setAlert("error", "Invalid", "Invalid year id.");
    header("Location: academic.php?view=list");
    exit;
  }

  $chk = $conn->prepare("SELECT is_current FROM academic_years WHERE year_id=? LIMIT 1");
  $isCur = 0;
  if ($chk) {
    $chk->bind_param("i", $year_id);
    $chk->execute();
    $r = $chk->get_result();
    if ($row = $r->fetch_assoc()) $isCur = (int)$row["is_current"];
    $chk->close();
  }
  if ($isCur === 1) {
    setAlert("error", "Not Allowed", "You cannot delete the current academic year. Set another year as current first.");
    header("Location: academic.php?view=list");
    exit;
  }

  $stmt = $conn->prepare("DELETE FROM academic_years WHERE year_id=?");
  if (!$stmt) {
    setAlert("error", "DB Error", "Prepare failed: " . $conn->error);
    header("Location: academic.php?view=list");
    exit;
  }

  $stmt->bind_param("i", $year_id);
  if ($stmt->execute()) {
    setAlert("success", "Deleted", "Academic Year deleted successfully.");
  } else {
    setAlert("error", "Failed", "Delete failed. Maybe this year is used by enrollments/timetables/exams.");
  }
  $stmt->close();

  header("Location: academic.php?view=list");
  exit;
}

/* =========================
   FETCH: LIST + EDIT ROW
   ========================= */
$years = [];
$res = $conn->query("SELECT * FROM academic_years ORDER BY is_current DESC, start_date DESC, year_id DESC");
if ($res) while ($row = $res->fetch_assoc()) $years[] = $row;

$curName = "N/A";
foreach ($years as $y) {
  if ((int)$y["is_current"] === 1) { $curName = (string)$y["year_name"]; break; }
}

$editId = (int)($_GET["edit"] ?? 0);
$editRow = null;
if ($editId > 0) {
  $st = $conn->prepare("SELECT * FROM academic_years WHERE year_id=? LIMIT 1");
  if ($st) {
    $st->bind_param("i", $editId);
    $st->execute();
    $rr = $st->get_result();
    $editRow = $rr->fetch_assoc();
    $st->close();
  }
}

$alert = popAlert();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Academic Years</title>

  <link rel="stylesheet" href="bootstrap.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <style>
    :root{
      --bg:#f4f7ff; --card:#ffffff; --border:#dbe6f7; --text:#0f172a; --muted:#64748b;
      --primary:#2563eb; --success:#16a34a; --danger:#ef4444;
      --shadow: 0 18px 55px rgba(2,6,23,.08); --radius: 18px;
    }
    body{
      margin:0;
      background: radial-gradient(1000px 500px at 10% 0%, rgba(37,99,235,.08), transparent 55%),
                  radial-gradient(900px 450px at 90% 10%, rgba(22,163,74,.07), transparent 55%),
                  var(--bg);
      font-family: Arial, sans-serif;
      color: var(--text);
    }
    .wrap{ max-width: 1180px; margin: 22px auto; padding: 0 14px 24px; }
    .cardx{
      background: rgba(255,255,255,.75);
      backdrop-filter: blur(10px);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
    }
    .head{
      padding: 14px 14px;
      display:flex; align-items:center; justify-content:space-between; gap: 12px; flex-wrap:wrap;
    }
    .titleRow{ display:flex; align-items:center; gap: 12px; font-weight: 900; font-size: 18px; flex-wrap:wrap; }
    .pill{
      display:inline-flex; align-items:center; gap:8px;
      padding: 8px 12px; border-radius: 999px;
      border: 1px solid var(--border);
      background: #fff;
      font-weight: 900; color: var(--muted); font-size: 13px;
    }
    .btnRound{ border-radius: 999px !important; font-weight: 900 !important; }
    .panel{
      background: #fff;
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 14px 14px;
      box-shadow: var(--shadow);
    }
    .panel h5{
      margin: 0 0 10px;
      font-weight: 900;
      font-size: 15px;
      display:flex; align-items:center; gap: 10px;
    }
    label{ font-weight: 900; font-size: 13px; color: var(--muted); }
    .hint{ font-size: 12px; margin-top: 6px; color: var(--muted); font-weight: 800; }
    .table thead th{
      font-size: 12px; text-transform: uppercase; letter-spacing: .6px;
      color: var(--muted); border-bottom-color: var(--border) !important;
    }
    .table td, .table th{
      border-top-color: var(--border) !important;
      font-weight: 800;
      vertical-align: middle;
      background: transparent !important;
    }
    .badgeSoft{
      display:inline-flex; align-items:center; gap:8px;
      padding: 6px 10px; border-radius: 999px;
      border: 1px solid rgba(22,163,74,.20);
      background: rgba(22,163,74,.10);
      color: var(--success);
      font-weight: 900; font-size: 12px;
    }
    .muted{ color: var(--muted); font-weight: 800; }
  </style>
</head>
<body>

<div class="wrap">

  <!-- HEADER -->
  <div class="cardx mb-3">
    <div class="head">
      <div class="titleRow">
        <i class="fa-solid fa-calendar-check" style="color:var(--primary)"></i>
        Academic Years
        <span class="pill"><i class="fa-solid fa-star" style="color:#f59e0b"></i> Current: <?= h($curName) ?></span>
      </div>

      <div class="d-flex gap-2 flex-wrap">
       
         
        </a>

        <!-- ✅ Two buttons (two pages) -->
        <a href="academic.php?view=form" class="btn btn<?= $view==="form" ? "" : "-outline" ?>-primary btnRound">
          <i class="fa-solid fa-plus"></i> Add Page
        </a>
        <a href="academic.php?view=list" class="btn btn<?= $view==="list" ? "" : "-outline" ?>-primary btnRound">
          <i class="fa-solid fa-list"></i> List Page
        </a>
      </div>
    </div>
  </div>

  <?php if ($view === "form"): ?>
    <!-- =========================
         FORM PAGE (ADD / EDIT)
         ========================= -->
    <div class="panel">
      <?php if ($editRow): ?>
        <h5><i class="fa-solid fa-pen-to-square"></i> Edit Academic Year</h5>
        <form method="post" action="academic.php?view=form&edit=<?= (int)$editRow["year_id"] ?>">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="year_id" value="<?= (int)$editRow["year_id"] ?>">

          <div class="mb-3">
            <label>Year Name</label>
            <input class="form-control" name="year_name"
                   value="<?= h($editRow["year_name"] ?? "") ?>" placeholder="2025-2026" required>
            <div class="hint">Example: 2025-2026</div>
          </div>

          <div class="mb-3">
            <label>Start Date</label>
            <input type="date" class="form-control" name="start_date"
                   value="<?= h($editRow["start_date"] ?? "") ?>" required>
          </div>

          <div class="mb-3">
            <label>End Date</label>
            <input type="date" class="form-control" name="end_date"
                   value="<?= h($editRow["end_date"] ?? "") ?>" required>
          </div>

          <div class="d-flex gap-2 flex-wrap">
            <button class="btn btn-primary btnRound" type="submit">
              <i class="fa-solid fa-floppy-disk"></i> Update
            </button>
            <a class="btn btn-outline-secondary btnRound" href="academic.php?view=form">
              Cancel
            </a>
            <a class="btn btn-outline-primary btnRound" href="academic.php?view=list">
              Go List
            </a>
          </div>
        </form>

      <?php else: ?>
        <h5><i class="fa-solid fa-plus"></i> Add Academic Year</h5>
        <form method="post" action="academic.php?view=form">
          <input type="hidden" name="action" value="add">

          <div class="mb-3">
            <label>Year Name</label>
            <input class="form-control" name="year_name" placeholder="2025-2026" required>
            <div class="hint">Example: 2025-2026</div>
          </div>

          <div class="mb-3">
            <label>Start Date</label>
            <input type="date" class="form-control" name="start_date" required>
          </div>

          <div class="mb-3">
            <label>End Date</label>
            <input type="date" class="form-control" name="end_date" required>
          </div>

          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="is_current" name="is_current">
            <label class="form-check-label" for="is_current" style="font-weight:900;">
              Set as current year
            </label>
          </div>

          <div class="d-flex gap-2 flex-wrap">
            <button class="btn btn-primary btnRound" type="submit">
              <i class="fa-solid fa-floppy-disk"></i> Save
            </button>
            <a class="btn btn-outline-primary btnRound" href="academic.php?view=list">
              Go List
            </a>
          </div>
        </form>
      <?php endif; ?>
    </div>

  <?php else: ?>
    <!-- =========================
         LIST PAGE
         ========================= -->
    <div class="panel">
      <h5><i class="fa-solid fa-list"></i> Academic Years List</h5>

      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead>
            <tr>
              <th>ID</th>
              <th>Year</th>
              <th>Start</th>
              <th>End</th>
              <th>Status</th>
              <th style="width: 260px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($years) === 0): ?>
              <tr>
                <td colspan="6" class="text-center muted" style="padding:18px;">
                  No academic years found.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($years as $y): ?>
                <tr>
                  <td><?= (int)$y["year_id"] ?></td>
                  <td style="font-weight:900;"><?= h($y["year_name"] ?? "") ?></td>
                  <td><?= h($y["start_date"] ?? "") ?></td>
                  <td><?= h($y["end_date"] ?? "") ?></td>
                  <td>
                    <?php if ((int)$y["is_current"] === 1): ?>
                      <span class="badgeSoft"><i class="fa-solid fa-star"></i> Current</span>
                    <?php else: ?>
                      <span class="muted">Normal</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div class="d-flex gap-2 flex-wrap">
                      <!-- EDIT goes to form page -->
                      <a class="btn btn-outline-primary btn-sm btnRound"
                         href="academic.php?view=form&edit=<?= (int)$y["year_id"] ?>">
                        <i class="fa-solid fa-pen"></i> Edit
                      </a>

                      <?php if ((int)$y["is_current"] !== 1): ?>
                        <a class="btn btn-outline-success btn-sm btnRound btnSetCurrent"
                           href="academic.php?action=set_current&year_id=<?= (int)$y["year_id"] ?>&view=list">
                          <i class="fa-solid fa-check"></i> Set Current
                        </a>

                        <a class="btn btn-outline-danger btn-sm btnRound btnDelete"
                           href="academic.php?action=delete&year_id=<?= (int)$y["year_id"] ?>&view=list">
                          <i class="fa-solid fa-trash"></i> Delete
                        </a>
                      <?php else: ?>
                        <button class="btn btn-outline-secondary btn-sm btnRound" type="button" disabled>
                          <i class="fa-solid fa-lock"></i> Locked
                        </button>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="muted mt-2">
        Tip: Current year is used by default in enrollments, timetable, exams.
      </div>
    </div>
  <?php endif; ?>

</div>

<?php if ($alert): ?>
<script>
Swal.fire({
  icon: <?= json_encode($alert["type"]) ?>,
  title: <?= json_encode($alert["title"]) ?>,
  text: <?= json_encode($alert["text"]) ?>,
  confirmButtonColor: "#2563eb",
  width: 560
});
</script>
<?php endif; ?>

<script>
  // Confirm DELETE
  document.querySelectorAll(".btnDelete").forEach(btn=>{
    btn.addEventListener("click", (e)=>{
      e.preventDefault();
      const url = btn.getAttribute("href");
      Swal.fire({
        icon: "warning",
        title: "Delete Academic Year?",
        text: "This action cannot be undone.",
        showCancelButton: true,
        confirmButtonText: "Yes, delete",
        cancelButtonText: "Cancel",
        confirmButtonColor: "#ef4444",
        cancelButtonColor: "#64748b",
        width: 560
      }).then(r=>{
        if(r.isConfirmed) window.location.href = url;
      });
    });
  });

  // Confirm SET CURRENT
  document.querySelectorAll(".btnSetCurrent").forEach(btn=>{
    btn.addEventListener("click", (e)=>{
      e.preventDefault();
      const url = btn.getAttribute("href");
      Swal.fire({
        icon: "question",
        title: "Set as Current Year?",
        text: "This will make this year the default for the system.",
        showCancelButton: true,
        confirmButtonText: "Yes, set current",
        cancelButtonText: "Cancel",
        confirmButtonColor: "#16a34a",
        cancelButtonColor: "#64748b",
        width: 560
      }).then(r=>{
        if(r.isConfirmed) window.location.href = url;
      });
    });
  });
</script>

</body>
</html>
