<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . "/conncation.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* =========================
   GUARD (Admin/Reception/Teacher)
   ========================= */
if (!isset($_SESSION["user_id"])) { header("Location: login.php"); exit; }
if (!isset($_SESSION["role"]) || !in_array($_SESSION["role"], ["ADMIN","RECEPTION","TEACHER"], true)) {
  header("Location: login.php"); exit;
}

$role = (string)($_SESSION["role"] ?? "");

/* =========================
   Helpers (avoid redeclare)
   ========================= */
if (!function_exists("h")) {
  function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8"); }
}
if (!function_exists("setFlash")) {
  function setFlash(string $type, string $title, string $text): void {
    $_SESSION["flash_alert"] = ["type"=>$type, "title"=>$title, "text"=>$text];
  }
}
if (!function_exists("popFlash")) {
  function popFlash(): ?array {
    if (!isset($_SESSION["flash_alert"])) return null;
    $a = $_SESSION["flash_alert"];
    unset($_SESSION["flash_alert"]);
    return $a;
  }
}
$alert = popFlash();

/* =========================
   CURRENT YEAR (fallback)
   ========================= */
$currentYear = null;
$rs = $conn->query("SELECT year_id, year_name FROM academic_years WHERE is_current=1 ORDER BY start_date DESC LIMIT 1");
$currentYear = $rs->fetch_assoc();

if (!$currentYear) {
  $rs = $conn->query("SELECT year_id, year_name FROM academic_years ORDER BY start_date DESC LIMIT 1");
  $currentYear = $rs->fetch_assoc();
}

$currentYearId = (int)($currentYear["year_id"] ?? 0);

/* =========================
   year_id selected
   ========================= */
$year_id = (int)($_GET["year_id"] ?? 0);
if ($year_id <= 0) $year_id = $currentYearId;

/* =========================
   Years list
   ========================= */
$years = [];
$rs = $conn->query("SELECT year_id, year_name, is_current FROM academic_years ORDER BY is_current DESC, start_date DESC");
while ($r = $rs->fetch_assoc()) $years[] = $r;

/* =========================
   Selected year record (for header)
   ========================= */
$selectedYearName = "";
if ($year_id > 0) {
  $st = $conn->prepare("SELECT year_name FROM academic_years WHERE year_id=? LIMIT 1");
  $st->bind_param("i", $year_id);
  $st->execute();
  $yy = $st->get_result()->fetch_assoc();
  $st->close();
  $selectedYearName = (string)($yy["year_name"] ?? "");
}

/* =========================
   Cards for classes (sections) with counts in selected year
   ========================= */
