<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . "/conncation.php";

if (!isset($_SESSION["user_id"]) || !in_array($_SESSION["role"], ["ADMIN","RECEPTION"], true)) {
  header("Location: login.php");
  exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8"); }
function setFlash(string $type, string $msg): void { $_SESSION["flash"] = ["type"=>$type, "msg"=>$msg]; }

/* =========================
   PHOTO SRC BUILDER
   ========================= */
$BASE_URL = ""; // haddii project-kaagu ku jiro subfolder -> "/SCHOOL"
function photoSrc(string $raw, string $BASE_URL): string {
  $p = trim($raw);
  if ($p === "") return "";
  if (preg_match('~^https?://~i', $p)) return $p;
  if (preg_match('~^data:image/~i', $p)) return $p;

  $p = str_replace("\\", "/", $p);

  if (strpos($p, "/") === false) $p = "uploads/students/" . $p;

  if (str_starts_with($p, "/")) return rtrim($BASE_URL, "/") . $p;
  return rtrim($BASE_URL, "/") . "/" . ltrim($p, "/");
}

/* =========================
   CURRENT YEAR (fallback)
   ========================= */
$currentYearId = 0;
$r = $conn->query("SELECT year_id FROM academic_years WHERE is_current=1 ORDER BY start_date DESC LIMIT 1")->fetch_assoc();
if ($r && (int)$r["year_id"] > 0) $currentYearId = (int)$r["year_id"];
if ($currentYearId <= 0) {
  $r = $conn->query("SELECT year_id FROM academic_years ORDER BY start_date DESC LIMIT 1")->fetch_assoc();
  if ($r && (int)$r["year_id"] > 0) $currentYearId = (int)$r["year_id"];
}

/* =========================
   FILTERS
   ========================= */
$sectionId = (int)($_GET["section_id"] ?? 0);
$yearId    = (int)($_GET["year_id"] ?? 0);
if ($yearId <= 0) $yearId = $currentYearId;

/* =========================
   SEARCH (q)
   ========================= */
$q = trim((string)($_GET["q"] ?? ""));
$hasQ = ($q !== "");
$qLike = "%".$q."%";
$qid = (int)$q;

/* =========================
   DELETE (SAFE) + keep filters
   ========================= */
if (isset($_GET["delete"])) {
  $id = (int)($_GET["delete"] ?? 0);

  $back = "students.php";
  $qs = [];
  if ($sectionId > 0) { $qs["section_id"] = $sectionId; $qs["year_id"] = $yearId; }
  if ($q !== "") $qs["q"] = $q;
  if (!empty($qs)) $back .= "?".http_build_query($qs);

  if ($id <= 0) {
    setFlash("error", "ID-ga ardayga ma saxna.");
    header("Location: ".$back);
    exit;
  }

  $conn->begin_transaction();
  try {
    $enrIds = [];
    $st = $conn->prepare("SELECT enrollment_id FROM enrollments WHERE student_id=?");
    $st->bind_param("i", $id);
    $st->execute();
    $rs = $st->get_result();
    while($row = $rs->fetch_assoc()) $enrIds[] = (int)$row["enrollment_id"];
    $st->close();

    $invIds = [];
    if (count($enrIds) > 0) {
      $in = implode(",", array_fill(0, count($enrIds), "?"));
      $types = str_repeat("i", count($enrIds));
      $st = $conn->prepare("SELECT invoice_id FROM student_invoices WHERE enrollment_id IN ($in)");
      $st->bind_param($types, ...$enrIds);
      $st->execute();
      $rs = $st->get_result();
      while($row = $rs->fetch_assoc()) $invIds[] = (int)$row["invoice_id"];
      $st->close();
    }

    if (count($invIds) > 0) {
      $in = implode(",", array_fill(0, count($invIds), "?"));
      $types = str_repeat("i", count($invIds));

      $st = $conn->prepare("DELETE FROM payments WHERE invoice_id IN ($in)");
      $st->bind_param($types, ...$invIds);
      $st->execute();
      $st->close();

      $st = $conn->prepare("DELETE FROM invoice_items WHERE invoice_id IN ($in)");
      $st->bind_param($types, ...$invIds);
      $st->execute();
      $st->close();

      $st = $conn->prepare("DELETE FROM student_invoices WHERE invoice_id IN ($in)");
      $st->bind_param($types, ...$invIds);
      $st->execute();
      $st->close();
    }

    // NOTE: haddii aad rabto soft-delete enrollments, halkan ka beddel:
    // UPDATE enrollments SET status='DROPPED' WHERE student_id=?
    $st = $conn->prepare("DELETE FROM enrollments WHERE student_id=?");
    $st->bind_param("i", $id);
    $st->execute();
    $st->close();

    $st = $conn->prepare("DELETE FROM students WHERE student_id=?");
    $st->bind_param("i", $id);
    $st->execute();
    $st->close();

    $conn->commit();
    setFlash("success", "Ardayga waa la tirtiray ✅");
  } catch (Throwable $e) {
    $conn->rollback();
    setFlash("error", "Tirtiriddu way fashilantay: ".$e->getMessage());
  }

  header("Location: ".$back);
  exit;
}

/* =========================
   FLASH
   ========================= */
$flash = $_SESSION["flash"] ?? null;
unset($_SESSION["flash"]);

/* =========================
   LOAD DROPDOWNS
   ========================= */
$years = [];
$res = $conn->query("SELECT year_id, year_name, is_current FROM academic_years ORDER BY is_current DESC, start_date DESC");
if ($res) while($x=$res->fetch_assoc()) $years[] = $x;

$sectionsAll = [];
$res = $conn->query("
  SELECT s.section_id, s.section_name, g.grade_name, g.sort_order
  FROM sections s
  JOIN grades g ON g.grade_id = s.grade_id
  ORDER BY g.sort_order ASC, s.section_name ASC
");
if ($res) while($x=$res->fetch_assoc()) $sectionsAll[] = $x;

/* =========================
   CLASS LABEL (header)
   ========================= */
$classLabel = "";
if ($sectionId > 0) {
  $st = $conn->prepare("
    SELECT CONCAT(g.grade_name,'-',s.section_name) AS class_name
    FROM sections s
    JOIN grades g ON g.grade_id = s.grade_id
    WHERE s.section_id = ?
    LIMIT 1
  ");
  $st->bind_param("i", $sectionId);
  $st->execute();
  $rr = $st->get_result()->fetch_assoc();
  $st->close();
  $classLabel = $rr ? (string)($rr["class_name"] ?? "") : "";
}

/* =========================
   QUERY STUDENTS  (FIXED ✅)
   ========================= */
$rows = [];

if ($sectionId > 0) {
  $sql = "
    SELECT
      s.student_id,
      s.admission_no,
      CONCAT(s.first_name,' ',s.middle_name,' ',s.last_name) AS fullname,
      s.profile_photo_url,
      s.phone,
      e.year_id,
      y.year_name,
      e.section_id,
      CONCAT(g.grade_name,'-',sec.section_name) AS class_name,
      e.roll_no
    FROM enrollments e
    JOIN students s ON s.student_id = e.student_id
    JOIN academic_years y ON y.year_id = e.year_id
    JOIN sections sec ON sec.section_id = e.section_id
    JOIN grades g ON g.grade_id = sec.grade_id
    WHERE UPPER(e.status)='ENROLLED'
      AND e.section_id = ?
      AND e.year_id = ?
  ";
  if ($hasQ) {
    $sql .= "
      AND (
        s.student_id = ?
        OR CONCAT(s.first_name,' ',s.middle_name,' ',s.last_name) LIKE ?
        OR s.admission_no LIKE ?
        OR s.phone LIKE ?
        OR CONCAT(g.grade_name,'-',sec.section_name) LIKE ?
      )
    ";
  }
  $sql .= " ORDER BY s.student_id DESC ";

  $stmt = $conn->prepare($sql);
  if ($hasQ) $stmt->bind_param("iiissss", $sectionId, $yearId, $qid, $qLike, $qLike, $qLike, $qLike);
  else $stmt->bind_param("ii", $sectionId, $yearId);

  $stmt->execute();
  $rs = $stmt->get_result();
  while($r = $rs->fetch_assoc()) $rows[] = $r;
  $stmt->close();

} else {
  // ✅ FIX: use $yearId (selected), not $currentYearId
  $sql = "
    SELECT
      s.student_id,
      s.admission_no,
      CONCAT(s.first_name,' ',s.middle_name,' ',s.last_name) AS fullname,
      s.profile_photo_url,
      s.phone,

      COALESCE(ec.year_id, el.year_id) AS year_id,
      COALESCE(yc.year_name, yl.year_name) AS year_name,
      COALESCE(ec.section_id, el.section_id) AS section_id,
      COALESCE(CONCAT(gc.grade_name,'-',sc.section_name), CONCAT(gl.grade_name,'-',sl.section_name)) AS class_name,
      COALESCE(ec.roll_no, el.roll_no) AS roll_no

    FROM students s

    LEFT JOIN enrollments ec
      ON ec.student_id = s.student_id
     AND ec.year_id = ?
     AND UPPER(ec.status)='ENROLLED'
    LEFT JOIN academic_years yc ON yc.year_id = ec.year_id
    LEFT JOIN sections sc ON sc.section_id = ec.section_id
    LEFT JOIN grades gc ON gc.grade_id = sc.grade_id

    LEFT JOIN (
      SELECT e1.*
      FROM enrollments e1
      JOIN (
        SELECT student_id, MAX(enrollment_id) AS mx
        FROM enrollments
        WHERE UPPER(status)='ENROLLED'
        GROUP BY student_id
      ) t ON t.student_id = e1.student_id AND t.mx = e1.enrollment_id
      WHERE UPPER(e1.status)='ENROLLED'
    ) el ON el.student_id = s.student_id
    LEFT JOIN academic_years yl ON yl.year_id = el.year_id
    LEFT JOIN sections sl ON sl.section_id = el.section_id
    LEFT JOIN grades gl ON gl.grade_id = sl.grade_id
  ";
  if ($hasQ) {
    $sql .= "
      WHERE (
        s.student_id = ?
        OR CONCAT(s.first_name,' ',s.middle_name,' ',s.last_name) LIKE ?
        OR s.admission_no LIKE ?
        OR s.phone LIKE ?
        OR COALESCE(CONCAT(gc.grade_name,'-',sc.section_name), CONCAT(gl.grade_name,'-',sl.section_name)) LIKE ?
      )
    ";
  }
  $sql .= " ORDER BY s.student_id DESC ";

  $stmt = $conn->prepare($sql);
  if ($hasQ) $stmt->bind_param("iissss", $yearId, $qid, $qLike, $qLike, $qLike, $qLike);
  else $stmt->bind_param("i", $yearId);

  $stmt->execute();
  $rs = $stmt->get_result();
  while($r = $rs->fetch_assoc()) $rows[] = $r;
  $stmt->close();
}

function initials(string $name): string {
  $name = trim(preg_replace('/\s+/', ' ', $name));
  if ($name === "") return "S";
  $parts = explode(" ", $name);
  $a = mb_substr($parts[0], 0, 1);
  $b = (count($parts) > 1) ? mb_substr($parts[1], 0, 1) : "";
  $out = mb_strtoupper($a.$b);
  return $out ?: "S";
}

$keepQs = [];
if ($sectionId > 0) { $keepQs["section_id"] = $sectionId; $keepQs["year_id"] = $yearId; }
if ($q !== "") $keepQs["q"] = $q;
$keepQuery = http_build_query($keepQs);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Students</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="stylesheet" href="bootstrap.css" />
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <style>
    :root{
      --bg:#f4f7ff; --card:#ffffff; --text:#0f172a; --muted:#64748b;
      --border:#e6edf8; --shadow:0 16px 45px rgba(2,6,23,.08); --radius:18px;
    }
    body{ background:var(--bg); color:var(--text); }
    .pageWrap{ max-width:1250px; margin:18px auto; padding:0 14px 30px; }

    .headerRow{
      background:var(--card); border:1px solid var(--border); border-radius:var(--radius);
      box-shadow:var(--shadow); padding:14px 16px;
      display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;
    }
    .crumb{
      font-weight:1000; color:var(--muted); font-size:13px;
      display:flex; gap:8px; align-items:center; flex-wrap:wrap;
    }
    .crumb b{ color:var(--text); }
    .pill{
      background:#eef2ff; border:1px solid #dbeafe; color:#1d4ed8;
      padding:4px 10px; border-radius:999px; font-size:12px; font-weight:1000; text-decoration:none;
    }

    .tools{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; }

    .searchBox{
      display:flex; gap:10px; align-items:center; background:#f8fafc;
      border:1px solid var(--border); border-radius:14px; padding:8px 10px;
      min-width:min(520px, 92vw);
    }
    .searchBox input{
      border:none !important; outline:none !important; background:transparent !important;
      width:100%; font-weight:900; color:var(--text);
    }
    .miniSelect{
      border:1px solid var(--border); border-radius:14px; padding:9px 10px; background:#fff;
      font-weight:900; color:#0f172a; min-width: 220px;
    }

    .btnAdd{ border-radius:14px; font-weight:1000; padding:10px 14px; }

    .grid{
      margin-top:14px;
      display:grid;
      grid-template-columns: repeat(4, 1fr);
      gap:14px;
    }
    @media(max-width:1100px){ .grid{ grid-template-columns: repeat(3, 1fr);} }
    @media(max-width:820px){ .grid{ grid-template-columns: repeat(2, 1fr);} }
    @media(max-width:520px){ .grid{ grid-template-columns: 1fr;} }

    .studentCard{
      background:var(--card); border:1px solid var(--border); border-radius:18px;
      box-shadow:var(--shadow); padding:16px;
      display:flex; flex-direction:column; align-items:center; text-align:center; gap:10px;
      min-height:260px; position:relative;
    }

    .idTag{
      position:absolute; top:12px; left:12px;
      background:#0f172a; color:#fff; font-weight:1000; font-size:12px;
      padding:4px 10px; border-radius:999px; opacity:.92;
    }

    .avatarCircle{
      width:86px; height:86px; border-radius:999px;
      border:1px solid var(--border); background:#eef2ff;
      display:flex; align-items:center; justify-content:center;
      overflow:hidden; position:relative;
    }
    .avatarCircle img{
      width:100%; height:100%; object-fit:cover; display:block;
    }
    .avatarInitial{
      position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
      font-weight:1000; font-size:22px; color:#1d4ed8;
    }

    .stName{ font-weight:1000; font-size:16px; margin:0; line-height:1.2; }
    .stMeta{ margin:0; color:var(--muted); font-weight:900; font-size:12px; }

    .badgeClass{
      margin-top:2px; display:inline-block; padding:5px 10px; border-radius:999px;
      font-weight:1000; font-size:12px; border:1px solid var(--border);
      background:#f8fafc; color:#334155;
    }

    .actionsRow{
      margin-top:auto; display:flex; gap:10px; align-items:center; justify-content:center; width:100%;
    }
    .roundAction{
      width:44px; height:44px; border-radius:999px; border:none;
      display:inline-flex; align-items:center; justify-content:center; cursor:pointer;
      box-shadow:0 10px 25px rgba(2,6,23,.10);
      text-decoration:none;
    }
    .aView{ background:#e0e7ff; }
    .aEdit{ background:#dcfce7; }
    .aDel{ background:#fee2e2; }
    .roundAction svg{ width:18px; height:18px; }

    .addCard{
      background:#fff; border:2px dashed #93c5fd; border-radius:18px;
      display:flex; align-items:center; justify-content:center; min-height:250px;
      box-shadow:var(--shadow); text-decoration:none; color:#2563eb;
    }
    .addCard .inner{ text-align:center; font-weight:1000; }
    .addCard .plus{
      width:54px;height:54px; border-radius:16px; border:1px solid #bfdbfe;
      display:flex; align-items:center; justify-content:center;
      margin:0 auto 10px; background:#eff6ff;
    }

    .hint{ margin-top:10px; font-weight:900; color:#64748b; font-size:12px; }
    .tinyBtn{
      border-radius:12px; font-weight:1000; padding:10px 12px;
      border:1px solid var(--border); background:#fff; cursor:pointer;
    }
    .noRes{
      grid-column: 1 / -1;
      background:#fff; border:1px solid var(--border); border-radius:18px;
      box-shadow:var(--shadow); padding:18px; text-align:center;
      font-weight:1000; color:#64748b;
    }
  </style>
</head>
<body class="p-3">

<div class="pageWrap">

  <div class="headerRow">
    <div class="crumb">
      <b>Students</b> <span>›</span>
      <span><?= ($sectionId>0 ? "Class Students" : "All Students") ?></span>

      <span class="pill">Year: <?= (int)$yearId ?></span>

      <?php if($sectionId>0): ?>
        <span class="pill"><?= h($classLabel !== "" ? $classLabel : ("Section #".$sectionId)) ?></span>
        <a class="pill" href="students.php">Show All</a>
      <?php endif; ?>
    </div>

    <div class="tools">
      <a class="btn btn-primary btnAdd" href="students_add.php">+ Add New</a>
      <button class="tinyBtn" type="button" id="btnReload">↻ Reload</button>
    </div>

    <div class="tools" style="width:100%; justify-content:space-between;">
      <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
        <select class="miniSelect" id="yearFilter">
          <?php foreach($years as $y): ?>
            <option value="<?= (int)$y["year_id"] ?>" <?= ((int)$y["year_id"]===$yearId ? "selected" : "") ?>>
              <?= h($y["year_name"]) ?><?= ((int)$y["is_current"]===1 ? " (Current)" : "") ?>
            </option>
          <?php endforeach; ?>
        </select>

        <select class="miniSelect" id="classFilter">
          <option value="0">All Classes</option>
          <?php foreach($sectionsAll as $s): ?>
            <option value="<?= (int)$s["section_id"] ?>" <?= ((int)$s["section_id"]===$sectionId ? "selected" : "") ?>>
              <?= h($s["grade_name"]." - ".$s["section_name"]) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="searchBox">
        <input
          id="liveSearch"
          type="text"
          value="<?= h($q) ?>"
          placeholder="Live Search: ID / Admission / Name / Phone / Class ..."
          autocomplete="off"
        />
        <button class="tinyBtn" type="button" id="btnGo">Search</button>
        <button class="tinyBtn" type="button" id="btnClear">Clear</button>
      </div>
    </div>

    <div class="hint">
      ✅ Live Search + Server Search: ID / Admission / Phone / Name / Class.
    </div>
  </div>

  <div class="grid" id="grid">

    <?php if(count($rows) === 0): ?>
      <div class="noRes">
        Arday lama helin
        <?= $q!=="" ? " (Search: ".h($q).")" : "" ?>
        <?= $sectionId>0 ? " gudaha class-kan." : "." ?>
      </div>

      <a class="addCard" href="students_add.php">
        <div class="inner">
          <div class="plus">
            <svg viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <line x1="12" y1="5" x2="12" y2="19"></line>
              <line x1="5" y1="12" x2="19" y2="12"></line>
            </svg>
          </div>
          Add New
        </div>
      </a>

    <?php else: ?>

      <?php foreach($rows as $r): ?>
        <?php
          $sid  = (int)($r["student_id"] ?? 0);
          $full = (string)($r["fullname"] ?? "");
          $adNo = (string)($r["admission_no"] ?? "");
          $phone = (string)($r["phone"] ?? "");
          $className = trim((string)($r["class_name"] ?? ""));

          $photoRaw = (string)($r["profile_photo_url"] ?? "");
          $photo = photoSrc($photoRaw, $BASE_URL);

          $key = strtolower(trim($sid." ".$adNo." ".$full." ".$phone." ".$className));

          $ini = initials($full);

          $viewQs = $keepQuery ? ("&".$keepQuery) : "";
        ?>
        <div class="studentCard item" data-key="<?= h($key) ?>">
          <div class="idTag">ID: <?= (int)$sid ?></div>

          <div class="avatarCircle">
            <span class="avatarInitial" style="<?= $photo !== "" ? "display:none;" : "display:flex;" ?>">
              <?= h($ini) ?>
            </span>

            <?php if($photo !== ""): ?>
              <img
                src="<?= h($photo) ?>"
                alt="photo"
                onload="this.closest('.avatarCircle').querySelector('.avatarInitial').style.display='none';"
                onerror="this.style.display='none'; this.closest('.avatarCircle').querySelector('.avatarInitial').style.display='flex';"
              >
            <?php endif; ?>
          </div>

          <p class="stName"><?= h($full !== "" ? $full : "—") ?></p>

          <span class="badgeClass"><?= h($className !== "" ? $className : "Not Enrolled") ?></span>

          <p class="stMeta">Admission: <?= h($adNo !== "" ? $adNo : "—") ?></p>
          <p class="stMeta">Phone: <?= h($phone !== "" ? $phone : "—") ?></p>

          <div class="actionsRow">
            <a class="roundAction aView" href="students_view.php?id=<?= (int)$sid ?><?= $viewQs ?>" title="View">
              <svg viewBox="0 0 24 24" fill="none" stroke="#1d4ed8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"></path>
                <circle cx="12" cy="12" r="3"></circle>
              </svg>
            </a>

            <a class="roundAction aEdit" href="students_add.php?id=<?= (int)$sid ?><?= $keepQuery ? ("&".$keepQuery) : "" ?>" title="Edit">
              <svg viewBox="0 0 24 24" fill="none" stroke="#166534" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 20h9"></path>
                <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"></path>
              </svg>
            </a>

            <button class="roundAction aDel btnDel" type="button" title="Delete"
              data-id="<?= (int)$sid ?>"
              data-name="<?= h($full) ?>">
              <svg viewBox="0 0 24 24" fill="none" stroke="#b91c1c" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="3 6 5 6 21 6"></polyline>
                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>
                <path d="M10 11v6"></path>
                <path d="M14 11v6"></path>
                <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"></path>
              </svg>
            </button>
          </div>
        </div>
      <?php endforeach; ?>

      <a class="addCard" href="students_add.php">
        <div class="inner">
          <div class="plus">
            <svg viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <line x1="12" y1="5" x2="12" y2="19"></line>
              <line x1="5" y1="12" x2="19" y2="12"></line>
            </svg>
          </div>
          Add New
        </div>
      </a>

    <?php endif; ?>
  </div>

  <div class="hint" id="countHint"></div>
</div>

<script>
  <?php if ($flash): ?>
  Swal.fire({
    icon: <?= json_encode($flash["type"]) ?>,
    title: <?= json_encode($flash["type"] === "success" ? "Guul ✅" : "Fariin") ?>,
    text: <?= json_encode($flash["msg"]) ?>,
    confirmButtonText: "Haye",
    confirmButtonColor: "#3b82f6",
    width: 650
  });
  <?php endif; ?>

  function buildUrl(params){
    const url = new URL(window.location.href);
    url.search = "";
    Object.keys(params).forEach(k=>{
      if(params[k] !== null && params[k] !== undefined && params[k] !== "" && params[k] !== "0"){
        url.searchParams.set(k, params[k]);
      }
    });
    return url.toString();
  }

  const yearFilter  = document.getElementById("yearFilter");
  const classFilter = document.getElementById("classFilter");
  const liveSearch  = document.getElementById("liveSearch");
  const btnGo       = document.getElementById("btnGo");
  const btnClear    = document.getElementById("btnClear");
  const btnReload   = document.getElementById("btnReload");
  const items       = Array.from(document.querySelectorAll(".item"));
  const countHint   = document.getElementById("countHint");

  function updateCount(){
    const visible = items.filter(x => x.style.display !== "none").length;
    if(items.length === 0) { countHint.textContent = ""; return; }
    countHint.textContent = "✅ Hadda muuqda: " + visible + " / " + items.length;
  }

  function applyLiveFilter(){
    const q = (liveSearch.value || "").trim().toLowerCase();
    if(!q){
      items.forEach(x=> x.style.display = "");
      updateCount();
      return;
    }
    items.forEach(card=>{
      const key = (card.dataset.key || "");
      card.style.display = key.includes(q) ? "" : "none";
    });
    updateCount();
  }
  liveSearch.addEventListener("input", applyLiveFilter);

  btnGo.addEventListener("click", ()=>{
    const params = {
      year_id: yearFilter.value || "",
      section_id: classFilter.value || "0",
      q: (liveSearch.value || "").trim()
    };
    window.location.href = buildUrl(params);
  });

  btnClear.addEventListener("click", ()=>{
    liveSearch.value = "";
    applyLiveFilter();
    const params = {
      year_id: yearFilter.value || "",
      section_id: classFilter.value || "0"
    };
    window.location.href = buildUrl(params);
  });

  btnReload.addEventListener("click", ()=>{
    const params = {
      year_id: yearFilter.value || "",
      section_id: classFilter.value || "0",
      q: (<?= json_encode($q) ?> || "").trim()
    };
    window.location.href = buildUrl(params);
  });

  yearFilter.addEventListener("change", ()=>{
    const params = {
      year_id: yearFilter.value || "",
      section_id: classFilter.value || "0",
      q: (liveSearch.value || "").trim()
    };
    window.location.href = buildUrl(params);
  });

  classFilter.addEventListener("change", ()=>{
    const params = {
      year_id: yearFilter.value || "",
      section_id: classFilter.value || "0",
      q: (liveSearch.value || "").trim()
    };
    window.location.href = buildUrl(params);
  });

  document.querySelectorAll(".btnDel").forEach(btn=>{
    btn.addEventListener("click", ()=>{
      const id = btn.dataset.id;
      const nm = btn.dataset.name || "ardaygan";
      Swal.fire({
        icon: "warning",
        title: "Ma tirtiraysaa ardaygan?",
        text: "Ma hubtaa inaad tirtirto: " + nm + " (ID " + id + ")?",
        showCancelButton: true,
        confirmButtonText: "Haa, tirtir",
        cancelButtonText: "Maya",
        confirmButtonColor: "#ef4444",
        cancelButtonColor: "#64748b",
        width: 700
      }).then(r=>{
        if(r.isConfirmed){
          const params = new URLSearchParams(window.location.search);
          params.set("delete", id);
          window.location.href = "students.php?" + params.toString();
        }
      });
    });
  });

  applyLiveFilter();
  updateCount();

  liveSearch.addEventListener("keydown", (e)=>{
    if(e.key === "Enter"){
      e.preventDefault();
      btnGo.click();
    }
  });
</script>

</body>
</html>
