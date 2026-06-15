<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . "/conncation.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* =====================
   ADMIN GUARD
   ===================== */
if (!isset($_SESSION["user_id"])) { header("Location: login.php"); exit; }
if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "ADMIN") { header("Location: login.php"); exit; }

/* =====================
   HELPERS
   ===================== */
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8"); }

function setFlash(string $type, string $title, string $text): void {
  $_SESSION["flash_alert"] = ["type"=>$type, "title"=>$title, "text"=>$text];
}
function popFlash(): ?array {
  if (!isset($_SESSION["flash_alert"])) return null;
  $a = $_SESSION["flash_alert"];
  unset($_SESSION["flash_alert"]);
  return $a;
}
$alert = popFlash();

function redirectSelf(array $qs = []): void {
  $base = basename($_SERVER["PHP_SELF"]);
  $q = http_build_query($qs);
  header("Location: " . $base . ($q ? ("?" . $q) : ""));
  exit;
}

/* =====================
   YEARS (current + list)
   ===================== */
function fetchYears(mysqli $conn): array {
  $years = [];
  $rs = $conn->query("SELECT year_id, year_name, is_current, start_date, end_date FROM academic_years ORDER BY start_date DESC, year_id DESC");
  if ($rs) while($r = $rs->fetch_assoc()) $years[] = $r;
  return $years;
}
function getCurrentYear(mysqli $conn): array {
  $cur = ["id"=>0, "name"=>""];
  // try is_current
  $st = $conn->prepare("SELECT year_id, year_name FROM academic_years WHERE is_current=1 ORDER BY start_date DESC, year_id DESC LIMIT 1");
  $st->execute();
  $rs = $st->get_result();
  if ($row = $rs->fetch_assoc()) {
    $cur["id"] = (int)$row["year_id"];
    $cur["name"] = (string)($row["year_name"] ?? "");
    $st->close();
    return $cur;
  }
  $st->close();

  // fallback latest
  $st = $conn->prepare("SELECT year_id, year_name FROM academic_years ORDER BY start_date DESC, year_id DESC LIMIT 1");
  $st->execute();
  $rs = $st->get_result();
  if ($row = $rs->fetch_assoc()) {
    $cur["id"] = (int)$row["year_id"];
    $cur["name"] = (string)($row["year_name"] ?? "");
  }
  $st->close();
  return $cur;
}

$years = fetchYears($conn);
$curY  = getCurrentYear($conn);

$selectedYearId = (int)($_GET["year_id"] ?? 0);
if ($selectedYearId <= 0) $selectedYearId = (int)$curY["id"];

$selectedYearName = (string)$curY["name"];
if ($selectedYearId > 0) {
  foreach ($years as $y) {
    if ((int)$y["year_id"] === $selectedYearId) {
      $selectedYearName = (string)($y["year_name"] ?? $selectedYearName);
      break;
    }
  }
}

/* =====================
   ACTIVE TAB
   ===================== */
$tab = (string)($_GET["tab"] ?? "sections");
if (!in_array($tab, ["grades","sections"], true)) $tab = "sections";

/* =====================
   SEARCH
   ===================== */
$qg = trim((string)($_GET["qg"] ?? "")); // grades search
$qs = trim((string)($_GET["qs"] ?? "")); // sections search

/* =====================
   FETCH DROPDOWNS
   ===================== */