$sectionCards = [];
if ($year_id > 0) {
  $st = $conn->prepare("
    SELECT
      sec.section_id,
      CONCAT(g.grade_name,' - ', sec.section_name) AS class_name,
      COUNT(e.enrollment_id) AS total_students,
      SUM(CASE WHEN st.gender='M' THEN 1 ELSE 0 END) AS boys,
      SUM(CASE WHEN st.gender='F' THEN 1 ELSE 0 END) AS girls,
      SUM(CASE WHEN st.gender IS NULL THEN 1 ELSE 0 END) AS na_gender
    FROM sections sec
    JOIN grades g ON g.grade_id = sec.grade_id
    LEFT JOIN enrollments e
      ON e.section_id = sec.section_id
     AND e.year_id = ?
     AND UPPER(e.status)='ENROLLED'
    LEFT JOIN students st ON st.student_id = e.student_id
    GROUP BY sec.section_id, g.grade_name, sec.section_name, g.sort_order
    ORDER BY g.sort_order ASC, sec.section_name ASC
  ");
  $st->bind_param("i", $year_id);
  $st->execute();
  $res = $st->get_result();
  while ($r = $res->fetch_assoc()) $sectionCards[] = $r;
  $st->close();
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Attendance - Classes</title>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <style>
    :root{
      --bg:#f6f7fb;--card:#fff;--ink:#0f172a;--muted:#6b7280;
      --primary:#4f46e5;--stroke:#e9ecff;--shadow:0 18px 44px rgba(15,23,42,.10);
      --radius:18px;
    }
    *{box-sizing:border-box}
    body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;background:var(--bg);color:var(--ink)}
    .wrap{max-width:1250px;margin:0 auto;padding:18px}
    .top{background:var(--card);border:1px solid #eef0ff;border-radius:16px;padding:14px 16px;display:flex;align-items:center;gap:12px;box-shadow:0 2px 10px rgba(0,0,0,.04)}
    .actions{margin-left:auto;display:flex;gap:10px;flex-wrap:wrap}
    .btn{border:1px solid #e5e7eb;background:#fff;padding:10px 14px;border-radius:12px;font-weight:950;cursor:pointer;display:inline-flex;align-items:center;gap:10px;color:#111827;text-decoration:none;transition:.15s}
    .btn:hover{transform:translateY(-1px);box-shadow:0 14px 24px rgba(0,0,0,.06)}
    .btn.blue{border-color:rgba(79,70,229,.25);background:rgba(79,70,229,.06);color:var(--primary)}
    .card{margin-top:14px;background:var(--card);border:1px solid #eef0ff;border-radius:var(--radius);box-shadow:var(--shadow);padding:16px}
    .filters{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    @media (max-width:900px){.filters{grid-template-columns:1fr}}
    .field{border:2px solid rgba(79,70,229,.28);border-radius:999px;padding:12px 14px;background:#fff}
    .field label{display:block;font-size:12px;color:var(--muted);font-weight:950;margin-bottom:6px}
    select,input{width:100%;border:none;outline:none;background:transparent;font-size:15px}
    .hint{margin-top:10px;color:var(--muted);font-weight:850;font-size:13px;line-height:1.35}
    .gridCards{display:grid;grid-template-columns:repeat(3, 1fr);gap:14px}
    @media (max-width:1100px){.gridCards{grid-template-columns:repeat(2,1fr)}}
    @media (max-width:700px){.gridCards{grid-template-columns:1fr}}
    .classCard{border:1px solid var(--stroke);border-radius:22px;padding:18px;background:#fff;position:relative;cursor:pointer;transition:.15s;min-height:220px}
    .classCard:hover{transform:translateY(-2px);box-shadow:0 18px 40px rgba(79,70,229,.12)}
    .classTop{display:flex;align-items:flex-start;gap:10px}
    .className{font-weight:1000;font-size:30px;letter-spacing:.2px}
    .mini{margin-left:auto;display:flex;gap:10px;opacity:.9}
    .mini span{font-weight:1000;font-size:18px;color:#2563eb}
    .bigNum{font-size:72px;font-weight:1100;line-height:1;margin:8px 0 0}
    .subTxt{font-size:20px;font-weight:950;letter-spacing:.7px;color:#6b7280}
    .rings{display:flex;gap:18px;margin-top:16px;flex-wrap:wrap}
    .ring{width:90px;height:90px;border-radius:999px;border:8px solid #e5e7eb;display:flex;align-items:center;justify-content:center;flex-direction:column}
    .ring strong{font-size:18px;font-weight:1100}
    .ring small{font-weight:900;color:#6b7280}
    .ring.blue{border-color:#3b82f6}
    .ring.gray{border-color:#d1d5db}
    .ring .count{margin-top:2px;font-weight:1100}
    .classFoot{position:absolute;right:16px;bottom:16px;font-size:64px;opacity:.12}
    .empty{color:#9ca3af;font-weight:1000}
    .badge{display:inline-block;padding:6px 10px;border:1px solid #e5e7eb;border-radius:999px;background:#fff;font-weight:950;color:#374151}
  </style>
</head>
<body>
<div class="wrap">

  <div class="top">
    <b style="font-size:16px;">🏫 Attendance — Classes</b>
    <span class="badge"><?= h($role) ?></span>

    <div class="actions">
      <a class="btn" href="attendance_sessions.php">📚 Sessions</a>
      <a class="btn blue" href="dashboard.php">🏠 Dashboard</a>
    </div>
  </div>

  <!-- YEAR SELECT -->
  <div class="card">
    <form method="get" action="attendance_admin.php">
      <div class="filters">
        <div class="field">
          <label>Academic Year</label>
          <select name="year_id" required>
            <option value="">Dooro Year</option>
            <?php foreach ($years as $y): ?>
              <option value="<?= (int)$y["year_id"] ?>" <?= ((int)$y["year_id"]===$year_id)?"selected":"" ?>>
                <?= h($y["year_name"]) ?><?= ((int)$y["is_current"]===1)?" (Current)":"" ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label>Quick Search Class</label>
          <input id="classSearch" type="text" placeholder="Raadi class: Grade - Section..." value="">
        </div>
      </div>

      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;">
        <button class="btn blue" type="submit">🔎 Load Classes</button>
      </div>

      <div class="hint">
        ✅ Halkan kaliya classes baa yaal. <b>Markaad card click-gareyso</b> → wuxuu kuu geynayaa <b>attendance_take.php</b>.
      </div>
    </form>
  </div>

  <!-- CLASS CARDS -->
  <?php if ($year_id <= 0): ?>
    <div class="card"><div class="empty">Fadlan dooro Academic Year si classes-ka u soo baxaan.</div></div>
  <?php else: ?>
    <div class="card">
      <b style="font-size:16px;">📌 Classes (Year: <?= h($selectedYearName ?: ("#".$year_id)) ?>)</b>
      <div class="hint">Click class card si aad u aado page-gooni ah oo attendance-ka lagu qaado.</div>

      <div class="gridCards" id="classGrid" style="margin-top:14px;">
        <?php foreach ($sectionCards as $c): ?>
          <?php
            $total = (int)$c["total_students"];
            $boys  = (int)$c["boys"];
            $girls = (int)$c["girls"];
            $na    = (int)$c["na_gender"];
            $pb = $total>0 ? (int)round(($boys/$total)*100) : 0;
            $pg = $total>0 ? (int)round(($girls/$total)*100) : 0;
            $pn = $total>0 ? (int)round(($na/$total)*100) : 0;
          ?>
          <div class="classCard"
               data-name="<?= h($c["class_name"]) ?>"
               onclick="goClass(<?= (int)$c['section_id'] ?>)">
            <div class="classTop">
              <div>
                <div class="className"><?= h($c["class_name"]) ?></div>
              </div>
              <div class="mini" title="Open attendance page">
                <span>➡️</span>
                <span style="color:#ef4444;">●</span>
              </div>
            </div>

            <div style="display:flex;gap:14px;align-items:flex-end;margin-top:6px;">
              <div class="bigNum"><?= $total ?></div>
              <div class="subTxt">STUDENTS</div>
            </div>

            <div class="rings">
              <div class="ring blue">
                <strong><?= $pb ?>%</strong>
                <small>Boys</small>
                <div class="count"><?= $boys ?></div>
              </div>
              <div class="ring gray">
                <strong><?= $pg ?>%</strong>
                <small>Girls</small>
                <div class="count"><?= $girls ?></div>
              </div>
              <div class="ring gray">
                <strong><?= $pn ?>%</strong>
                <small>N/A</small>
                <div class="count"><?= $na ?></div>
              </div>
            </div>

            <div class="classFoot">🎓</div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

</div>

<script>
  <?php if ($alert): ?>
  Swal.fire({
    icon: <?= json_encode($alert["type"]) ?>,
    title: <?= json_encode($alert["title"]) ?>,
    text: <?= json_encode($alert["text"]) ?>,
    confirmButtonText: "Haye",
    confirmButtonColor: "#3b82f6",
    width: 650
  });
  <?php endif; ?>

  // Filter class cards
  const classSearch = document.getElementById("classSearch");
  const classGrid = document.getElementById("classGrid");
  if (classSearch && classGrid) {
    classSearch.addEventListener("input", () => {
      const q = classSearch.value.trim().toLowerCase();
      classGrid.querySelectorAll(".classCard").forEach(card => {
        const name = (card.getAttribute("data-name") || "").toLowerCase();
        card.style.display = name.includes(q) ? "" : "none";
      });
    });
  }

  // ✅ Go to separate page (attendance_take.php)
  function goClass(sectionId){
    const yearId = <?= (int)$year_id ?>;
    window.location.href = `attendance_take.php?year_id=${yearId}&section_id=${sectionId}`;
  }
</script>
</body>
</html>
