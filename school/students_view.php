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

/* Keep filters for back link */
$keepQs = [];
if (isset($_GET["section_id"])) $keepQs["section_id"] = (int)$_GET["section_id"];
if (isset($_GET["year_id"]))    $keepQs["year_id"]    = (int)$_GET["year_id"];
if (isset($_GET["q"]))          $keepQs["q"]          = trim((string)$_GET["q"]);
$keepQuery = http_build_query(array_filter($keepQs, fn($v)=> $v!==0 && $v!=="" && $v!==null));

$studentId = (int)($_GET["id"] ?? 0);
if ($studentId <= 0) {
  setFlash("error", "ID-ga ardayga ma saxna.");
  header("Location: students.php".($keepQuery ? "?".$keepQuery : ""));
  exit;
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
   LOAD STUDENT (with enrollment)
   ========================= */
$sql = "
SELECT
  s.student_id,
  s.admission_no,
  s.first_name, s.middle_name, s.last_name,
  CONCAT(s.first_name,' ',s.middle_name,' ',s.last_name) AS fullname,
  s.mother_full_name,
  s.gender, s.dob, s.nationality, s.place_of_birth,
  s.profile_photo_url,
  s.phone, s.address, s.emergency_contact_name, s.emergency_contact_phone,
  s.created_at,

  COALESCE(ec.enrollment_id, el.enrollment_id) AS enrollment_id,
  COALESCE(ec.year_id, el.year_id) AS year_id,
  COALESCE(yc.year_name, yl.year_name) AS year_name,
  COALESCE(ec.section_id, el.section_id) AS section_id,
  COALESCE(CONCAT(gc.grade_name,'-',sc.section_name), CONCAT(gl.grade_name,'-',sl.section_name)) AS class_name,
  COALESCE(ec.roll_no, el.roll_no) AS roll_no

FROM students s

LEFT JOIN enrollments ec
  ON ec.student_id = s.student_id AND ec.year_id = ? AND ec.status='ENROLLED'
LEFT JOIN academic_years yc ON yc.year_id = ec.year_id
LEFT JOIN sections sc ON sc.section_id = ec.section_id
LEFT JOIN grades gc ON gc.grade_id = sc.grade_id

LEFT JOIN (
  SELECT e1.*
  FROM enrollments e1
  JOIN (
    SELECT student_id, MAX(enrollment_id) AS mx
    FROM enrollments
    WHERE status='ENROLLED'
    GROUP BY student_id
  ) t ON t.student_id = e1.student_id AND t.mx = e1.enrollment_id
) el ON el.student_id = s.student_id
LEFT JOIN academic_years yl ON yl.year_id = el.year_id
LEFT JOIN sections sl ON sl.section_id = el.section_id
LEFT JOIN grades gl ON gl.grade_id = sl.grade_id

WHERE s.student_id = ?
LIMIT 1
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $currentYearId, $studentId);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
  setFlash("error", "Ardayga lama helin (ID: ".$studentId.").");
  header("Location: students.php".($keepQuery ? "?".$keepQuery : ""));
  exit;
}

/* initials */
function initials(string $name): string {
  $name = trim(preg_replace('/\s+/', ' ', $name));
  if ($name === "") return "S";
  $parts = explode(" ", $name);
  $a = mb_substr($parts[0], 0, 1);
  $b = (count($parts) > 1) ? mb_substr($parts[1], 0, 1) : "";
  $out = mb_strtoupper($a.$b);
  return $out ?: "S";
}

/* Pretty */
function fmtDate(?string $d): string {
  $d = trim((string)$d);
  if ($d === "") return "—";
  $t = strtotime($d);
  if (!$t) return $d;
  return date("d F, Y", $t);
}

$fullName   = (string)($student["fullname"] ?? "");
$photo      = trim((string)($student["profile_photo_url"] ?? ""));
$className  = trim((string)($student["class_name"] ?? ""));
$adNo       = (string)($student["admission_no"] ?? "");
$dob        = (string)($student["dob"] ?? "");
$gender     = (string)($student["gender"] ?? "");
$phone      = (string)($student["phone"] ?? "");
$national   = (string)($student["nationality"] ?? "");
$pob        = (string)($student["place_of_birth"] ?? "");
$mother     = (string)($student["mother_full_name"] ?? "");
$addr       = (string)($student["address"] ?? "");
$emName     = (string)($student["emergency_contact_name"] ?? "");
$emPhone    = (string)($student["emergency_contact_phone"] ?? "");
$yearName   = (string)($student["year_name"] ?? "");
$rollNo     = (string)($student["roll_no"] ?? "");
$createdAt  = (string)($student["created_at"] ?? "");
$enrollment = (int)($student["enrollment_id"] ?? 0);
$sectionId  = (int)($student["section_id"] ?? 0);
$yearId     = (int)($student["year_id"] ?? 0);

