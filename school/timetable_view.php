<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . "/conncation.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!isset($_SESSION["user_id"])) { header("Location: login.php"); exit; }
if (!isset($_SESSION["role"]) || !in_array($_SESSION["role"], ["ADMIN","RECEPTION","TEACHER","STUDENT"], true)) {
  header("Location: login.php"); exit;
}

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8"); }

/* current year */
$currentYear = null;
$rs = $conn->query("SELECT year_id, year_name FROM academic_years WHERE is_current=1 LIMIT 1");
$currentYear = $rs->fetch_assoc();

$year_id    = (int)($_GET["year_id"] ?? ($currentYear["year_id"] ?? 0));
$section_id = (int)($_GET["section_id"] ?? 0);

/* years */
$years = [];
$rs = $conn->query("SELECT year_id, year_name, is_current FROM academic_years ORDER BY start_date DESC");
while ($r = $rs->fetch_assoc()) $years[] = $r;

/* sections */
$sections = [];
$rs = $conn->query("
  SELECT s.section_id, CONCAT(g.grade_name,' - ', s.section_name) AS display_name
  FROM sections s
  JOIN grades g ON g.grade_id = s.grade_id
  ORDER BY g.sort_order ASC, s.section_name ASC
");
while ($r = $rs->fetch_assoc()) $sections[] = $r;

/* selected labels (for print header) */
$yearLabel = "";
$sectionLabel = "";
foreach ($years as $y) if ((int)$y["year_id"] === $year_id) $yearLabel = (string)$y["year_name"];
foreach ($sections as $s) if ((int)$s["section_id"] === $section_id) $sectionLabel = (string)$s["display_name"];

/* days / slots */
$days = [];
$rs = $conn->query("SELECT day_id, day_name FROM week_days ORDER BY sort_order ASC");
while ($r = $rs->fetch_assoc()) $days[] = $r;

$slots = [];
$rs = $conn->query("SELECT slot_id, slot_no, start_time, end_time, is_break FROM time_slots ORDER BY slot_no ASC, start_time ASC");
while ($r = $rs->fetch_assoc()) $slots[] = $r;

/* timetable id */
$timetable_id = 0;
if ($year_id > 0 && $section_id > 0) {
  $st = $conn->prepare("SELECT timetable_id FROM timetables WHERE year_id=? AND section_id=? LIMIT 1");
  $st->bind_param("ii", $year_id, $section_id);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();
  if ($row) $timetable_id = (int)$row["timetable_id"];
}

/* grid */
$grid = [];
if ($timetable_id > 0) {
  $st = $conn->prepare("
    SELECT
      te.day_id, te.slot_id,
      sub.subject_name,
      emp.full_name AS teacher_name,
      r.room_name
    FROM timetable_entries te
    JOIN subjects sub ON sub.subject_id = te.subject_id
    JOIN teachers t ON t.teacher_id = te.teacher_id
    JOIN employees emp ON emp.employee_id = t.employee_id
    LEFT JOIN rooms r ON r.room_id = te.room_id
    WHERE te.timetable_id = ?
  ");
  $st->bind_param("i", $timetable_id);
  $st->execute();
  $res = $st->get_result();
  while ($row = $res->fetch_assoc()) {
    $grid[(int)$row["day_id"]][(int)$row["slot_id"]] = $row;
  }
  $st->close();
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Timetable View</title>
  <style>
    :root{--bg:#f6f7fb;--card:#fff;--ink:#0f172a;--muted:#6b7280;--primary:#4f46e5;--stroke:#e9ecff;--shadow:0 18px 44px rgba(15,23,42,.10);}
    *{box-sizing:border-box}
    body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;background:var(--bg);color:var(--ink)}
    .wrap{max-width:1200px;margin:0 auto;padding:18px}
    .top{background:var(--card);border:1px solid #eef0ff;border-radius:16px;padding:14px 16px;display:flex;align-items:center;gap:12px;box-shadow:0 2px 10px rgba(0,0,0,.04)}
    .actions{margin-left:auto;display:flex;gap:10px;flex-wrap:wrap}
    .btn{text-decoration:none;border:1px solid #e5e7eb;background:#fff;padding:10px 14px;border-radius:12px;font-weight:900;cursor:pointer;display:inline-flex;align-items:center;gap:10px;color:#111827}
    .btn.blue{border-color:rgba(79,70,229,.25);background:rgba(79,70,229,.06);color:var(--primary)}
    .card{margin-top:14px;background:var(--card);border:1px solid #eef0ff;border-radius:18px;box-shadow:var(--shadow);padding:16px}
    .filters{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    @media (max-width:800px){.filters{grid-template-columns:1fr}}
    .field{border:2px solid rgba(79,70,229,.28);border-radius:999px;padding:12px 14px;background:#fff}
    .field label{display:block;font-size:12px;color:var(--muted);font-weight:900;margin-bottom:6px}
    select,input{width:100%;border:none;outline:none;background:transparent;font-size:16px}

    .printHeader{
      display:none;
      padding:10px 0 12px;
      border-bottom:2px solid #e5e7eb;
      margin-bottom:10px;
    }
    .printHeader h1{margin:0;font-size:18px}
    .printHeader .meta{margin-top:6px;color:#374151;font-weight:900;font-size:12px}

    .tableWrap{overflow:auto;margin-top:14px;border:1px solid var(--stroke);border-radius:16px}
    table{width:100%;border-collapse:separate;border-spacing:0;background:#fff}
    th,td{padding:12px;border-bottom:1px solid var(--stroke);border-right:1px solid var(--stroke);vertical-align:top}
    th{background:#f2f4ff;font-size:12px;text-transform:uppercase;letter-spacing:.7px;color:#374151}
    th:first-child,td:first-child{position:sticky;left:0;background:#f9faff;z-index:2}
    tr:last-child td{border-bottom:none}
    td:last-child,th:last-child{border-right:none}

    .slotMeta{font-size:12px;color:var(--muted);font-weight:900}
    .cell{min-height:70px;border-radius:14px;padding:10px;border:1px solid rgba(79,70,229,.12)}
    .sub{font-weight:950}
    .teach{color:var(--muted);font-weight:800;font-size:13px;margin-top:4px}
    .room{color:#0ea5e9;font-weight:900;font-size:12px;margin-top:2px}
    .empty{color:#9ca3af;font-weight:900}
    .break{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;font-weight:950;text-align:center;padding:14px;border-radius:14px}

    @media print{
      .top,.card.filtersCard{display:none !important;}
      body{background:#fff}
      .card{box-shadow:none;border:none;padding:0}
      .tableWrap{border:none}
      th:first-child,td:first-child{position:static}
      .printHeader{display:block}
      .cell{border:1px solid #e5e7eb}
      th{background:#f3f4f6 !important}
      .break{border:1px solid #f59e0b}
    }
  </style>
</head>
<body>
<div class="wrap">

  <div class="top">
    <b>👁 Timetable View</b>
    <div class="actions">
      <?php if (($_SESSION["role"] ?? "") === "ADMIN"): ?>
        <a class="btn blue" href="timetable.php?year_id=<?= $year_id ?>&section_id=<?= $section_id ?>">✏️ Edit</a>
      <?php endif; ?>
      <button class="btn" onclick="window.print()">🖨 Print</button>
    </div>
  </div>

  <div class="card filtersCard">
    <form method="get" action="timetable_view.php">
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
          <label>Class (Section)</label>
          <input id="classSearch" type="text" placeholder="Raadi class..." style="font-size:13px;margin:0 0 8px;color:#6b7280;font-weight:800;">
          <select name="section_id" id="sectionSelect" required>
            <option value="">Dooro Class</option>
            <?php foreach ($sections as $s): ?>
              <option value="<?= (int)$s["section_id"] ?>" <?= ((int)$s["section_id"]===$section_id)?"selected":"" ?>>
                <?= h($s["display_name"]) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;">
        <button class="btn blue" type="submit">🔎 Show</button>
        <a class="btn" href="timetable_view.php">Reset</a>
      </div>
    </form>
  </div>

  <div class="card">
    <?php if ($year_id<=0 || $section_id<=0): ?>
      <div class="empty">Fadlan dooro Year iyo Class si timetable-ka loo arko.</div>
    <?php elseif ($timetable_id<=0): ?>
      <div class="empty">Timetable weli lama samayn class-kan. Haddii Admin tahay, tag <b>timetable.php</b>.</div>
    <?php else: ?>

      <div class="printHeader">
        <h1>School Timetable</h1>
        <div class="meta">
          Academic Year: <?= h($yearLabel ?: (string)$year_id) ?>  •
          Class: <?= h($sectionLabel ?: (string)$section_id) ?>
        </div>
      </div>

      <b style="font-size:16px;">📌 Timetable</b>

      <div class="tableWrap">
        <table>
          <thead>
            <tr>
              <th style="width:220px;">Time Slots</th>
              <?php foreach ($days as $d): ?>
                <th><?= h($d["day_name"]) ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($slots as $sl): ?>
              <?php
                $isBreak = ((int)$sl["is_break"]===1);
                $slotLabel = "Slot ".$sl["slot_no"];
                $timeLabel = substr((string)$sl["start_time"],0,5)." - ".substr((string)$sl["end_time"],0,5);
              ?>
              <tr>
                <td>
                  <div style="font-weight:950;"><?= h($slotLabel) ?></div>
                  <div class="slotMeta"><?= h($timeLabel) ?><?= $isBreak?" • BREAK":"" ?></div>
                </td>

                <?php foreach ($days as $d): ?>
                  <?php $entry = $grid[(int)$d["day_id"]][(int)$sl["slot_id"]] ?? null; ?>
                  <td>
                    <?php if ($isBreak): ?>
                      <div class="break">BREAK</div>
                    <?php else: ?>
                      <div class="cell">
                        <?php if ($entry): ?>
                          <div class="sub"><?= h($entry["subject_name"]) ?></div>
                          <div class="teach">👨‍🏫 <?= h($entry["teacher_name"]) ?></div>
                          <?php if (!empty($entry["room_name"])): ?>
                            <div class="room">🏫 <?= h($entry["room_name"]) ?></div>
                          <?php endif; ?>
                        <?php else: ?>
                          <div class="empty">—</div>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                  </td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    <?php endif; ?>
  </div>

</div>

<script>
  // class filter
  const classSearch = document.getElementById("classSearch");
  const sectionSelect = document.getElementById("sectionSelect");
  if (classSearch && sectionSelect) {
    const original = Array.from(sectionSelect.options).map(o => ({value:o.value, text:o.text, selected:o.selected}));
    classSearch.addEventListener("input", () => {
      const q = classSearch.value.trim().toLowerCase();
      sectionSelect.innerHTML = "";
      original.forEach(opt => {
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
