<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . "/conncation.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* =========================
   ADMIN GUARD
   ========================= */
if (!isset($_SESSION["user_id"])) { header("Location: login.php"); exit; }
if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "ADMIN") { header("Location: login.php"); exit; }

$userId   = (int)($_SESSION["user_id"]);
$username = (string)($_SESSION["username"] ?? "Admin");

/* =========================
   HELPERS
   ========================= */
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

function cleanSpaces(?string $v): string {
  $v = trim((string)$v);
  $v = preg_replace('/\s+/', ' ', $v);
  return $v ?: "";
}

/* =========================
   CREATE TABLE for MARKS (SAFE)
   - DB-gaaga section_subjects ma laha max_mark
   - Sidaas darteed waxaan ku kaydineynaa halkan
   ========================= */
$conn->query("
  CREATE TABLE IF NOT EXISTS section_subject_details (
    section_id BIGINT NOT NULL,
    subject_id BIGINT NOT NULL,
    max_mark INT NOT NULL DEFAULT 100,
    PRIMARY KEY (section_id, subject_id),
    CONSTRAINT fk_ssd_section FOREIGN KEY (section_id) REFERENCES sections(section_id) ON DELETE CASCADE,
    CONSTRAINT fk_ssd_subject FOREIGN KEY (subject_id) REFERENCES subjects(subject_id) ON DELETE CASCADE,
    CONSTRAINT chk_ssd_mark CHECK (max_mark BETWEEN 0 AND 100)
  ) ENGINE=InnoDB
");

/* =========================
   ROUTING
   ========================= */
$view = $_GET["view"] ?? "classes"; // classes | assign
$editSectionId = isset($_GET["edit_section_id"]) ? (int)$_GET["edit_section_id"] : 0;
$isEdit = ($view === "assign" && $editSectionId > 0);

/* =========================
   LOAD SECTIONS (Classes)
   ========================= */
$sections = [];
$rs = $conn->query("
  SELECT
    s.section_id,
    g.grade_name,
    s.section_name,
    CONCAT(g.grade_name, ' - ', s.section_name) AS display_name
  FROM sections s
  JOIN grades g ON g.grade_id = s.grade_id
  ORDER BY g.sort_order ASC, s.section_name ASC
");
while ($row = $rs->fetch_assoc()) $sections[] = $row;

/* =========================
   IF EDIT: Load existing subjects for section
   ========================= */
$editData = [
  "section_id" => 0,
  "display_name" => "",
  "rows" => [] // each: subject_name, max_mark
];

if ($isEdit) {
  $st = $conn->prepare("
    SELECT
      s.section_id,
      CONCAT(g.grade_name, ' - ', s.section_name) AS display_name
    FROM sections s
    JOIN grades g ON g.grade_id = s.grade_id
    WHERE s.section_id = ?
    LIMIT 1
  ");
  $st->bind_param("i", $editSectionId);
  $st->execute();
  $hdr = $st->get_result()->fetch_assoc();
  $st->close();

  if (!$hdr) {
    setFlash("error", "Qalad!", "Class-kan lama helin.");
    header("Location: subjects.php?view=classes");
    exit;
  }

  $editData["section_id"] = (int)$hdr["section_id"];
  $editData["display_name"] = (string)$hdr["display_name"];

  $st = $conn->prepare("
    SELECT sub.subject_name, COALESCE(ssd.max_mark, 100) AS max_mark
    FROM section_subjects ss
    JOIN subjects sub ON sub.subject_id = ss.subject_id
    LEFT JOIN section_subject_details ssd
      ON ssd.section_id = ss.section_id AND ssd.subject_id = ss.subject_id
    WHERE ss.section_id = ?
    ORDER BY sub.subject_name ASC
  ");
  $st->bind_param("i", $editSectionId);
  $st->execute();
  $res = $st->get_result();
  while ($r = $res->fetch_assoc()) {
    $editData["rows"][] = [
      "subject_name" => (string)$r["subject_name"],
      "max_mark" => (int)$r["max_mark"],
    ];
  }
  $st->close();
}

/* =========================
   HANDLE SUBMIT (Assign / Update)
   ========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"]) && $_POST["action"] === "save_subjects") {
  $section_id = (int)($_POST["section_id"] ?? 0);

  $sub_names = $_POST["subject_name"] ?? [];
  $marks_arr = $_POST["max_mark"] ?? [];

  if ($section_id <= 0) {
    setFlash("error", "Qalad!", "Fadlan dooro Class.");
    header("Location: subjects.php?view=assign");
    exit;
  }

  // validate section exists
  $st = $conn->prepare("SELECT section_id FROM sections WHERE section_id=? LIMIT 1");
  $st->bind_param("i", $section_id);
  $st->execute();
  $exists = $st->get_result()->fetch_assoc();
  $st->close();
  if (!$exists) {
    setFlash("error", "Qalad!", "Class-kan database-ka kuma jiro.");
    header("Location: subjects.php?view=assign");
    exit;
  }

  // Normalize + validate rows
  $rows = [];
  $seen = [];
  for ($i=0; $i<count($sub_names); $i++) {
    $name = cleanSpaces((string)($sub_names[$i] ?? ""));
    $mark = (int)($marks_arr[$i] ?? 0);

    if ($name === "") continue; // ignore empty lines
    if ($mark < 0 || $mark > 100) {
      setFlash("error", "Qalad!", "Marks-ka waa inuu noqdaa 0 ilaa 100.");
      header("Location: subjects.php?view=assign" . ($section_id ? "&edit_section_id=".$section_id : ""));
      exit;
    }

    $key = mb_strtolower($name);
    if (isset($seen[$key])) {
      setFlash("warning", "Digniin!", "Subject isku mid ah lama celin karo: ".$name);
      header("Location: subjects.php?view=assign" . ($section_id ? "&edit_section_id=".$section_id : ""));
      exit;
    }
    $seen[$key] = true;

    $rows[] = ["subject_name"=>$name, "max_mark"=>$mark];
  }

  if (count($rows) === 0) {
    setFlash("error", "Qalad!", "Fadlan geli ugu yaraan hal Subject.");
    header("Location: subjects.php?view=assign" . ($section_id ? "&edit_section_id=".$section_id : ""));
    exit;
  }

  $conn->begin_transaction();
  try {
    // 1) if update: delete old links for this section
    $st = $conn->prepare("DELETE FROM section_subjects WHERE section_id=?");
    $st->bind_param("i", $section_id);
    $st->execute();
    $st->close();

    // also delete marks details (will be re-inserted)
    $st = $conn->prepare("DELETE FROM section_subject_details WHERE section_id=?");
    $st->bind_param("i", $section_id);
    $st->execute();
    $st->close();

    // 2) insert / reuse subjects + link to section
    $stFind = $conn->prepare("SELECT subject_id FROM subjects WHERE subject_name=? LIMIT 1");
    $stIns  = $conn->prepare("INSERT INTO subjects(subject_name,is_active) VALUES(?,1)");
    $stLink = $conn->prepare("INSERT INTO section_subjects(section_id,subject_id) VALUES(?,?)");
    $stMark = $conn->prepare("
      INSERT INTO section_subject_details(section_id,subject_id,max_mark)
      VALUES(?,?,?)
    ");

    foreach ($rows as $r) {
      $name = $r["subject_name"];
      $mark = (int)$r["max_mark"];

      // subject_id
      $subject_id = 0;
      $stFind->bind_param("s", $name);
      $stFind->execute();
      $found = $stFind->get_result()->fetch_assoc();

      if ($found) {
        $subject_id = (int)$found["subject_id"];
      } else {
        $stIns->bind_param("s", $name);
        $stIns->execute();
        $subject_id = (int)$conn->insert_id;
      }

      // link section_subjects
      $stLink->bind_param("ii", $section_id, $subject_id);
      $stLink->execute();

      // store mark
      $stMark->bind_param("iii", $section_id, $subject_id, $mark);
      $stMark->execute();
    }

    $stFind->close();
    $stIns->close();
    $stLink->close();
    $stMark->close();

    $conn->commit();

    // activity log
    $action = ($isEdit || isset($_POST["mode"]) && $_POST["mode"] === "update") ? "UPDATE_SUBJECTS" : "ASSIGN_SUBJECTS";
    $details = "section_id=$section_id, subjects=" . count($rows);
    $st = $conn->prepare("INSERT INTO activity_logs(user_id, action, entity, entity_id, details) VALUES(?,?,?,?,?)");
    $entity = "sections";
    $eid = $section_id;
    $st->bind_param("issis", $userId, $action, $entity, $eid, $details);
    $st->execute();
    $st->close();

    setFlash("success", "Waa guul!", "Subjects waa la kaydiyey si sax ah.");
    header("Location: subjects.php?view=classes");
    exit;

  } catch (Throwable $e) {
    $conn->rollback();
    setFlash("error", "Qalad!", "Wax cilad ah ayaa dhacday: " . $e->getMessage());
    header("Location: subjects.php?view=assign" . ($section_id ? "&edit_section_id=".$section_id : ""));
    exit;
  }
}

/* =========================
   CLASSES VIEW DATA (cards)
   ========================= */
$cards = [];
$rs = $conn->query("
  SELECT
    s.section_id,
    CONCAT(g.grade_name,' - ', s.section_name) AS display_name,
    COUNT(ss.subject_id) AS total_subjects,
    COALESCE(SUM(ssd.max_mark), 0) AS total_exam_marks,
    MIN(sub.subject_name) AS first_subject
  FROM sections s
  JOIN grades g ON g.grade_id = s.grade_id
  LEFT JOIN section_subjects ss ON ss.section_id = s.section_id
  LEFT JOIN subjects sub ON sub.subject_id = ss.subject_id
  LEFT JOIN section_subject_details ssd
    ON ssd.section_id = ss.section_id AND ssd.subject_id = ss.subject_id
  GROUP BY s.section_id
  ORDER BY g.sort_order ASC, s.section_name ASC
");
while ($row = $rs->fetch_assoc()) $cards[] = $row;

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Subjects</title>

  <!-- SweetAlert2 -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <style>
    :root{
      --bg:#f6f7fb;
      --card:#ffffff;
      --ink:#121826;
      --muted:#6b7280;
      --primary:#4f46e5;
      --primary2:#3b82f6;
      --stroke:#dbe1ff;
      --shadow: 0 16px 40px rgba(17,24,39,.10);
      --soft: 0 10px 30px rgba(79,70,229,.10);
      --radius: 22px;
    }
    *{box-sizing:border-box}
    body{
      margin:0;
      font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
      background:var(--bg);
      color:var(--ink);
    }
    .wrap{max-width:1200px;margin:0 auto;padding:22px}
    .topbar{
      background:var(--card);
      border-radius:16px;
      padding:14px 18px;
      display:flex;
      align-items:center;
      gap:12px;
      box-shadow: 0 2px 10px rgba(0,0,0,.04);
      border:1px solid #eef0ff;
    }
    .crumb{
      display:flex;align-items:center;gap:10px;color:var(--muted);font-weight:600
    }
    .crumb b{color:var(--ink)}
    .navBtns{margin-left:auto;display:flex;gap:10px;flex-wrap:wrap}
    .btn{
      border:1px solid #e5e7eb;
      background:#fff;
      padding:10px 14px;
      border-radius:12px;
      cursor:pointer;
      font-weight:700;
      color:#111827;
      display:inline-flex;align-items:center;gap:10px;
      transition:.15s;
      text-decoration:none;
    }
    .btn:hover{transform: translateY(-1px); box-shadow: 0 10px 18px rgba(0,0,0,.06);}
    .btn.primary{
      border-color: transparent;
      background: linear-gradient(135deg, #ffcf6d, #fbbf24);
      color:#111827;
      padding:12px 16px;
    }
    .btn.blue{
      border-color: rgba(79,70,229,.25);
      background: rgba(79,70,229,.06);
      color: var(--primary);
    }

    /* ====== Layout sections ====== */
    .content{margin-top:18px}

    /* ====== Assign form card (center) ====== */
    .center{
      display:flex;
      justify-content:center;
      padding:22px 0;
    }
    .panel{
      width:min(720px, 96vw);
      background:var(--card);
      border-radius:var(--radius);
      box-shadow: var(--shadow);
      border:1px solid #eef0ff;
      padding:26px 26px 22px;
    }
    .panel h1{
      margin:0;
      text-align:center;
      font-size:34px;
      letter-spacing:.2px;
    }
    .legend{
      display:flex;justify-content:center;gap:22px;
      margin-top:6px;color:var(--muted);font-weight:700
    }
    .pill{
      display:inline-flex;align-items:center;gap:8px
    }
    .dot{
      width:34px;height:10px;border-radius:999px;
      background: #9ca3af;
    }
    .dot.req{background: var(--primary)}
    .form{
      margin-top:18px;
      display:grid;
      gap:16px;
    }
    .field{
      position:relative;
      background:#fff;
      border:2px solid rgba(79,70,229,.35);
      border-radius:999px;
      padding:16px 18px;
      box-shadow: var(--soft);
    }
    .field.small{padding:14px 16px}
    .label{
      position:absolute;
      top:-12px; left:18px;
      background: var(--primary);
      color:#fff;
      padding:4px 14px;
      border-radius:999px;
      font-size:13px;
      font-weight:800;
      box-shadow: 0 8px 18px rgba(79,70,229,.18);
    }
    select, input{
      width:100%;
      border:none;
      outline:none;
      font-size:22px;
      color:#374151;
      background:transparent;
    }
    input::placeholder{color:#9ca3af}
    .row2{
      display:grid;
      grid-template-columns: 1.2fr .7fr;
      gap:14px;
    }

    .miniActions{
      display:flex;justify-content:center;gap:12px;
      margin:8px 0 6px;
    }
    .miniBtn{
      border:none;
      cursor:pointer;
      border-radius:999px;
      padding:10px 18px;
      font-weight:900;
      display:inline-flex;align-items:center;gap:10px;
      color:#fff;
      box-shadow: 0 14px 28px rgba(0,0,0,.10);
      transition:.15s;
    }
    .miniBtn.add{background:#9ca3af}
    .miniBtn.remove{background:#111827}
    .miniBtn:hover{transform: translateY(-1px)}
    .submitWrap{display:flex;justify-content:center;margin-top:14px}
    .bigSubmit{
      border:none;
      cursor:pointer;
      border-radius:999px;
      padding:16px 34px;
      font-size:22px;
      font-weight:900;
      background: linear-gradient(135deg, #ffcf6d, #fbbf24);
      box-shadow: 0 20px 40px rgba(251,191,36,.30);
      display:inline-flex;align-items:center;gap:12px;
    }

    /* ====== Classes cards ====== */
    .classesHeader{
      display:flex;align-items:center;gap:12px;
      margin:14px 0 14px;
    }
    .searchBox{
      margin-left:auto;
      width:min(360px, 90vw);
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius:999px;
      padding:10px 14px;
      display:flex;align-items:center;gap:10px;
      box-shadow: 0 10px 24px rgba(0,0,0,.05);
    }
    .searchBox input{font-size:16px}
    .grid{
      display:grid;
      grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
      gap:16px;
      align-items:start;
    }
    .classCard{
      background:#fff;
      border-radius:20px;
      border:1px solid #eef0ff;
      box-shadow: 0 14px 34px rgba(17,24,39,.08);
      padding:16px;
      position:relative;
      overflow:hidden;
    }
    .ccTop{
      display:flex;align-items:center;gap:10px;
    }
    .ccTitle{
      font-size:30px;
      font-weight:900;
      letter-spacing:.2px;
    }
    .editIcon{
      margin-left:auto;
      width:40px;height:40px;
      border-radius:12px;
      display:grid;place-items:center;
      border:1px solid rgba(79,70,229,.25);
      background: rgba(79,70,229,.06);
      cursor:pointer;
      text-decoration:none;
      color: var(--primary);
      font-weight:900;
    }
    .ccBody{
      margin-top:10px;
      color:var(--muted);
      font-weight:800;
      line-height:1.35;
      text-transform:uppercase;
      font-size:12px;
      letter-spacing:.7px;
    }
    .ccNums{
      margin-top:8px;
      font-size:48px;
      font-weight:950;
      color:#111827;
      line-height:1;
    }
    .ring{
      margin-top:12px;
      width:120px;height:120px;
      border-radius:50%;
      border:10px solid rgba(79,70,229,.18);
      display:grid;
      place-items:center;
      position:relative;
    }
    .ring b{font-size:26px;color:#111827}
    .ring span{display:block;margin-top:-4px;color:var(--muted);font-weight:800;font-size:12px;text-transform:none}
    .firstSub{
      margin-top:4px;
      color: var(--primary);
      font-weight:950;
      text-transform:lowercase;
      font-size:18px;
      text-align:center;
    }

    .assignTile{
      background:#fff;
      border-radius:20px;
      border:3px dotted rgba(79,70,229,.55);
      box-shadow: 0 16px 34px rgba(17,24,39,.06);
      padding:18px;
      min-height: 220px;
      display:grid;
      place-items:center;
      text-decoration:none;
      color: var(--primary);
      font-weight:950;
    }
    .assignTile .plus{
      width:62px;height:62px;border-radius:18px;
      background: rgba(79,70,229,.08);
      display:grid;place-items:center;
      font-size:42px;
      border:1px solid rgba(79,70,229,.25);
    }
    .assignTile .txt{
      margin-top:8px;
      text-align:center;
      font-size:22px;
      line-height:1.1;
    }

    /* Responsive */
    @media (max-width: 720px){
      .row2{grid-template-columns:1fr}
      .panel h1{font-size:28px}
    }
  </style>
</head>

<body>
<div class="wrap">

  <div class="topbar">
    <div class="crumb">
      <b>Subjects</b>
      <span>|</span>
      <span>🏠</span>
      <span> - </span>
      <span><?= ($view==="assign" ? "Assign Subjects" : "Classes With Subjects") ?></span>
    </div>

    <div class="navBtns">
      <a class="btn <?= $view==="classes" ? "blue" : "" ?>" href="subjects.php?view=classes">Classes With Subjects</a>
      <a class="btn <?= $view==="assign" ? "blue" : "" ?>" href="subjects.php?view=assign">Assign Subjects</a>
    </div>
  </div>

  <div class="content">

    <?php if ($view === "assign"): ?>
      <div class="center">
        <div class="panel">
          <h1><?= $isEdit ? "Update Subjects" : "Create Subjects" ?></h1>
          <div class="legend">
            <div class="pill"><span class="dot req"></span> Required*</div>
            <div class="pill"><span class="dot"></span> Optional</div>
          </div>

          <form class="form" method="post" action="subjects.php?view=assign<?= $isEdit ? "&edit_section_id=".$editSectionId : "" ?>">
            <input type="hidden" name="action" value="save_subjects">
            <input type="hidden" name="mode" value="<?= $isEdit ? "update" : "create" ?>">

            <!-- Select Class -->
            <div class="field">
              <div class="label">Select Class*</div>

              <!-- Search input for class -->
              <input id="classSearch" type="text" placeholder="Raadi Class..." style="font-size:16px;margin-bottom:8px;color:#6b7280;" />

              <select name="section_id" id="sectionSelect" required>
                <option value="">Select*</option>
                <?php foreach ($sections as $s): ?>
                  <option value="<?= (int)$s["section_id"] ?>"
                    <?= ($isEdit && (int)$s["section_id"] === (int)$editData["section_id"]) ? "selected" : "" ?>>
                    <?= h($s["display_name"]) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Subjects rows -->
            <div id="rowsWrap">
              <?php
                $prefill = $isEdit ? $editData["rows"] : [
                  ["subject_name"=>"", "max_mark"=>""],
                  ["subject_name"=>"", "max_mark"=>""],
                ];
                if (count($prefill) === 0) $prefill = [["subject_name"=>"", "max_mark"=>""]];
              ?>

              <?php foreach ($prefill as $r): ?>
                <div class="row2 subjectRow">
                  <div class="field small">
                    <div class="label">Subject Name*</div>
                    <input name="subject_name[]" placeholder="Name Of Subject" value="<?= h($r["subject_name"] ?? "") ?>" required />
                  </div>
                  <div class="field small">
                    <div class="label">Marks*</div>
                    <input name="max_mark[]" type="number" min="0" max="100" placeholder="Total Exam Mark" value="<?= h((string)($r["max_mark"] ?? "")) ?>" required />
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="miniActions">
              <button type="button" class="miniBtn add" id="addRowBtn">＋ Add More</button>
              <button type="button" class="miniBtn remove" id="removeRowBtn">－ Remove</button>
            </div>

            <div class="submitWrap">
              <button class="bigSubmit" type="submit">＋ <?= $isEdit ? "Update Subjects" : "Assign Subjects" ?></button>
            </div>
          </form>

        </div>
      </div>

    <?php else: ?>
      <div class="classesHeader">
        <h2 style="margin:0;font-size:22px;letter-spacing:.2px;">Classes With Subjects</h2>

        <div class="searchBox">
          <span style="color:#9ca3af;font-weight:900;">🔎</span>
          <input id="cardSearch" type="text" placeholder="Raadi Class..." />
        </div>
      </div>

      <div class="grid" id="cardsGrid">

        <!-- Assign tile -->
        <a class="assignTile" href="subjects.php?view=assign">
          <div>
            <div class="plus">＋</div>
            <div class="txt">Assign<br/>Subjects</div>
          </div>
        </a>

        <?php foreach ($cards as $c): ?>
          <?php
            $totalSubjects = (int)$c["total_subjects"];
            $totalMarks    = (int)$c["total_exam_marks"];
            $firstSub      = (string)($c["first_subject"] ?? "");
          ?>
          <div class="classCard" data-name="<?= h(mb_strtolower((string)$c["display_name"])) ?>">
            <div class="ccTop">
              <div class="ccTitle"><?= h($c["display_name"]) ?></div>
              <a class="editIcon" title="Edit" href="subjects.php?view=assign&edit_section_id=<?= (int)$c["section_id"] ?>">✎</a>
            </div>

            <div class="ccBody">
              <div style="margin-top:12px;">
                <div><?= $totalSubjects ?> <span style="font-weight:800;color:#9ca3af;">TOTAL SUBJECTS</span></div>
                <div style="margin-top:10px;">
                  <div class="ccNums"><?= $totalMarks ?></div>
                  <div style="margin-top:4px;">TOTAL EXAM</div>
                  <div style="margin-top:4px;">MARKS</div>
                </div>
              </div>

              <div style="display:flex;justify-content:flex-start;gap:14px;margin-top:14px;">
                <div>
                  <div class="ring">
                    <div style="text-align:center;">
                      <b><?= $totalMarks ?></b>
                      <span>Marks</span>
                    </div>
                  </div>
                  <div class="firstSub"><?= $firstSub !== "" ? h($firstSub) : "—" ?></div>
                </div>
              </div>

            </div>
          </div>
        <?php endforeach; ?>

      </div>
    <?php endif; ?>

  </div>
</div>

<script>
  // SweetAlert flash
  <?php if ($alert): ?>
    Swal.fire({
      icon: <?= json_encode($alert["type"]) ?>,
      title: <?= json_encode($alert["title"]) ?>,
      text: <?= json_encode($alert["text"]) ?>,
      confirmButtonText: "Haye"
    });
  <?php endif; ?>

  // Add/Remove subject rows
  const rowsWrap = document.getElementById("rowsWrap");
  const addRowBtn = document.getElementById("addRowBtn");
  const removeRowBtn = document.getElementById("removeRowBtn");

  function makeRow() {
    const div = document.createElement("div");
    div.className = "row2 subjectRow";
    div.innerHTML = `
      <div class="field small">
        <div class="label">Subject Name*</div>
        <input name="subject_name[]" placeholder="Name Of Subject" required />
      </div>
      <div class="field small">
        <div class="label">Marks*</div>
        <input name="max_mark[]" type="number" min="0" max="100" placeholder="Total Exam Mark" required />
      </div>
    `;
    return div;
  }

  if (addRowBtn && rowsWrap) {
    addRowBtn.addEventListener("click", () => {
      rowsWrap.appendChild(makeRow());
    });
  }
  if (removeRowBtn && rowsWrap) {
    removeRowBtn.addEventListener("click", () => {
      const rows = rowsWrap.querySelectorAll(".subjectRow");
      if (rows.length <= 1) {
        Swal.fire({icon:"warning", title:"Digniin!", text:"Ugu yaraan hal row waa inuu jiro.", confirmButtonText:"Haye"});
        return;
      }
      rows[rows.length - 1].remove();
    });
  }

  // Live search for cards
  const cardSearch = document.getElementById("cardSearch");
  const cardsGrid = document.getElementById("cardsGrid");
  if (cardSearch && cardsGrid) {
    cardSearch.addEventListener("input", () => {
      const q = cardSearch.value.trim().toLowerCase();
      cardsGrid.querySelectorAll(".classCard").forEach(card => {
        const name = card.getAttribute("data-name") || "";
        card.style.display = name.includes(q) ? "" : "none";
      });
    });
  }

  // "Search class" for select (simple filter)
  const classSearch = document.getElementById("classSearch");
  const sectionSelect = document.getElementById("sectionSelect");
  if (classSearch && sectionSelect) {
    const originalOptions = Array.from(sectionSelect.options).map(o => ({value:o.value, text:o.text, selected:o.selected}));
    classSearch.addEventListener("input", () => {
      const q = classSearch.value.trim().toLowerCase();

      // rebuild
      sectionSelect.innerHTML = "";
      originalOptions.forEach(opt => {
        if (opt.value === "" || opt.text.toLowerCase().includes(q)) {
          const o = document.createElement("option");
          o.value = opt.value;
          o.textContent = opt.text;
          if (opt.selected) o.selected = true;
          sectionSelect.appendChild(o);
        }
      });
    });
  }
</script>

</body>
</html>
