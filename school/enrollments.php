<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . "/conncation.php";

if (!isset($_SESSION["user_id"]) || !in_array($_SESSION["role"], ["ADMIN","RECEPTION"], true)) {
  header("Location: login.php");
  exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* =========================
   HELPERS
   ========================= */
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8"); }

function setFlash(string $type, string $title, string $msg): void {
  $_SESSION["flash"] = ["type"=>$type, "title"=>$title, "msg"=>$msg];
}

$flash = $_SESSION["flash"] ?? null;
unset($_SESSION["flash"]);

$view = $_GET["view"] ?? "add";
if (!in_array($view, ["add","list"], true)) $view = "add";

$action = $_POST["action"] ?? $_GET["action"] ?? "";

/* =========================
   DB VALIDATIONS
   ========================= */
function existsRow(mysqli $conn, string $sql, string $types, array $params): bool {
  $st = $conn->prepare($sql);
  $st->bind_param($types, ...$params);
  $st->execute();
  $st->store_result();
  $ok = $st->num_rows > 0;
  $st->close();
  return $ok;
}

/* =========================
   CAPACITY + DUPLICATE
   ========================= */
function enrolledCount(mysqli $conn, int $yearId, int $sectionId, int $excludeEnrollmentId = 0): int {
  if ($excludeEnrollmentId > 0) {
    $st = $conn->prepare("
      SELECT COUNT(*) FROM enrollments
      WHERE year_id=? AND section_id=? AND UPPER(status)='ENROLLED' AND enrollment_id<>?
    ");
    $st->bind_param("iii", $yearId, $sectionId, $excludeEnrollmentId);
  } else {
    $st = $conn->prepare("
      SELECT COUNT(*) FROM enrollments
      WHERE year_id=? AND section_id=? AND UPPER(status)='ENROLLED'
    ");
    $st->bind_param("ii", $yearId, $sectionId);
  }
  $st->execute();
  $st->bind_result($c);
  $st->fetch();
  $st->close();
  return (int)$c;
}

function sectionCapacity(mysqli $conn, int $sectionId): int {
  $st = $conn->prepare("SELECT capacity_max FROM sections WHERE section_id=? LIMIT 1");
  $st->bind_param("i", $sectionId);
  $st->execute();
  $st->bind_result($cap);
  $st->fetch();
  $st->close();
  return (int)$cap;
}

/* ✅ HARD duplicate guard (case-insensitive) */
function studentAlreadyEnrolledInYear(mysqli $conn, int $studentId, int $yearId, int $excludeEnrollmentId = 0): bool {
  if ($excludeEnrollmentId > 0) {
    $st = $conn->prepare("
      SELECT enrollment_id
      FROM enrollments
      WHERE student_id=? AND year_id=? AND UPPER(status)='ENROLLED' AND enrollment_id<>?
      LIMIT 1
    ");
    $st->bind_param("iii", $studentId, $yearId, $excludeEnrollmentId);
  } else {
    $st = $conn->prepare("
      SELECT enrollment_id
      FROM enrollments
      WHERE student_id=? AND year_id=? AND UPPER(status)='ENROLLED'
      LIMIT 1
    ");
    $st->bind_param("ii", $studentId, $yearId);
  }
  $st->execute();
  $st->store_result();
  $has = $st->num_rows > 0;
  $st->close();
  return $has;
}

/* ✅ STRONG lock: prevents 2 tabs / double click duplicates */
function lockExistingEnrollment(mysqli $conn, int $studentId, int $yearId): ?int {
  $st = $conn->prepare("
    SELECT enrollment_id
    FROM enrollments
    WHERE student_id=? AND year_id=? AND UPPER(status)='ENROLLED'
    LIMIT 1
    FOR UPDATE
  ");
  $st->bind_param("ii", $studentId, $yearId);
  $st->execute();
  $st->bind_result($eid);
  $found = $st->fetch();
  $st->close();
  return $found ? (int)$eid : null;
}

/* =========================
   LOAD YEARS (currentYearId early)
   ========================= */
$years = [];
$res = $conn->query("
  SELECT year_id, year_name, is_current
  FROM academic_years
  ORDER BY is_current DESC, year_id DESC
");
if ($res) while ($r = $res->fetch_assoc()) $years[] = $r;

$currentYearId = 0;
foreach ($years as $y) {
  if ((int)$y["is_current"] === 1) { $currentYearId = (int)$y["year_id"]; break; }
}
if ($currentYearId <= 0 && count($years) > 0) {
  $currentYearId = (int)$years[0]["year_id"];
}

/* =========================
   ADD  (STRONG FIX)
   ========================= */
if ($action === "add") {
  $student_id = (int)($_POST["student_id"] ?? 0);
  $year_id    = (int)($_POST["year_id"] ?? 0);
  $section_id = (int)($_POST["section_id"] ?? 0);
  $roll_no    = trim((string)($_POST["roll_no"] ?? ""));

  if ($student_id <= 0 || $year_id <= 0 || $section_id <= 0) {
    setFlash("error", "Xog Dhiman", "Fadlan buuxi: Ardayga, Sanadka, iyo Fasalka (Section).");
    header("Location: enrollments.php?view=add");
    exit;
  }

  // ✅ Ensure IDs exist (prevents invalid / orphan)
  if (!existsRow($conn, "SELECT 1 FROM students WHERE student_id=? LIMIT 1", "i", [$student_id])) {
    setFlash("error", "Arday Lama Helin", "Student ID-gan ma jiro. Fadlan dooro arday sax ah.");
    header("Location: enrollments.php?view=add");
    exit;
  }
  if (!existsRow($conn, "SELECT 1 FROM academic_years WHERE year_id=? LIMIT 1", "i", [$year_id])) {
    setFlash("error", "Sanad Lama Helin", "Year ID-gan ma jiro. Fadlan dooro sanad sax ah.");
    header("Location: enrollments.php?view=add");
    exit;
  }
  if (!existsRow($conn, "SELECT 1 FROM sections WHERE section_id=? LIMIT 1", "i", [$section_id])) {
    setFlash("error", "Fasal Lama Helin", "Section ID-gan ma jiro. Fadlan dooro fasal sax ah.");
    header("Location: enrollments.php?view=add");
    exit;
  }

  $conn->begin_transaction();
  try {
    // ✅ Lock any existing ENROLLED record for same student+year
    $existing = lockExistingEnrollment($conn, $student_id, $year_id);
    if ($existing !== null) {
      $conn->rollback();
      setFlash("error", "Lama Ogola", "Ardaygaan hore ayaa sanadkan loogu daray fasal. Mar labaad lama dari karo (kaliya EDIT).");
      header("Location: enrollments.php?view=add");
      exit;
    }

    // Capacity check (for this selected year)
    $cap = sectionCapacity($conn, $section_id);
    $enr = enrolledCount($conn, $year_id, $section_id, 0);
    if ($cap > 0 && $enr >= $cap) {
      $conn->rollback();
      setFlash("error", "Fasal Buuxa", "Fasalkan (Section) waa buuxaa. Fadlan dooro fasal kale.");
      header("Location: enrollments.php?view=add");
      exit;
    }

    $ins = $conn->prepare("
      INSERT INTO enrollments (student_id, year_id, section_id, roll_no, status)
      VALUES (?,?,?,?, 'ENROLLED')
    ");
    $ins->bind_param("iiis", $student_id, $year_id, $section_id, $roll_no);
    $ins->execute();
    $ins->close();

    $conn->commit();
    setFlash("success", "Waa La Diiwaangeliyey", "Ardayga si guul leh ayaa fasalka loogu daray.");
    header("Location: enrollments.php?view=list");
    exit;

  } catch (mysqli_sql_exception $e) {
    $conn->rollback();
    // even if DB has unique error etc.
    setFlash("error", "Qalad DB", $e->getMessage());
    header("Location: enrollments.php?view=add");
    exit;
  }
}

/* =========================
   UPDATE (STRONG FIX)
   ========================= */
if ($action === "update") {
  $enrollment_id = (int)($_POST["enrollment_id"] ?? 0);
  $year_id       = (int)($_POST["year_id"] ?? 0);
  $section_id    = (int)($_POST["section_id"] ?? 0);
  $roll_no       = trim((string)($_POST["roll_no"] ?? ""));

  if ($enrollment_id <= 0 || $year_id <= 0 || $section_id <= 0) {
    setFlash("error", "Xog Dhiman", "Fadlan buuxi: Sanadka iyo Fasalka (Section).");
    header("Location: enrollments.php?view=list");
    exit;
  }

  // Ensure year/section exist
  if (!existsRow($conn, "SELECT 1 FROM academic_years WHERE year_id=? LIMIT 1", "i", [$year_id])) {
    setFlash("error", "Sanad Lama Helin", "Year ID-gan ma jiro. Fadlan dooro sanad sax ah.");
    header("Location: enrollments.php?view=list");
    exit;
  }
  if (!existsRow($conn, "SELECT 1 FROM sections WHERE section_id=? LIMIT 1", "i", [$section_id])) {
    setFlash("error", "Fasal Lama Helin", "Section ID-gan ma jiro. Fadlan dooro fasal sax ah.");
    header("Location: enrollments.php?view=list");
    exit;
  }

  $conn->begin_transaction();
  try {
    // Lock this enrollment row
    $student_id = 0;
    $get = $conn->prepare("
      SELECT student_id
      FROM enrollments
      WHERE enrollment_id=?
      LIMIT 1
      FOR UPDATE
    ");
    $get->bind_param("i", $enrollment_id);
    $get->execute();
    $get->bind_result($student_id);
    $get->fetch();
    $get->close();

    if ((int)$student_id <= 0) {
      $conn->rollback();
      setFlash("error", "Lama Helin", "Enrollment-kan lama helin (ID khaldan).");
      header("Location: enrollments.php?view=list");
      exit;
    }

    // Lock any other ENROLLED record in same year (excluding this enrollment)
    $st2 = $conn->prepare("
      SELECT enrollment_id
      FROM enrollments
      WHERE student_id=? AND year_id=? AND UPPER(status)='ENROLLED' AND enrollment_id<>?
      LIMIT 1
      FOR UPDATE
    ");
    $st2->bind_param("iii", $student_id, $year_id, $enrollment_id);
    $st2->execute();
    $st2->store_result();
    $dup = $st2->num_rows > 0;
    $st2->close();

    if ($dup) {
      $conn->rollback();
      setFlash("error", "Duplicate", "Ardaygaan hore ayaa sanadkan loogu daray fasal kale. Kaliya hal fasal sanadkii.");
      header("Location: enrollments.php?view=list");
      exit;
    }

    // Capacity check (exclude this enrollment)
    $cap = sectionCapacity($conn, $section_id);
    $enr = enrolledCount($conn, $year_id, $section_id, $enrollment_id);
    if ($cap > 0 && $enr >= $cap) {
      $conn->rollback();
      setFlash("error", "Fasal Buuxa", "Fasalkan (Section) waa buuxaa. Fadlan dooro fasal kale.");
      header("Location: enrollments.php?view=list");
      exit;
    }

    // Safety cleanup: drop any other old ENROLLED in same year
    $drop = $conn->prepare("
      UPDATE enrollments
      SET status='DROPPED'
      WHERE student_id=? AND year_id=? AND UPPER(status)='ENROLLED' AND enrollment_id<>?
    ");
    $drop->bind_param("iii", $student_id, $year_id, $enrollment_id);
    $drop->execute();
    $drop->close();

    $up = $conn->prepare("
      UPDATE enrollments
      SET year_id=?, section_id=?, roll_no=?, status='ENROLLED'
      WHERE enrollment_id=?
      LIMIT 1
    ");
    $up->bind_param("iisi", $year_id, $section_id, $roll_no, $enrollment_id);
    $up->execute();
    $up->close();

    $conn->commit();
    setFlash("success", "Waa La Cusbooneysiiyey", "Enrollment-ka si guul leh ayaa loo update gareeyey.");
    header("Location: enrollments.php?view=list");
    exit;

  } catch (mysqli_sql_exception $e) {
    $conn->rollback();
    setFlash("error", "Update Qalad", $e->getMessage());
    header("Location: enrollments.php?view=list");
    exit;
  }
}

/* =========================
   DELETE (DO NOT HARD DELETE)
   ========================= */
if ($action === "delete") {
  $enrollment_id = (int)($_GET["id"] ?? 0);
  if ($enrollment_id <= 0) {
    setFlash("error", "ID Khaldan", "Enrollment ID sax ma aha.");
    header("Location: enrollments.php?view=list");
    exit;
  }

  $conn->begin_transaction();
  try {
    $del = $conn->prepare("
      UPDATE enrollments
      SET status='DROPPED'
      WHERE enrollment_id=?
      LIMIT 1
    ");
    $del->bind_param("i", $enrollment_id);
    $del->execute();
    $del->close();

    $conn->commit();
    setFlash("success", "Waa La Tirtiray", "Enrollment-ka waa la saaray. Ardayga hadda waa Class La'aan.");
    header("Location: enrollments.php?view=list");
    exit;

  } catch (mysqli_sql_exception $e) {
    $conn->rollback();
    setFlash("error", "Delete Qalad", $e->getMessage());
    header("Location: enrollments.php?view=list");
    exit;
  }
}

/* =========================
   LOAD DATA
   ========================= */
$students = [];
$res = $conn->query("
  SELECT student_id, CONCAT(first_name,' ',middle_name,' ',last_name) AS name
  FROM students
  ORDER BY first_name, middle_name, last_name
");
if ($res) while ($r = $res->fetch_assoc()) $students[] = $r;

$sections = [];
$cy = (int)$currentYearId;
$res = $conn->query("
  SELECT s.section_id,
         CONCAT(g.grade_name,'-',s.section_name) AS name,
         s.capacity_max,
         (SELECT COUNT(*)
            FROM enrollments e
           WHERE e.section_id=s.section_id
             AND e.year_id={$cy}
             AND UPPER(e.status)='ENROLLED') AS enrolled_now
  FROM sections s
  JOIN grades g ON g.grade_id = s.grade_id
  ORDER BY g.sort_order, s.section_name
");
if ($res) while ($r = $res->fetch_assoc()) $sections[] = $r;

$enrollments = [];
if ($view === "list") {
  $res = $conn->query("
    SELECT e.enrollment_id,
           st.student_id,
           CONCAT(st.first_name,' ',st.middle_name,' ',st.last_name) AS student,
           ay.year_id, ay.year_name,
           s.section_id,
           CONCAT(g.grade_name,'-',s.section_name) AS section,
           e.roll_no
    FROM enrollments e
    JOIN students st ON st.student_id = e.student_id
    JOIN academic_years ay ON ay.year_id = e.year_id
    JOIN sections s ON s.section_id = e.section_id
    JOIN grades g ON g.grade_id = s.grade_id
    WHERE UPPER(e.status)='ENROLLED'
    ORDER BY e.enrollment_id DESC
  ");
  if ($res) while ($r = $res->fetch_assoc()) $enrollments[] = $r;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Enrollments</title>

<link rel="stylesheet" href="bootstrap.css" />
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
  :root{
    --bg:#f4f7ff; --card:#fff; --text:#0f172a; --muted:#64748b; --border:#dbe6f7;
    --primary:#2563eb; --danger:#ef4444; --shadow:0 18px 55px rgba(2,6,23,.08);
    --radius:18px;
  }
  body{ background:var(--bg); color:var(--text); font-family: Arial, sans-serif; }
  .wrap{ max-width:1200px; margin:20px auto; padding:0 14px 30px; }
  .cardx{ background:var(--card); border:1px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow); }
  .head{ padding:14px 16px; display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
  .title{ font-weight:900; font-size:18px; margin:0; }
  .sub{ color:var(--muted); font-weight:700; font-size:13px; }
  .btnRound{ border-radius:999px !important; font-weight:900; }
  .table thead th{ font-size:12px; text-transform:uppercase; letter-spacing:.6px; color:var(--muted); border-bottom-color:var(--border)!important; }
  .table td,.table th{ border-top-color:var(--border)!important; vertical-align:middle; font-weight:800; }
  .chip{
    display:inline-flex; align-items:center; gap:8px;
    padding:6px 10px; border-radius:999px; border:1px solid var(--border);
    background:rgba(37,99,235,.08); color:var(--primary); font-weight:900; font-size:12px;
  }
  .ac-wrap{ position:relative; }
  .ac-box{
    position:absolute; z-index:9999; top:100%; left:0; right:0;
    background:#fff; border:1px solid var(--border); border-radius:14px;
    box-shadow: 0 18px 55px rgba(2,6,23,.10);
    max-height: 260px; overflow:auto; display:none;
  }
  .ac-item{
    padding:10px 12px; cursor:pointer; font-weight:800;
    border-bottom:1px solid rgba(219,230,247,.8);
  }
  .ac-item:last-child{ border-bottom:none; }
  .ac-item:hover{ background: rgba(37,99,235,.08); }
  .muted{ color:var(--muted); font-weight:800; }
</style>
</head>
<body>

<div class="wrap">

  <div class="cardx mb-3">
    <div class="head">
      <div>
        <p class="title mb-1">Enrollments</p>
        <div class="sub">Add / List / Edit / Delete (Duplicate Block + Capacity)</div>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <a href="enrollments.php?view=add" class="btn btn<?= $view==="add" ? "" : "-outline" ?>-primary btnRound">Add Page</a>
        <a href="enrollments.php?view=list" class="btn btn<?= $view==="list" ? "" : "-outline" ?>-primary btnRound">List Page</a>
      </div>
    </div>
  </div>

  <?php if ($view === "add"): ?>
    <div class="cardx p-3 mb-3">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
        <div style="font-weight:900;">Arday Fasalka Ku Dar</div>
        <span class="chip">Qor magac → ka dooro liiska</span>
      </div>

      <form method="post" class="row g-3" autocomplete="off" id="addForm">
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="student_id" id="student_id" value="">

        <div class="col-md-6 ac-wrap">
          <label class="form-label" style="font-weight:900;">Magaca Ardayga</label>
          <input type="text" class="form-control" id="student_name" placeholder="Qor magaca..." required />
          <div class="ac-box" id="acBox"></div>
          <div class="muted mt-1" style="font-size:12px;">
            Qor 2 xaraf → magacyada ayaa kuu soo baxaya. (Haddii ardaygu sanadkan hore u enrolled yahay, system-ku wuu diidayaa.)
          </div>
        </div>

        <div class="col-md-3">
          <label class="form-label" style="font-weight:900;">Sanad Dugsiyeed</label>
          <select name="year_id" class="form-control" required>
            <option value="">-- Dooro Sanad --</option>
            <?php foreach ($years as $y): ?>
              <?php $sel = ($currentYearId>0 && (int)$y["year_id"] === $currentYearId) ? "selected" : ""; ?>
              <option value="<?= (int)$y["year_id"] ?>" <?= $sel ?>>
                <?= h($y["year_name"]) ?><?= ((int)$y["is_current"]===1) ? " (Current)" : "" ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-3">
          <label class="form-label" style="font-weight:900;">Fasal (Section)</label>
          <select name="section_id" class="form-control" required>
            <option value="">-- Dooro Fasal --</option>
            <?php foreach ($sections as $s): ?>
              <?php
                $sid = (int)$s["section_id"];
                $cap = (int)$s["capacity_max"];
                $enr = (int)$s["enrolled_now"];
                $full = ($cap > 0 && $enr >= $cap);
                $label = h($s["name"]) . " (" . $enr . "/" . $cap . ")";
              ?>
              <option value="<?= $sid ?>" <?= $full ? "disabled" : "" ?>>
                <?= $label ?><?= $full ? " — BUUXA" : "" ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="muted mt-1" style="font-size:12px;">Fasalka buuxa lama dooran karo (Current Year count).</div>
        </div>

        <div class="col-md-3">
          <label class="form-label" style="font-weight:900;">Roll No (ikhtiyaari)</label>
          <input name="roll_no" class="form-control" placeholder="tusaale: 12A" maxlength="30" />
        </div>

        <div class="col-12 d-flex gap-2 flex-wrap">
          <button class="btn btn-success btnRound" type="submit">Diiwaangeli</button>
          <a class="btn btn-outline-primary btnRound" href="enrollments.php?view=list">Tag List</a>
        </div>
      </form>
    </div>

  <?php else: ?>
    <div class="cardx p-3 mb-2">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div style="font-weight:900;">Enrollment List</div>
        <div class="d-flex gap-2 flex-wrap">
          <input id="searchBox" class="form-control" style="max-width:360px;" placeholder="Raadi (student / year / section / roll)" />
          <a class="btn btn-outline-success btnRound" href="enrollments.php?view=add">+ Ku Dar Cusub</a>
        </div>
      </div>
    </div>

    <div class="cardx p-2">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="enrollTable">
          <thead>
            <tr>
              <th>#</th>
              <th>Arday</th>
              <th>Sanad</th>
              <th>Fasal</th>
              <th>Roll</th>
              <th style="width:220px;">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (count($enrollments) === 0): ?>
            <tr>
              <td colspan="6" class="text-center" style="padding:18px; color:var(--muted); font-weight:800;">
                Wax enrollments ah lama helin.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($enrollments as $e): ?>
              <tr class="rowItem"
                  data-id="<?= (int)$e["enrollment_id"] ?>"
                  data-year="<?= (int)$e["year_id"] ?>"
                  data-section="<?= (int)$e["section_id"] ?>"
                  data-roll="<?= h($e["roll_no"]) ?>">
                <td><?= (int)$e["enrollment_id"] ?></td>
                <td><?= h($e["student"]) ?></td>
                <td><?= h($e["year_name"]) ?></td>
                <td><?= h($e["section"]) ?></td>
                <td><?= h($e["roll_no"]) ?></td>
                <td>
                  <div class="d-flex gap-2 flex-wrap">
                    <button type="button" class="btn btn-outline-primary btn-sm btnRound btnEdit">Edit</button>
                    <a href="enrollments.php?action=delete&id=<?= (int)$e["enrollment_id"] ?>&view=list"
                       class="btn btn-outline-danger btn-sm btnRound btnDelete">Delete</a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

</div>

<?php if ($flash): ?>
<script>
Swal.fire({
  icon: <?= json_encode($flash["type"]) ?>,
  title: <?= json_encode($flash["title"]) ?>,
  text: <?= json_encode($flash["msg"]) ?>,
  confirmButtonColor: "#2563eb",
  width: 560
});
</script>
<?php endif; ?>

<script>
// Delete confirm
document.querySelectorAll(".btnDelete").forEach(btn=>{
  btn.addEventListener("click", (e)=>{
    e.preventDefault();
    const url = btn.getAttribute("href");
    Swal.fire({
      icon: "warning",
      title: "Ma Ka Saaraysaa Ardayga Fasalka?",
      text: "Ardayga wuxuu noqonayaa Class La'aan (DROPPED).",
      showCancelButton: true,
      confirmButtonText: "Haa, saar",
      cancelButtonText: "Ka noqo",
      confirmButtonColor: "#ef4444",
      cancelButtonColor: "#64748b",
      width: 560
    }).then(r=>{
      if(r.isConfirmed) window.location.href = url;
    });
  });
});

// Table search
const searchBox = document.getElementById("searchBox");
if(searchBox){
  const rows = document.querySelectorAll("#enrollTable tbody .rowItem");
  searchBox.addEventListener("input", ()=>{
    const q = (searchBox.value || "").toLowerCase().trim();
    rows.forEach(r=>{
      r.style.display = r.innerText.toLowerCase().includes(q) ? "" : "none";
    });
  });
}

// Options for edit modal
const yearsOptions = `<?php
  $opt = '';
  foreach ($years as $y) {
    $id = (int)$y["year_id"];
    $name = h($y["year_name"]) . (((int)$y["is_current"]===1) ? " (Current)" : "");
    $opt .= "<option value='{$id}'>".str_replace("'", "&#039;", $name)."</option>";
  }
  echo $opt;
?>`;

const sectionsOptions = `<?php
  $opt = '';
  foreach ($sections as $s) {
    $id = (int)$s["section_id"];
    $cap = (int)$s["capacity_max"];
    $enr = (int)$s["enrolled_now"];
    $full = ($cap > 0 && $enr >= $cap);
    $name = h($s["name"])." ({$enr}/{$cap})".($full ? " — BUUXA" : "");
    $opt .= "<option value='{$id}'>".str_replace("'", "&#039;", $name)."</option>";
  }
  echo $opt;
?>`;


document.querySelectorAll(".btnEdit").forEach(btn=>{
  btn.addEventListener("click", async ()=>{
    const tr = btn.closest("tr");
    const enrollmentId = tr.getAttribute("data-id");
    const curYear = tr.getAttribute("data-year");
    const curSection = tr.getAttribute("data-section");
    const curRoll = tr.getAttribute("data-roll") || "";

    const { value: formValues } = await Swal.fire({
      title: "Beddel Enrollment",
      html: `
        <label style="font-weight:900;display:block;text-align:left;margin-top:8px;">Sanad</label>
        <select id="sw_year" class="swal2-input" style="width:100%;margin:6px 0 0;">${yearsOptions}</select>

        <label style="font-weight:900;display:block;text-align:left;margin-top:10px;">Fasal (Section)</label>
        <select id="sw_section" class="swal2-input" style="width:100%;margin:6px 0 0;">${sectionsOptions}</select>

        <label style="font-weight:900;display:block;text-align:left;margin-top:10px;">Roll No</label>
        <input id="sw_roll" class="swal2-input" placeholder="tusaale: 12A" value="${curRoll.replace(/"/g,'&quot;')}">
        <div style="text-align:left;color:#64748b;font-weight:800;font-size:12px;margin-top:6px;">
          Duplicate lama ogola (1 arday + 1 year). Capacity server ayaa hubinaya.
        </div>
      `,
      showCancelButton: true,
      confirmButtonText: "Update",
      cancelButtonText: "Ka noqo",
      confirmButtonColor: "#2563eb",
      width: 620,
      didOpen: () => {
        document.getElementById("sw_year").value = curYear || "";
        document.getElementById("sw_section").value = curSection || "";
      },
      preConfirm: () => {
        const year = document.getElementById("sw_year").value;
        const sec  = document.getElementById("sw_section").value;
        const roll = document.getElementById("sw_roll").value;
        if(!year || !sec){
          Swal.showValidationMessage("Fadlan dooro Sanad iyo Fasal.");
          return false;
        }
        return { enrollmentId, year, sec, roll };
      }
    });

    if(formValues){
      const f = document.createElement("form");
      f.method = "POST";
      f.action = "enrollments.php?view=list";
      f.innerHTML = `
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="enrollment_id" value="${formValues.enrollmentId}">
        <input type="hidden" name="year_id" value="${formValues.year}">
        <input type="hidden" name="section_id" value="${formValues.sec}">
        <input type="hidden" name="roll_no" value="${(formValues.roll || "").replace(/"/g,'&quot;')}">
      `;
      document.body.appendChild(f);
      f.submit();
    }
  });
});

// Autocomplete
const nameInput = document.getElementById("student_name");
const idHidden  = document.getElementById("student_id");
const acBox     = document.getElementById("acBox");

const STUDENTS = <?php
  $arr = [];
  foreach ($students as $st) $arr[] = ["id" => (int)$st["student_id"], "name" => (string)$st["name"]];
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
?>;

function closeAC(){
  if(acBox){ acBox.style.display="none"; acBox.innerHTML=""; }
}
function showAC(items){
  if(!acBox) return;
  acBox.innerHTML = "";
  if(items.length === 0){ closeAC(); return; }
  items.slice(0, 12).forEach(it=>{
    const div = document.createElement("div");
    div.className = "ac-item";
    div.textContent = it.name;
    div.addEventListener("click", ()=>{
      nameInput.value = it.name;
      idHidden.value = it.id;
      closeAC();
    });
    acBox.appendChild(div);
  });
  acBox.style.display="block";
}

if(nameInput && idHidden && acBox){
  nameInput.addEventListener("input", ()=>{
    const q = (nameInput.value || "").trim().toLowerCase();
    idHidden.value = "";
    if(q.length < 2){ closeAC(); return; }
    const found = STUDENTS.filter(s => (s.name || "").toLowerCase().includes(q));
    showAC(found);
  });

  document.addEventListener("click", (e)=>{
    if(!acBox.contains(e.target) && e.target !== nameInput) closeAC();
  });

  const addForm = document.getElementById("addForm");
  if(addForm){
    addForm.addEventListener("submit", (e)=>{
      if(!idHidden.value){
        e.preventDefault();
        Swal.fire({
          icon: "error",
          title: "Arday lama dooran",
          text: "Fadlan ardayga ka dooro liiska suggestions-ka.",
          confirmButtonColor: "#2563eb",
          width: 560
        });
      }
    });
  }
}
</script>

</body>
</html>