function fetchLevels(mysqli $conn): array {
  $levels = [];
  $rs = $conn->query("SELECT level_id, level_name FROM school_levels ORDER BY level_id ASC");
  if ($rs) while($r = $rs->fetch_assoc()) $levels[] = $r;
  return $levels;
}
function fetchGradesSelect(mysqli $conn): array {
  $out = [];
  $rs = $conn->query("
    SELECT g.grade_id, g.grade_name, g.sort_order, g.level_id, sl.level_name
    FROM grades g
    JOIN school_levels sl ON sl.level_id = g.level_id
    ORDER BY g.sort_order ASC, g.grade_name ASC
  ");
  if ($rs) while($r = $rs->fetch_assoc()) $out[] = $r;
  return $out;
}

/* =====================
   FETCH GRADES (cards)
   ===================== */
function fetchGrades(mysqli $conn, string $qg = ""): array {
  $grades = [];
  $base = "
    SELECT g.grade_id, g.grade_name, g.sort_order, g.level_id, sl.level_name,
           (SELECT COUNT(*) FROM sections s WHERE s.grade_id=g.grade_id) AS sections_count
    FROM grades g
    JOIN school_levels sl ON sl.level_id=g.level_id
  ";

  if ($qg !== "") {
    $st = $conn->prepare($base." WHERE g.grade_name LIKE ? ORDER BY g.sort_order ASC, g.grade_name ASC");
    $like = "%".$qg."%";
    $st->bind_param("s", $like);
    $st->execute();
    $rs = $st->get_result();
    while($r=$rs->fetch_assoc()) $grades[] = $r;
    $st->close();
  } else {
    $rs = $conn->query($base." ORDER BY g.sort_order ASC, g.grade_name ASC");
    if ($rs) while($r=$rs->fetch_assoc()) $grades[] = $r;
  }
  return $grades;
}

/* =====================
   FETCH SECTIONS (cards + counts)
   ✅ DB-gaaga status waa: ENROLLED, TRANSFERRED, GRADUATED, DROPPED
   ✅ year_id NOT NULL => no need IS NULL
   ===================== */
function fetchSections(mysqli $conn, int $yearId, string $qs = ""): array {
  $sections = [];
  $yearId = (int)$yearId;

  // haddii academic_years madhan yahay, return sections with 0 counts
  if ($yearId <= 0) {
    $where = "";
    $types = "";
    $params = [];

    if ($qs !== "") {
      $where = "WHERE (g.grade_name LIKE ? OR s.section_name LIKE ?)";
      $like = "%".$qs."%";
      $types = "ss";
      $params = [$like, $like];
    }

    $sql = "
      SELECT
        s.section_id, s.section_name, IFNULL(s.capacity_max,50) AS capacity_max,
        g.grade_id, g.grade_name, g.sort_order, sl.level_name,
        0 AS total_students, 0 AS boys, 0 AS girls, 0 AS na_gender
      FROM sections s
      JOIN grades g ON g.grade_id=s.grade_id
      JOIN school_levels sl ON sl.level_id=g.level_id
      $where
      ORDER BY g.sort_order ASC, s.section_name ASC
    ";

    $st = $conn->prepare($sql);
    if ($types !== "") $st->bind_param($types, ...$params);
    $st->execute();
    $rs = $st->get_result();
    while($r=$rs->fetch_assoc()) $sections[] = $r;
    $st->close();
    return $sections;
  }

  // Normal: count enrollments for selected year + status ENROLLED only
  $where = "";
  $types = "i";     // yearId for join
  $params = [$yearId];

  if ($qs !== "") {
    $where = "WHERE (g.grade_name LIKE ? OR s.section_name LIKE ?)";
    $like = "%".$qs."%";
    $types .= "ss";
    $params[] = $like;
    $params[] = $like;
  }

  $sql = "
    SELECT
      s.section_id,
      s.section_name,
      IFNULL(s.capacity_max,50) AS capacity_max,
      g.grade_id,
      g.grade_name,
      g.sort_order,
      sl.level_name,

      COUNT(DISTINCT e.student_id) AS total_students,

      COUNT(DISTINCT CASE WHEN st.gender='M' THEN e.student_id END) AS boys,
      COUNT(DISTINCT CASE WHEN st.gender='F' THEN e.student_id END) AS girls,
      COUNT(DISTINCT CASE
        WHEN st.gender IS NULL OR st.gender=''
        THEN e.student_id END
      ) AS na_gender

    FROM sections s
    JOIN grades g ON g.grade_id = s.grade_id
    JOIN school_levels sl ON sl.level_id = g.level_id

    LEFT JOIN enrollments e
      ON e.section_id = s.section_id
     AND e.year_id = ?
     AND e.status = 'ENROLLED'

    LEFT JOIN students st ON st.student_id = e.student_id

    $where
    GROUP BY s.section_id
    ORDER BY g.sort_order ASC, s.section_name ASC
  ";

  $st = $conn->prepare($sql);
  $st->bind_param($types, ...$params);
  $st->execute();
  $rs = $st->get_result();
  while($r=$rs->fetch_assoc()) $sections[] = $r;
  $st->close();

  return $sections;
}

/* =====================
   AJAX LIVE SEARCH
   ===================== */
if (isset($_GET["ajax"]) && $_GET["ajax"] === "1") {
  header("Content-Type: application/json; charset=utf-8");

  $tabAjax = (string)($_GET["tab"] ?? "sections");
  if (!in_array($tabAjax, ["grades","sections"], true)) $tabAjax = "sections";

  $yearAjax = (int)($_GET["year_id"] ?? 0);
  if ($yearAjax <= 0) $yearAjax = (int)$selectedYearId;

  if ($tabAjax === "sections") {
    $qsAjax = trim((string)($_GET["qs"] ?? ""));
    $data = fetchSections($conn, $yearAjax, $qsAjax);
    echo json_encode(["ok"=>true, "tab"=>"sections", "items"=>$data, "year_id"=>$yearAjax], JSON_UNESCAPED_UNICODE);
    exit;
  } else {
    $qgAjax = trim((string)($_GET["qg"] ?? ""));
    $data = fetchGrades($conn, $qgAjax);
    echo json_encode(["ok"=>true, "tab"=>"grades", "items"=>$data], JSON_UNESCAPED_UNICODE);
    exit;
  }
}

/* =====================
   ACTIONS (POST)
   ===================== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $action = (string)($_POST["action"] ?? "");
  $tabPost = (string)($_POST["tab"] ?? $tab);
  if (!in_array($tabPost, ["grades","sections"], true)) $tabPost = "sections";

  /* ---------- GRADES ---------- */
  if ($action === "add_grade") {
    $level_id   = (int)($_POST["level_id"] ?? 0);
    $grade_name = trim((string)($_POST["grade_name"] ?? ""));
    $sort_order = (int)($_POST["sort_order"] ?? 0);

    if ($level_id <= 0 || $grade_name === "" || $sort_order <= 0) {
      setFlash("error","Invalid Input","Please fill Level, Grade Name, and Sort Order.");
      redirectSelf(["tab"=>"grades","year_id"=>$selectedYearId]);
    }

    $st = $conn->prepare("INSERT INTO grades (level_id, grade_name, sort_order) VALUES (?,?,?)");
    $st->bind_param("isi", $level_id, $grade_name, $sort_order);

    try { $st->execute(); setFlash("success","Saved","Grade created successfully."); }
    catch (mysqli_sql_exception $e) { setFlash("error","Save Failed","Could not create grade. (Maybe duplicate grade name)."); }

    $st->close();
    redirectSelf(["tab"=>"grades","year_id"=>$selectedYearId]);
  }

  if ($action === "update_grade") {
    $grade_id   = (int)($_POST["grade_id"] ?? 0);
    $level_id   = (int)($_POST["level_id"] ?? 0);
    $grade_name = trim((string)($_POST["grade_name"] ?? ""));
    $sort_order = (int)($_POST["sort_order"] ?? 0);

    if ($grade_id <= 0 || $level_id <= 0 || $grade_name === "" || $sort_order <= 0) {
      setFlash("error","Invalid Input","Please fill all fields.");
      redirectSelf(["tab"=>"grades","year_id"=>$selectedYearId]);
    }

    $st = $conn->prepare("UPDATE grades SET level_id=?, grade_name=?, sort_order=? WHERE grade_id=?");
    $st->bind_param("isii", $level_id, $grade_name, $sort_order, $grade_id);

    try { $st->execute(); setFlash("success","Updated","Grade updated successfully."); }
    catch (mysqli_sql_exception $e) { setFlash("error","Update Failed","Could not update grade. (Maybe duplicate grade name)."); }

    $st->close();
    redirectSelf(["tab"=>"grades","year_id"=>$selectedYearId]);
  }

  if ($action === "delete_grade") {
    $grade_id = (int)($_POST["grade_id"] ?? 0);
    if ($grade_id <= 0) { setFlash("error","Invalid","Missing grade id."); redirectSelf(["tab"=>"grades","year_id"=>$selectedYearId]); }

    $cnt = 0;
    $chk = $conn->prepare("SELECT COUNT(*) FROM sections WHERE grade_id=?");
    $chk->bind_param("i", $grade_id);
    $chk->execute();
    $chk->bind_result($cnt);
    $chk->fetch();
    $chk->close();

    if ($cnt > 0) {
      setFlash("error","Blocked","You cannot delete this grade because it has sections.");
      redirectSelf(["tab"=>"grades","year_id"=>$selectedYearId]);
    }

    $st = $conn->prepare("DELETE FROM grades WHERE grade_id=?");
    $st->bind_param("i", $grade_id);

    try { $st->execute(); setFlash("success","Deleted","Grade deleted successfully."); }
    catch (mysqli_sql_exception $e) { setFlash("error","Delete Failed","Could not delete grade."); }

    $st->close();
    redirectSelf(["tab"=>"grades","year_id"=>$selectedYearId]);
  }

  /* ---------- SECTIONS ---------- */
  if ($action === "add_section") {
    $grade_id     = (int)($_POST["grade_id"] ?? 0);
    $section_name = strtoupper(trim((string)($_POST["section_name"] ?? "")));
    $capacity_max = (int)($_POST["capacity_max"] ?? 50);

    if ($grade_id <= 0 || $section_name === "") {
      setFlash("error","Invalid Input","Please fill Grade and Section name.");
      redirectSelf(["tab"=>"sections","year_id"=>$selectedYearId]);
    }
    if ($capacity_max < 1) $capacity_max = 50;
    if (strlen($section_name) > 10) {
      setFlash("error","Invalid Section","Section name too long (max 10).");
      redirectSelf(["tab"=>"sections","year_id"=>$selectedYearId]);
    }

    $st = $conn->prepare("INSERT INTO sections (grade_id, section_name, capacity_max) VALUES (?,?,?)");
    $st->bind_param("isi", $grade_id, $section_name, $capacity_max);

    try { $st->execute(); setFlash("success","Saved","Class/Section created successfully."); }
    catch (mysqli_sql_exception $e) { setFlash("error","Save Failed","Could not create section. (Maybe duplicate section for same grade)."); }

    $st->close();
    redirectSelf(["tab"=>"sections","year_id"=>$selectedYearId]);
  }

  if ($action === "update_section") {
    $section_id   = (int)($_POST["section_id"] ?? 0);
    $grade_id     = (int)($_POST["grade_id"] ?? 0);
    $section_name = strtoupper(trim((string)($_POST["section_name"] ?? "")));
    $capacity_max = (int)($_POST["capacity_max"] ?? 50);

    if ($section_id <= 0 || $grade_id <= 0 || $section_name === "") {
      setFlash("error","Invalid Input","Please fill all fields.");
      redirectSelf(["tab"=>"sections","year_id"=>$selectedYearId]);
    }
    if ($capacity_max < 1) $capacity_max = 50;

    $st = $conn->prepare("UPDATE sections SET grade_id=?, section_name=?, capacity_max=? WHERE section_id=?");
    $st->bind_param("isii", $grade_id, $section_name, $capacity_max, $section_id);

    try { $st->execute(); setFlash("success","Updated","Class/Section updated successfully."); }
    catch (mysqli_sql_exception $e) { setFlash("error","Update Failed","Could not update section. (Maybe duplicate section for same grade)."); }

    $st->close();
    redirectSelf(["tab"=>"sections","year_id"=>$selectedYearId]);
  }

  if ($action === "delete_section") {
    $section_id = (int)($_POST["section_id"] ?? 0);
    if ($section_id <= 0) { setFlash("error","Invalid","Missing section id."); redirectSelf(["tab"=>"sections","year_id"=>$selectedYearId]); }

    // block if students exist in selected year (status ENROLLED)
    $cnt = 0;
    if ($selectedYearId > 0) {
      $chk = $conn->prepare("
        SELECT COUNT(DISTINCT student_id)
        FROM enrollments
        WHERE section_id=?
          AND year_id=?
          AND status='ENROLLED'
      ");
      $chk->bind_param("ii", $section_id, $selectedYearId);
      $chk->execute();
      $chk->bind_result($cnt);
      $chk->fetch();
      $chk->close();
    }

    if ($cnt > 0) {
      setFlash("error","Blocked","You cannot delete this section because it has students enrolled in this year.");
      redirectSelf(["tab"=>"sections","year_id"=>$selectedYearId]);
    }

    $st = $conn->prepare("DELETE FROM sections WHERE section_id=?");
    $st->bind_param("i", $section_id);

    try { $st->execute(); setFlash("success","Deleted","Class/Section deleted successfully."); }
    catch (mysqli_sql_exception $e) { setFlash("error","Delete Failed","Could not delete section."); }

    $st->close();
    redirectSelf(["tab"=>"sections","year_id"=>$selectedYearId]);
  }

  redirectSelf(["tab"=>$tabPost,"year_id"=>$selectedYearId]);
}

/* =====================
   PAGE DATA
   ===================== */
$levels = fetchLevels($conn);
$gradesForSelect = fetchGradesSelect($conn);

$grades   = fetchGrades($conn, $qg);
$sections = fetchSections($conn, $selectedYearId, $qs);

/* JSON for SweetAlert selects */
$levelsJson = json_encode(array_map(fn($x)=>[
  "level_id" => (int)$x["level_id"],
  "level_name" => (string)$x["level_name"],
], $levels), JSON_UNESCAPED_UNICODE);

$gradesSelJson = json_encode(array_map(fn($x)=>[
  "grade_id" => (int)$x["grade_id"],
  "grade_name" => (string)$x["grade_name"],
  "level_id" => (int)$x["level_id"],
  "level_name" => (string)$x["level_name"],
  "sort_order" => (int)$x["sort_order"],
], $gradesForSelect), JSON_UNESCAPED_UNICODE);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Classes</title>

  <link rel="stylesheet" href="bootstrap.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <style>
    :root{
      --bg:#f3f5f9;
      --card:#ffffff;
      --border:#e5e7eb;
      --text:#111827;
      --muted:#6b7280;
      --primary:#2563eb;
      --primary2:#1d4ed8;
      --shadow: 0 8px 24px rgba(15,23,42,.08);
      --radius: 14px;
    }
    body{ margin:0; background: var(--bg); color: var(--text); font-family: Arial, sans-serif; }
    .wrap{ max-width: 1100px; margin: 20px auto; padding: 0 14px; }

    .pageHeader{
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 14px 16px;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap: 12px;
      flex-wrap: wrap;
    }

    .bread{
      display:flex;
      align-items:center;
      gap: 10px;
      font-weight: 900;
      flex-wrap: wrap;
    }
    .bread .sep{ color:#cbd5e1; font-weight:900; }
    .bread small{ color: var(--muted); font-weight:900; }

    .yearPick{
      display:flex;
      align-items:center;
      gap: 8px;
      margin-left: 10px;
    }
    .yearPick select{
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 8px 10px;
      font-weight: 900;
      background: #fff;
      outline: none;
    }

    .tabs{
      display:flex;
      gap: 10px;
      align-items:center;
      flex-wrap: wrap;
    }
    .tab{
      text-decoration:none;
      padding: 10px 14px;
      border-radius: 10px;
      border: 1px solid var(--border);
      background: #fff;
      color: var(--text);
      font-weight: 900;
      display:flex;
      align-items:center;
      gap: 10px;
    }
    .tab.active{
      border-color: rgba(37,99,235,.35);
      color: var(--primary);
      background: rgba(37,99,235,.06);
    }

    .toolbar{
      margin-top: 14px;
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 12px 14px;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap: 12px;
      flex-wrap: wrap;
    }
    .toolbar h2{
      margin:0;
      font-size: 16px;
      font-weight: 1000;
      display:flex;
      align-items:center;
      gap: 10px;
    }
    .searchBox{
      display:flex;
      gap: 8px;
      align-items:center;
      flex-wrap: wrap;
    }
    .searchBox input{
      width: 300px;
      max-width: 78vw;
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 10px 12px;
      font-weight: 900;
      outline: none;
      background: #fff;
    }
    .searchBox input:focus{
      border-color: rgba(37,99,235,.5);
      box-shadow: 0 0 0 4px rgba(37,99,235,.12);
    }
    .btnBlue{
      border: none;
      background: var(--primary);
      color:#fff;
      border-radius: 10px;
      padding: 10px 14px;
      font-weight: 1000;
      cursor:pointer;
    }
    .btnBlue:hover{ background: var(--primary2); }

    .grid{
      margin-top: 14px;
      display:grid;
      grid-template-columns: repeat(3, minmax(240px, 1fr));
      gap: 16px;
    }
    @media(max-width:920px){ .grid{ grid-template-columns: repeat(2, minmax(240px, 1fr)); } }
    @media(max-width:560px){ .grid{ grid-template-columns: 1fr; } }

    .classCard{
      background: #fff;
      border: 1px solid var(--border);
      border-radius: 14px;
      box-shadow: var(--shadow);
      padding: 16px;
      text-decoration:none;
      color: inherit;
      display:block;
      transition: transform .12s ease, box-shadow .12s ease;
      min-height: 230px;
    }
    .classCard:hover{
      transform: translateY(-2px);
      box-shadow: 0 14px 36px rgba(15,23,42,.12);
    }
    .cardHead{
      display:flex;
      justify-content:space-between;
      gap: 10px;
      align-items:flex-start;
    }
    .code{
      font-size: 22px;
      font-weight: 1000;
      letter-spacing: .2px;
      text-transform: lowercase;
    }
    .muted{
      color: var(--muted);
      font-weight: 900;
      font-size: 12px;
      margin-top: 4px;
    }
    .tools{
      display:flex;
      gap: 8px;
      align-items:center;
    }
    .toolBtn{
      width: 32px;
      height: 32px;
      border-radius: 9px;
      border: 1px solid var(--border);
      background: #fff;
      display:flex;
      align-items:center;
      justify-content:center;
      cursor:pointer;
      color: var(--primary);
    }
    .toolBtn.danger{ color:#dc2626; border-bottom: 2px solid #f43f5e; }

    .bigRow{
      margin-top: 14px;
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap: 10px;
    }
    .bigNum{
      font-size: 52px;
      font-weight: 1000;
      line-height: 1;
    }
    .bigLabel{
      font-weight: 1000;
      color:#374151;
      text-transform: uppercase;
      font-size: 14px;
      display:flex;
      align-items:center;
      gap: 10px;
      letter-spacing: .5px;
    }
    .capIcon{
      font-size: 44px;
      color: var(--primary);
      opacity: .9;
    }

    .circles{
      margin-top: 16px;
      display:flex;
      gap: 18px;
      flex-wrap: wrap;
      align-items:flex-end;
    }
    .circleWrap{
      display:flex;
      flex-direction:column;
      align-items:center;
      gap: 6px;
      min-width: 78px;
    }
    .ring{
      width: 64px;
      height: 64px;
      border-radius: 999px;
      background: conic-gradient(var(--primary) var(--p), rgba(148,163,184,.25) 0);
      display:flex;
      align-items:center;
      justify-content:center;
      position:relative;
    }
    .ring:after{
      content:"";
      position:absolute;
      width: 50px;
      height: 50px;
      border-radius: 999px;
      background:#fff;
      border: 1px solid rgba(148,163,184,.25);
    }
    .ring span{
      position:relative;
      z-index:2;
      font-weight: 1000;
      font-size: 12px;
      color:#111827;
    }
    .cap{
      font-size: 11px;
      font-weight: 1000;
      color: var(--muted);
      text-align:center;
      line-height: 1.2;
    }
    .cap b{ color:#111827; }

    .addNew{
      border: 2px dotted rgba(37,99,235,.7);
      display:flex;
      align-items:center;
      justify-content:center;
      min-height: 230px;
      cursor:pointer;
      background: #fff;
    }
    .addNew .plus{
      font-size: 48px;
      color: var(--primary);
      text-align:center;
      line-height: 1;
      margin-bottom: 6px;
    }
    .addNew .txt{
      font-weight: 1000;
      color: var(--primary);
      text-align:center;
      font-size: 18px;
    }

    .badge{
      display:inline-flex;
      align-items:center;
      gap: 8px;
      padding: 6px 10px;
      border-radius: 999px;
      border: 1px solid rgba(37,99,235,.25);
      background: rgba(37,99,235,.08);
      color: var(--primary);
      font-weight: 1000;
      font-size: 12px;
      margin-top: 8px;
    }
    .hint{
      margin-top: 10px;
      font-weight: 900;
      font-size: 12px;
      color: var(--muted);
    }
    .warnBox{
      margin-top: 12px;
      background: #fff7ed;
      border: 1px solid #fed7aa;
      color: #9a3412;
      border-radius: 12px;
      padding: 10px 12px;
      font-weight: 900;
      box-shadow: var(--shadow);
    }
  </style>
</head>

<body>
  <div class="wrap">

    <div class="pageHeader">
      <div class="bread">
        <span>Classes</span>
        <span class="sep">|</span>
        <small><i class="fa-solid fa-house"></i> - All Classes<?= $selectedYearName ? " • ".$selectedYearName : "" ?></small>

        <?php if (count($years) > 0): ?>
          <div class="yearPick">
            <span style="color:#6b7280;font-weight:900;">Year:</span>
            <select id="yearSel">
              <?php foreach($years as $y): ?>
                <?php $yy = (int)$y["year_id"]; ?>
                <option value="<?= $yy ?>" <?= $yy===$selectedYearId ? "selected" : "" ?>>
                  <?= h((string)$y["year_name"]) ?><?= ((int)$y["is_current"]===1 ? " (Current)" : "") ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>
      </div>

      <div class="tabs">
        <a class="tab <?= $tab==="sections" ? "active" : "" ?>" href="grades_sections.php?tab=sections&year_id=<?= (int)$selectedYearId ?>">
          <i class="fa-solid fa-layer-group"></i> Classes
        </a>
        <a class="tab <?= $tab==="grades" ? "active" : "" ?>" href="grades_sections.php?tab=grades&year_id=<?= (int)$selectedYearId ?>">
          <i class="fa-solid fa-graduation-cap"></i> Grades
        </a>
      </div>
    </div>

    <?php if (count($years) === 0): ?>
      <div class="warnBox">
        ⚠️ academic_years table waa madhan. Fadlan ku dar Year (tusaale 2025-2026) si enrollments & counts u shaqeeyaan.
      </div>
    <?php endif; ?>

    <?php if ($tab === "sections"): ?>
      <div class="toolbar">
        <h2><i class="fa-solid fa-layer-group"></i> All Classes</h2>
        <div class="searchBox">
          <input id="searchSections" type="text" value="<?= h($qs) ?>" placeholder="Search class / section...">
          <button class="btnBlue" type="button" onclick="openAddSection()"><i class="fa-solid fa-plus"></i> Add New</button>
        </div>
      </div>

      <div class="hint">✅ Click any class to open enrolled students.</div>

      <div id="sectionsGrid" class="grid">
        <div class="classCard addNew" onclick="openAddSection()">
          <div>
            <div class="plus">+</div>
            <div class="txt">Add New</div>
          </div>
        </div>

        <?php foreach($sections as $s): ?>
          <?php
            $sid    = (int)$s["section_id"];
            $grade  = (string)$s["grade_name"];
            $sec    = (string)$s["section_name"];

            // class code (display)
            $code = strtolower(substr(preg_replace('/\s+/', '', $grade), 0, 2)) . $sec;

            $total = (int)$s["total_students"];
            $boys  = (int)$s["boys"];
            $girls = (int)$s["girls"];
            $na    = (int)$s["na_gender"];

            $pb = ($total>0) ? (int)round(($boys/$total)*100) : 0;
            $pg = ($total>0) ? (int)round(($girls/$total)*100) : 0;
            $pn = ($total>0) ? (int)round(($na/$total)*100) : 0;

            $row = [
              "section_id"=>$sid,
              "grade_id"=>(int)$s["grade_id"],
              "section_name"=>$sec,
              "capacity_max"=>(int)$s["capacity_max"],
              "grade_name"=>$grade
            ];

            $studentsUrl = "students.php?section_id=".$sid.($selectedYearId>0 ? "&year_id=".$selectedYearId : "");
          ?>
          <a class="classCard" href="<?= h($studentsUrl) ?>">
            <div class="cardHead">
              <div>
                <div class="code"><?= h($code) ?></div>
                <div class="muted"><?= h($grade) ?> • Section <?= h($sec) ?></div>
              </div>

              <div class="tools">
                <button class="toolBtn" type="button"
                        onclick='event.preventDefault(); event.stopPropagation(); openEditSection(<?= json_encode($row, JSON_UNESCAPED_UNICODE) ?>);'>
                  <i class="fa-solid fa-pen"></i>
                </button>

                <button class="toolBtn danger" type="button"
                        onclick="event.preventDefault(); event.stopPropagation(); confirmDeleteSection(<?= $sid ?>, <?= json_encode($grade.'-'.$sec) ?>);">
                  <i class="fa-solid fa-trash"></i>
                </button>
              </div>
            </div>

            <div class="bigRow">
              <div style="display:flex;align-items:flex-end;gap:14px;">
                <div class="bigNum"><?= (int)$total ?></div>
                <div class="bigLabel">Students</div>
              </div>
              <div class="capIcon"><i class="fa-solid fa-graduation-cap"></i></div>
            </div>

            <div class="circles">
              <div class="circleWrap">
                <div class="ring" style="--p: <?= (int)$pb ?>%;"><span><?= (int)$pb ?>%</span></div>
                <div class="cap">Boys<br><b><?= (int)$boys ?></b></div>
              </div>
              <div class="circleWrap">
                <div class="ring" style="--p: <?= (int)$pg ?>%;"><span><?= (int)$pg ?>%</span></div>
                <div class="cap">Girls<br><b><?= (int)$girls ?></b></div>
              </div>
              <div class="circleWrap">
                <div class="ring" style="--p: <?= (int)$pn ?>%;"><span><?= (int)$pn ?>%</span></div>
                <div class="cap">N/A<br><b><?= (int)$na ?></b></div>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>


    <?php if ($tab === "grades"): ?>
      <div class="toolbar">
        <h2><i class="fa-solid fa-graduation-cap"></i> Grades</h2>
        <div class="searchBox">
          <input id="searchGrades" type="text" value="<?= h($qg) ?>" placeholder="Search grade...">
          <button class="btnBlue" type="button" onclick="openAddGrade()"><i class="fa-solid fa-plus"></i> Add New</button>
        </div>
      </div>

      <div id="gradesGrid" class="grid">
        <div class="classCard addNew" onclick="openAddGrade()">
          <div>
            <div class="plus">+</div>
            <div class="txt">Add New</div>
          </div>
        </div>

        <?php foreach($grades as $g): ?>
          <?php
            $gid = (int)$g["grade_id"];
            $row = [
              "grade_id"=>$gid,
              "grade_name"=>(string)$g["grade_name"],
              "level_id"=>(int)$g["level_id"],
              "sort_order"=>(int)$g["sort_order"],
              "level_name"=>(string)$g["level_name"],
              "sections_count"=>(int)$g["sections_count"],
            ];
          ?>
          <div class="classCard">
            <div class="cardHead">
              <div>
                <div class="code" style="text-transform:none;"><?= h($g["grade_name"]) ?></div>
                <div class="muted"><?= h($g["level_name"]) ?> • Sort: <?= (int)$g["sort_order"] ?></div>
                <div class="badge"><i class="fa-solid fa-layer-group"></i> Sections: <?= (int)$g["sections_count"] ?></div>
              </div>

              <div class="tools">
                <button class="toolBtn" type="button"
                        onclick='openEditGrade(<?= json_encode($row, JSON_UNESCAPED_UNICODE) ?>)'>
                  <i class="fa-solid fa-pen"></i>
                </button>

                <button class="toolBtn danger" type="button"
                        onclick="confirmDeleteGrade(<?= $gid ?>, <?= json_encode((string)$g['grade_name']) ?>)">
                  <i class="fa-solid fa-trash"></i>
                </button>
              </div>
            </div>

            <div class="hint" style="margin-top:18px;">
              Manage classes inside “Classes” tab.
            </div>
          </div>
        <?php endforeach; ?>
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
    width: 520
  });
</script>
<?php endif; ?>

<script>
  const LEVELS = <?= $levelsJson ?: "[]" ?>;
  const GRADES = <?= $gradesSelJson ?: "[]" ?>;
  const YEAR_ID = <?= (int)$selectedYearId ?>;
  const ACTIVE_TAB = <?= json_encode($tab) ?>;

  // Year select change
  document.addEventListener("DOMContentLoaded", ()=>{
    const ySel = document.getElementById("yearSel");
    if (ySel){
      ySel.addEventListener("change", ()=>{
        const y = ySel.value;
        const url = new URL(window.location.href);
        url.searchParams.set("year_id", y);
        url.searchParams.set("tab", ACTIVE_TAB);
        window.location.href = url.toString();
      });
    }
  });

  function postToSelf(data){
    const form = document.createElement("form");
    form.method = "POST";
    form.action = "<?= h(basename($_SERVER["PHP_SELF"])) ?>?tab=<?= h($tab) ?>&year_id=<?= (int)$selectedYearId ?>";
    Object.keys(data).forEach(k=>{
      const inp = document.createElement("input");
      inp.type = "hidden";
      inp.name = k;
      inp.value = String(data[k]);
      form.appendChild(inp);
    });
    document.body.appendChild(form);
    form.submit();
  }

  function escapeHtml(s){
    return String(s)
      .replaceAll("&","&amp;")
      .replaceAll("<","&lt;")
      .replaceAll(">","&gt;")
      .replaceAll('"',"&quot;")
      .replaceAll("'","&#039;");
  }

  function buildLevelSelect(selectedId){
    let html = `<select id="sw_level_id" class="swal2-select" style="width:100%;padding:10px;border-radius:10px;border:1px solid #e5e7eb;">`;
    html += `<option value="">Select level</option>`;
    for (const lv of LEVELS){
      const sel = (Number(selectedId) === Number(lv.level_id)) ? "selected" : "";
      html += `<option value="${lv.level_id}" ${sel}>${escapeHtml(lv.level_name)}</option>`;
    }
    html += `</select>`;
    return html;
  }

  function buildGradeSelect(selectedId){
    let html = `<select id="sw_grade_id" class="swal2-select" style="width:100%;padding:10px;border-radius:10px;border:1px solid #e5e7eb;">`;
    html += `<option value="">Select grade</option>`;
    for (const g of GRADES){
      const sel = (Number(selectedId) === Number(g.grade_id)) ? "selected" : "";
      html += `<option value="${g.grade_id}" ${sel}>${escapeHtml(g.grade_name)} (${escapeHtml(g.level_name)})</option>`;
    }
    html += `</select>`;
    return html;
  }

  /* ============ GRADES ============ */
  function openAddGrade(){
    Swal.fire({
      title: "Add New Grade",
      icon: "info",
      confirmButtonText: "Save",
      confirmButtonColor: "#2563eb",
      showCancelButton: true,
      cancelButtonText: "Cancel",
      width: 620,
      html: `
        <div style="text-align:left;display:grid;gap:10px;margin-top:6px;">
          <div>
            <label style="font-weight:900;margin-bottom:6px;display:block;">Level</label>
            ${buildLevelSelect("")}
          </div>
          <div>
            <label style="font-weight:900;margin-bottom:6px;display:block;">Grade Name</label>
            <input id="sw_grade_name" class="swal2-input" placeholder="Class 1 / Form 1" />
          </div>
          <div>
            <label style="font-weight:900;margin-bottom:6px;display:block;">Sort Order</label>
            <input id="sw_sort_order" class="swal2-input" type="number" min="1" placeholder="1..12" />
          </div>
        </div>
      `,
      preConfirm: () => {
        const level_id = document.getElementById("sw_level_id").value.trim();
        const grade_name = document.getElementById("sw_grade_name").value.trim();
        const sort_order = document.getElementById("sw_sort_order").value.trim();
        if (!level_id || !grade_name || !sort_order || Number(sort_order) <= 0){
          Swal.showValidationMessage("Please fill Level, Grade Name, and Sort Order.");
          return false;
        }
        return { level_id, grade_name, sort_order };
      }
    }).then(res=>{
      if(!res.isConfirmed) return;
      postToSelf({ action:"add_grade", tab:"grades", level_id: res.value.level_id, grade_name: res.value.grade_name, sort_order: res.value.sort_order });
    });
  }

  function openEditGrade(row){
    Swal.fire({
      title: "Edit Grade",
      icon: "question",
      confirmButtonText: "Update",
      confirmButtonColor: "#2563eb",
      showCancelButton: true,
      cancelButtonText: "Cancel",
      width: 640,
      html: `
        <div style="text-align:left;display:grid;gap:10px;margin-top:6px;">
          <div>
            <label style="font-weight:900;margin-bottom:6px;display:block;">Level</label>
            ${buildLevelSelect(row.level_id)}
          </div>
          <div>
            <label style="font-weight:900;margin-bottom:6px;display:block;">Grade Name</label>
            <input id="sw_grade_name" class="swal2-input" value="${escapeHtml(row.grade_name)}" />
          </div>
          <div>
            <label style="font-weight:900;margin-bottom:6px;display:block;">Sort Order</label>
            <input id="sw_sort_order" class="swal2-input" type="number" min="1" value="${Number(row.sort_order)}" />
          </div>
        </div>
      `,
      preConfirm: () => {
        const level_id = document.getElementById("sw_level_id").value.trim();
        const grade_name = document.getElementById("sw_grade_name").value.trim();
        const sort_order = document.getElementById("sw_sort_order").value.trim();
        if (!level_id || !grade_name || !sort_order || Number(sort_order) <= 0){
          Swal.showValidationMessage("Please fill all fields.");
          return false;
        }
        return { level_id, grade_name, sort_order };
      }
    }).then(res=>{
      if(!res.isConfirmed) return;
      postToSelf({ action:"update_grade", tab:"grades", grade_id: row.grade_id, level_id: res.value.level_id, grade_name: res.value.grade_name, sort_order: res.value.sort_order });
    });
  }

  function confirmDeleteGrade(grade_id, grade_name){
    Swal.fire({
      title: "Delete Grade?",
      text: `Are you sure you want to delete: ${grade_name}?`,
      icon: "warning",
      showCancelButton: true,
      confirmButtonText: "Yes, Delete",
      cancelButtonText: "Cancel",
      confirmButtonColor: "#dc2626",
    }).then(res=>{
      if(!res.isConfirmed) return;
      postToSelf({ action:"delete_grade", tab:"grades", grade_id });
    });
  }

  /* ============ SECTIONS ============ */
  function openAddSection(){
    if (!GRADES || GRADES.length === 0){
      Swal.fire({ icon:"error", title:"No Grades", text:"Please add grades first, then create classes (sections)." });
      return;
    }
    Swal.fire({
      title: "Add New Class",
      icon: "info",
      confirmButtonText: "Save",
      confirmButtonColor: "#2563eb",
      showCancelButton: true,
      cancelButtonText: "Cancel",
      width: 640,
      html: `
        <div style="text-align:left;display:grid;gap:10px;margin-top:6px;">
          <div>
            <label style="font-weight:900;margin-bottom:6px;display:block;">Grade</label>
            ${buildGradeSelect("")}
          </div>
          <div>
            <label style="font-weight:900;margin-bottom:6px;display:block;">Section Name</label>
            <input id="sw_section_name" class="swal2-input" placeholder="A / B / C / 221" />
          </div>
          <div>
            <label style="font-weight:900;margin-bottom:6px;display:block;">Capacity Max</label>
            <input id="sw_capacity" class="swal2-input" type="number" min="1" value="50" />
          </div>
        </div>
      `,
      preConfirm: () => {
        const grade_id = document.getElementById("sw_grade_id").value.trim();
        const section_name = document.getElementById("sw_section_name").value.trim().toUpperCase();
        const capacity_max = document.getElementById("sw_capacity").value.trim();
        if (!grade_id || !section_name){
          Swal.showValidationMessage("Please fill Grade and Section Name.");
          return false;
        }
        if (section_name.length > 10){
          Swal.showValidationMessage("Section name too long (max 10).");
          return false;
        }
        if (!capacity_max || Number(capacity_max) < 1){
          Swal.showValidationMessage("Capacity must be 1 or more.");
          return false;
        }
        return { grade_id, section_name, capacity_max };
      }
    }).then(res=>{
      if(!res.isConfirmed) return;
      postToSelf({ action:"add_section", tab:"sections", grade_id: res.value.grade_id, section_name: res.value.section_name, capacity_max: res.value.capacity_max });
    });
  }

  function openEditSection(row){
    Swal.fire({
      title: "Edit Class",
      icon: "question",
      confirmButtonText: "Update",
      confirmButtonColor: "#2563eb",
      showCancelButton: true,
      cancelButtonText: "Cancel",
      width: 660,
      html: `
        <div style="text-align:left;display:grid;gap:10px;margin-top:6px;">
          <div>
            <label style="font-weight:900;margin-bottom:6px;display:block;">Grade</label>
            ${buildGradeSelect(row.grade_id)}
          </div>
          <div>
            <label style="font-weight:900;margin-bottom:6px;display:block;">Section Name</label>
            <input id="sw_section_name" class="swal2-input" value="${escapeHtml(row.section_name)}" />
          </div>
          <div>
            <label style="font-weight:900;margin-bottom:6px;display:block;">Capacity Max</label>
            <input id="sw_capacity" class="swal2-input" type="number" min="1" value="${Number(row.capacity_max || 50)}" />
          </div>
        </div>
      `,
      preConfirm: () => {
        const grade_id = document.getElementById("sw_grade_id").value.trim();
        const section_name = document.getElementById("sw_section_name").value.trim().toUpperCase();
        const capacity_max = document.getElementById("sw_capacity").value.trim();
        if (!grade_id || !section_name){
          Swal.showValidationMessage("Please fill Grade and Section Name.");
          return false;
        }
        if (section_name.length > 10){
          Swal.showValidationMessage("Section name too long (max 10).");
          return false;
        }
        if (!capacity_max || Number(capacity_max) < 1){
          Swal.showValidationMessage("Capacity must be 1 or more.");
          return false;
        }
        return { grade_id, section_name, capacity_max };
      }
    }).then(res=>{
      if(!res.isConfirmed) return;
      postToSelf({ action:"update_section", tab:"sections", section_id: row.section_id, grade_id: res.value.grade_id, section_name: res.value.section_name, capacity_max: res.value.capacity_max });
    });
  }

  function confirmDeleteSection(section_id, label){
    Swal.fire({
      title: "Delete Class?",
      text: `Are you sure you want to delete: ${label}?`,
      icon: "warning",
      showCancelButton: true,
      confirmButtonText: "Yes, Delete",
      cancelButtonText: "Cancel",
      confirmButtonColor: "#dc2626",
    }).then(res=>{
      if(!res.isConfirmed) return;
      postToSelf({ action:"delete_section", tab:"sections", section_id });
    });
  }

  /* ============ LIVE SEARCH (AJAX) ============ */
  let tmr = null;

  async function liveSearchSections(q){
    const url = `grades_sections.php?ajax=1&tab=sections&qs=${encodeURIComponent(q)}&year_id=${encodeURIComponent(String(YEAR_ID))}`;
    const res = await fetch(url);
    const data = await res.json();
    if (!data.ok) return;

    const grid = document.getElementById("sectionsGrid");
    if (!grid) return;

    let html = `
      <div class="classCard addNew" onclick="openAddSection()">
        <div>
          <div class="plus">+</div>
          <div class="txt">Add New</div>
        </div>
      </div>
    `;

    for (const s of data.items){
      const sid = Number(s.section_id);
      const grade = String(s.grade_name || "");
      const sec = String(s.section_name || "");
      const code = (grade.replace(/\s+/g,'').slice(0,2).toLowerCase()) + sec;

      const total = Number(s.total_students || 0);
      const boys  = Number(s.boys || 0);
      const girls = Number(s.girls || 0);
      const na    = Number(s.na_gender || 0);

      const pb = total>0 ? Math.round((boys/total)*100) : 0;
      const pg = total>0 ? Math.round((girls/total)*100) : 0;
      const pn = total>0 ? Math.round((na/total)*100) : 0;

      const studentsUrl = `students.php?section_id=${sid}` + (YEAR_ID>0 ? `&year_id=${YEAR_ID}` : "");

      const row = {
        section_id: sid,
        grade_id: Number(s.grade_id),
        section_name: sec,
        capacity_max: Number(s.capacity_max || 50),
        grade_name: grade
      };

      html += `
        <a class="classCard" href="${escapeHtml(studentsUrl)}">
          <div class="cardHead">
            <div>
              <div class="code">${escapeHtml(code)}</div>
              <div class="muted">${escapeHtml(grade)} • Section ${escapeHtml(sec)}</div>
            </div>

            <div class="tools">
              <button class="toolBtn" type="button"
                      onclick='event.preventDefault(); event.stopPropagation(); openEditSection(${JSON.stringify(row)});'>
                <i class="fa-solid fa-pen"></i>
              </button>
              <button class="toolBtn danger" type="button"
                      onclick="event.preventDefault(); event.stopPropagation(); confirmDeleteSection(${sid}, ${JSON.stringify(grade+'-'+sec)});">
                <i class="fa-solid fa-trash"></i>
              </button>
            </div>
          </div>

          <div class="bigRow">
            <div style="display:flex;align-items:flex-end;gap:14px;">
              <div class="bigNum">${total}</div>
              <div class="bigLabel">Students</div>
            </div>
            <div class="capIcon"><i class="fa-solid fa-graduation-cap"></i></div>
          </div>

          <div class="circles">
            <div class="circleWrap">
              <div class="ring" style="--p:${pb}%"><span>${pb}%</span></div>
              <div class="cap">Boys<br><b>${boys}</b></div>
            </div>
            <div class="circleWrap">
              <div class="ring" style="--p:${pg}%"><span>${pg}%</span></div>
              <div class="cap">Girls<br><b>${girls}</b></div>
            </div>
            <div class="circleWrap">
              <div class="ring" style="--p:${pn}%"><span>${pn}%</span></div>
              <div class="cap">N/A<br><b>${na}</b></div>
            </div>
          </div>
        </a>
      `;
    }

    grid.innerHTML = html;
  }

  async function liveSearchGrades(q){
    const url = `grades_sections.php?ajax=1&tab=grades&qg=${encodeURIComponent(q)}&year_id=${encodeURIComponent(String(YEAR_ID))}`;
    const res = await fetch(url);
    const data = await res.json();
    if (!data.ok) return;

    const grid = document.getElementById("gradesGrid");
    if (!grid) return;

    let html = `
      <div class="classCard addNew" onclick="openAddGrade()">
        <div>
          <div class="plus">+</div>
          <div class="txt">Add New</div>
        </div>
      </div>
    `;

    for (const g of data.items){
      const row = {
        grade_id: Number(g.grade_id),
        grade_name: String(g.grade_name||""),
        level_id: Number(g.level_id),
        sort_order: Number(g.sort_order),
        level_name: String(g.level_name||""),
        sections_count: Number(g.sections_count||0),
      };

      html += `
        <div class="classCard">
          <div class="cardHead">
            <div>
              <div class="code" style="text-transform:none;">${escapeHtml(row.grade_name)}</div>
              <div class="muted">${escapeHtml(row.level_name)} • Sort: ${row.sort_order}</div>
              <div class="badge"><i class="fa-solid fa-layer-group"></i> Sections: ${row.sections_count}</div>
            </div>

            <div class="tools">
              <button class="toolBtn" type="button" onclick='openEditGrade(${JSON.stringify(row)})'>
                <i class="fa-solid fa-pen"></i>
              </button>
              <button class="toolBtn danger" type="button" onclick='confirmDeleteGrade(${row.grade_id}, ${JSON.stringify(row.grade_name)})'>
                <i class="fa-solid fa-trash"></i>
              </button>
            </div>
          </div>

          <div class="hint" style="margin-top:18px;">
            Manage classes inside “Classes” tab.
          </div>
        </div>
      `;
    }

    grid.innerHTML = html;
  }

  document.addEventListener("DOMContentLoaded", ()=>{
    const secInput = document.getElementById("searchSections");
    const grdInput = document.getElementById("searchGrades");

    if (secInput) {
      secInput.addEventListener("input", (e)=>{
        clearTimeout(tmr);
        tmr = setTimeout(()=> liveSearchSections(e.target.value.trim()), 220);
      });
    }

    if (grdInput) {
      grdInput.addEventListener("input", (e)=>{
        clearTimeout(tmr);
        tmr = setTimeout(()=> liveSearchGrades(e.target.value.trim()), 220);
      });
    }
  });
</script>

</body>
</html>