$genderLabel = "—";
if ($gender === "M") $genderLabel = "Male";
if ($gender === "F") $genderLabel = "Female";

/* =========================
   EXAMS FOR THIS STUDENT (year based)
   ========================= */
$studentExams = [];
$selectedExamId = (int)($_GET["exam_id"] ?? 0);

if ($sectionId > 0) {
  $stmt = $conn->prepare("
    SELECT e.exam_id, e.exam_name,
           y.year_name, t.term_name, y.is_current, e.start_date
    FROM exams e
    JOIN academic_years y ON y.year_id=e.year_id
    JOIN exam_terms t ON t.term_id=e.term_id
    WHERE e.year_id = ?
    ORDER BY y.is_current DESC, e.start_date DESC, e.exam_id DESC
  ");
  $useYear = $yearId > 0 ? $yearId : $currentYearId;
  $stmt->bind_param("i", $useYear);
  $stmt->execute();
  $studentExams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}

/* Auto select latest exam if exam_id not provided */
if ($selectedExamId <= 0 && $studentExams) {
  $selectedExamId = (int)$studentExams[0]["exam_id"];
}

/* =========================
   EXAM REPORT (subjects + marks)
   ONLY table (no header/stats)
   ========================= */
$examRows = [];
if ($selectedExamId > 0 && $enrollment > 0) {
  $stmt = $conn->prepare("
    SELECT
      p.paper_id,
      sub.subject_name,
      mk.mark_value,
      mk.result
    FROM exam_papers p
    JOIN subjects sub ON sub.subject_id=p.subject_id
    JOIN enrollments en ON en.section_id=p.section_id
    LEFT JOIN marks mk ON mk.paper_id=p.paper_id AND mk.enrollment_id=en.enrollment_id
    WHERE p.exam_id=? AND en.enrollment_id=?
    ORDER BY sub.subject_name ASC
  ");
  $stmt->bind_param("ii", $selectedExamId, $enrollment);
  $stmt->execute();
  $examRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}

$flash = $_SESSION["flash"] ?? null;
unset($_SESSION["flash"]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Student Report</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="stylesheet" href="bootstrap.css" />
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <style>
    :root{
      --bg:#f4f7ff;
      --card:#ffffff;
      --text:#0f172a;
      --muted:#64748b;
      --border:#e6edf8;
      --purple:#7c3aed;
      --shadow:0 16px 45px rgba(2,6,23,.08);
      --radius:18px;
      --green:#16a34a;
      --red:#ef4444;
    }
    body{ background:var(--bg); color:var(--text); }
    .wrap{ max-width:1300px; margin:18px auto; padding:0 14px 30px; }

    .topbar{
      background:var(--card);
      border:1px solid var(--border);
      border-radius:var(--radius);
      box-shadow:var(--shadow);
      padding:14px 16px;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      flex-wrap:wrap;
    }
    .crumb{
      font-weight:1000;
      color:var(--muted);
      font-size:13px;
      display:flex;
      gap:8px;
      align-items:center;
      flex-wrap:wrap;
    }
    .crumb b{ color:var(--text); }
    .pill{
      background:#eef2ff;
      border:1px solid #dbeafe;
      color:#1d4ed8;
      padding:4px 10px;
      border-radius:999px;
      font-size:12px;
      font-weight:1000;
      text-decoration:none;
      display:inline-block;
    }
    .btnPdf{
      background:#eef2ff;
      border:1px solid var(--border);
      border-radius:14px;
      font-weight:1000;
      padding:10px 14px;
    }

    .layout{
      margin-top:14px;
      display:grid;
      grid-template-columns: 380px 1fr;
      gap:14px;
      align-items:start;
    }
    @media(max-width:980px){ .layout{ grid-template-columns:1fr; } }

    .card{
      background:var(--card);
      border:1px solid var(--border);
      border-radius:var(--radius);
      box-shadow:var(--shadow);
    }

    .profile{ padding:16px; }
    .avatarWrap{
      width:170px;
      height:170px;
      border-radius:999px;
      border:8px solid #f1f5ff;
      background:#eef2ff;
      overflow:hidden;
      display:flex;
      align-items:center;
      justify-content:center;
      margin:0 auto 12px;
    }
    .avatarWrap img{ width:100%; height:100%; object-fit:cover; display:block; }
    .avatarInitial{ font-size:48px; font-weight:1000; color:#1d4ed8; }
    .studentName{
      text-align:center;
      font-weight:1000;
      font-size:28px;
      color:#7c3aed;
      margin:0;
      line-height:1.1;
    }
    .subTag{
      text-align:center;
      margin-top:6px;
      display:flex;
      justify-content:center;
      gap:8px;
      flex-wrap:wrap;
    }

    .infoBox{
      margin-top:14px;
      background:#f8fafc;
      border:1px solid var(--border);
      border-radius:16px;
      padding:14px;
    }
    .infoRow{
      display:grid;
      gap:10px;
      padding:8px 0;
      border-bottom:1px solid rgba(148,163,184,.25);
    }
    .infoRow:last-child{ border-bottom:none; }
    .lbl{ font-size:12px; font-weight:1000; color:#94a3b8; }
    .val{
      font-size:16px;
      font-weight:1000;
      color:#1f2937;
      display:flex;
      gap:10px;
      align-items:center;
    }
    .arrow{
      width:18px; height:18px;
      border-radius:999px;
      border:1px solid rgba(148,163,184,.35);
      display:inline-flex;
      align-items:center;
      justify-content:center;
      color:#94a3b8;
      font-weight:1000;
    }

    .right{ padding:16px; }
    .reportGrid{
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap:14px;
    }
    @media(max-width:980px){ .reportGrid{ grid-template-columns:1fr; } }

    .reportTitle{
      display:flex;
      align-items:center;
      gap:10px;
      font-weight:1000;
      color:var(--purple);
      margin:0 0 10px;
      font-size:20px;
    }
    .num{
      width:26px; height:26px;
      border-radius:999px;
      background:#e9d5ff;
      color:#6d28d9;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      font-weight:1000;
      font-size:14px;
    }
    .reportCard{ padding:14px; }

    .emptyBox{
      border-radius:16px;
      border:1px solid var(--border);
      background:#fff;
      padding:24px;
      min-height:210px;
      display:flex;
      align-items:center;
      justify-content:center;
      text-align:center;
      color:#94a3b8;
      font-weight:1000;
    }

    .tableWrap{ overflow:auto; border:1px solid var(--border); border-radius:16px; }
    table{ width:100%; border-collapse:collapse; }
    th,td{ padding:10px 12px; border-bottom:1px solid rgba(148,163,184,.25); font-weight:900; }
    th{ font-size:12px; color:#64748b; text-transform:uppercase; letter-spacing:.04em; background:#f8fafc; }
    td{ font-size:14px; }
    .badgeOk{ display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:1000; }
    .pass{ background:#dcfce7; color:#166534; border:1px solid #bbf7d0; }
    .fail{ background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
    .muted{ color:#64748b; font-weight:900; }

    .examToolbar{
      display:flex; gap:10px; flex-wrap:wrap;
      align-items:center; justify-content:flex-start;
      margin-bottom:12px;
    }
    .select{
      padding:10px 12px; border-radius:14px; border:1px solid var(--border);
      background:#fff; font-weight:900;
    }
    .btnSmall{
      background:#eef2ff;
      border:1px solid var(--border);
      border-radius:14px;
      font-weight:1000;
      padding:10px 12px;
      text-decoration:none;
      color:#1d4ed8;
    }

    @media print{
      .noPrint{ display:none !important; }
      body{ background:#fff; }
      .card{ box-shadow:none !important; }
      .wrap{ max-width:100%; margin:0; padding:0; }
    }
  </style>
</head>
<body>

<div class="wrap" id="printArea">

  <div class="topbar noPrint">
    <div class="crumb">
      <b>Students</b> <span>›</span> <span>Student Report</span>
      <a class="pill" href="students.php<?= $keepQuery ? ("?".$keepQuery) : "" ?>">← Back</a>
      <span class="pill">ID: <?= (int)$studentId ?></span>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <button class="btnPdf" type="button" id="btnPdf">Get PDF</button>
    </div>
  </div>

  <div class="layout">

    <!-- LEFT PROFILE -->
    <div class="card profile">
      <div class="avatarWrap">
        <?php if($photo !== ""): ?>
          <img src="<?= h($photo) ?>" alt="photo" onerror="this.style.display='none';document.getElementById('fallbackInit').style.display='block';">
          <span id="fallbackInit" class="avatarInitial" style="display:none;"><?= h(initials($fullName)) ?></span>
        <?php else: ?>
          <span class="avatarInitial"><?= h(initials($fullName)) ?></span>
        <?php endif; ?>
      </div>

      <h2 class="studentName"><?= h($fullName ?: "—") ?></h2>

      <div class="subTag">
        <span class="pill"><?= h($className ?: "Not Enrolled") ?></span>
        <span class="pill"><?= h($yearName ?: "—") ?></span>
      </div>

      <div class="infoBox">
        <div class="infoRow">
          <div class="lbl">Student ID</div>
          <div class="val"><span class="arrow">↳</span> <?= (int)$studentId ?></div>
        </div>

        <div class="infoRow">
          <div class="lbl">Registration / Admission No</div>
          <div class="val"><span class="arrow">↳</span> <?= h($adNo ?: "—") ?></div>
        </div>

        <div class="infoRow">
          <div class="lbl">Date of Admission</div>
          <div class="val"><span class="arrow">↳</span> <?= h($createdAt ? fmtDate(substr($createdAt,0,10)) : "—") ?></div>
        </div>

        <div class="infoRow">
          <div class="lbl">Roll No</div>
          <div class="val"><span class="arrow">↳</span> <?= h($rollNo ?: "—") ?></div>
        </div>

        <div class="infoRow">
          <div class="lbl">Date of Birth</div>
          <div class="val"><span class="arrow">↳</span> <?= h(fmtDate($dob)) ?></div>
        </div>

        <div class="infoRow">
          <div class="lbl">Gender</div>
          <div class="val"><span class="arrow">↳</span> <?= h($genderLabel) ?></div>
        </div>

        <div class="infoRow">
          <div class="lbl">Phone</div>
          <div class="val"><span class="arrow">↳</span> <?= h($phone ?: "—") ?></div>
        </div>

        <div class="infoRow">
          <div class="lbl">Mother Full Name</div>
          <div class="val"><span class="arrow">↳</span> <?= h($mother ?: "—") ?></div>
        </div>

        <div class="infoRow">
          <div class="lbl">Nationality</div>
          <div class="val"><span class="arrow">↳</span> <?= h($national ?: "—") ?></div>
        </div>

        <div class="infoRow">
          <div class="lbl">Place of Birth</div>
          <div class="val"><span class="arrow">↳</span> <?= h($pob ?: "—") ?></div>
        </div>

        <div class="infoRow">
          <div class="lbl">Address</div>
          <div class="val"><span class="arrow">↳</span> <?= h($addr ?: "—") ?></div>
        </div>

        <div class="infoRow">
          <div class="lbl">Emergency Contact</div>
          <div class="val">
            <span class="arrow">↳</span>
            <?= h(($emName ?: "—") . ($emPhone ? " • ".$emPhone : "")) ?>
          </div>
        </div>
      </div>
    </div>

    <!-- RIGHT REPORTS -->
    <div class="card right">
      <div class="reportGrid">

        <div class="card reportCard">
          <div class="reportTitle"><span class="num">1</span> Attendance Report</div>
          <div class="emptyBox">
            <div>
              <div style="font-size:18px;">⏳ Attendance wali lama xirin.</div>
              <div style="font-size:12px; font-weight:1000; margin-top:6px;">
                Marka aad attendance_records buuxiso, halkan ayaan ku soo bandhigi karnaa.
              </div>
            </div>
          </div>
        </div>

        <div class="card reportCard">
          <div class="reportTitle"><span class="num">2</span> Fee Report</div>
          <div class="emptyBox">
            <div>
              <div style="font-size:18px;">💳 Fees / Payments</div>
              <div style="font-size:12px; font-weight:1000; margin-top:6px;">
                Marka invoices/payments jiraan, halkan ayaan ku soo saar doonaa.
              </div>
            </div>
          </div>
        </div>

        <!-- ✅ EXAMINATION REPORT (ONLY TABLE) -->
        <div class="card reportCard" style="grid-column:1 / -1;">
          <div class="reportTitle"><span class="num">3</span> Examination Report</div>

          <?php if ($enrollment <= 0 || $sectionId <= 0): ?>
            <div class="emptyBox">
              <div>
                <div style="font-size:18px;">📝 Ardaygan enrollment ma leh.</div>
                <div style="font-size:12px; font-weight:1000; margin-top:6px;">
                  Hubi in ardayga lagu daray class (enrollments table).
                </div>
              </div>
            </div>
          <?php elseif (!$studentExams): ?>
            <div class="emptyBox">
              <div>
                <div style="font-size:18px;">📝 Imtixaan lama helin sanadkan.</div>
                <div style="font-size:12px; font-weight:1000; margin-top:6px;">
                  Marka exams.php aad ka sameyso exam, halkan ayuu ka muuqanayaa.
                </div>
              </div>
            </div>
          <?php else: ?>

            <div class="examToolbar noPrint">
              <form method="get" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                <input type="hidden" name="id" value="<?= (int)$studentId ?>"/>
                <?php if ($keepQuery): ?>
                  <?php foreach ($keepQs as $k=>$v): if ($v!==0 && $v!=="" && $v!==null): ?>
                    <input type="hidden" name="<?= h($k) ?>" value="<?= h((string)$v) ?>"/>
                  <?php endif; endforeach; ?>
                <?php endif; ?>

                <select class="select" name="exam_id" onchange="this.form.submit()">
                  <?php foreach ($studentExams as $ex): ?>
                    <option value="<?= (int)$ex["exam_id"] ?>" <?= ((int)$ex["exam_id"]===$selectedExamId) ? "selected" : "" ?>>
                      <?= h($ex["exam_name"]) ?> • <?= h($ex["term_name"]) ?> • <?= h($ex["year_name"]) ?>
                    </option>
                  <?php endforeach; ?>
                </select>

                <a class="btnSmall" href="results.php?exam_id=<?= (int)$selectedExamId ?>&enrollment_id=<?= (int)$enrollment ?>">Open Full Results</a>
                <a class="btnSmall" href="edit_mark.php?exam_id=<?= (int)$selectedExamId ?>&enrollment_id=<?= (int)$enrollment ?>">Edit Marks</a>
              </form>
            </div>

            <div class="tableWrap">
              <table>
                <thead>
                  <tr>
                    <th style="width:45%;">Subject</th>
                    <th>Mark</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($examRows as $r): ?>
                    <tr>
                      <td><?= h($r["subject_name"]) ?></td>
                      <td><?= ($r["mark_value"]===null) ? "<span class='muted'>—</span>" : (int)$r["mark_value"] ?></td>
                      <td>
                        <?php if (($r["result"] ?? null) === "PASS"): ?>
                          <span class="badgeOk pass">PASS</span>
                        <?php elseif (($r["result"] ?? null) === "FAIL"): ?>
                          <span class="badgeOk fail">FAIL</span>
                        <?php else: ?>
                          <span class="muted">—</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>

                  <?php if (!$examRows): ?>
                    <tr><td colspan="3" class="muted">No papers found for this exam (exams.php → add papers).</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

          <?php endif; ?>
        </div>

        <div class="card reportCard">
          <div class="reportTitle"><span class="num">4</span> Notes</div>
          <div class="emptyBox">
            <div>
              <div style="font-size:18px;">📌 Student Notes</div>
              <div style="font-size:12px; font-weight:1000; margin-top:6px;">
                Haddii aad rabto “notes table”, waan kuu dhisi karnaa si macallimiintu u qoraan.
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>

  </div>
</div>

<script>
  document.getElementById("btnPdf").addEventListener("click", () => window.print());

  <?php if ($flash): ?>
  Swal.fire({
    icon: <?= json_encode($flash["type"] ?? "info") ?>,
    title: <?= json_encode(($flash["type"] ?? "info")==="error" ? "Error" : "Info") ?>,
    text: <?= json_encode($flash["msg"] ?? "") ?>,
    timer: 2200,
    showConfirmButton: false
  });
  <?php endif; ?>
</script>

</body>
</html>
