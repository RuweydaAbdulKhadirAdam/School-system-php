<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . "/conncation.php";

/* =========================================================
   activity_logs.php  (FULL AUDIT LOG VIEWER)
   - Compatible with your DB (activity_logs table)
   - Shows: who (username/full_name), role, action, entity, entity_id,
            page/module, ip, user agent, time, details (old/new)
   - Search + filters + pagination
   - View details modal
   - Export CSV
   ========================================================= */

if (!isset($_SESSION["user_id"])) { header("Location: login.php"); exit; }

// Admin-only recommended (change if you want reception to access too)
$allowedRoles = ["ADMIN"];
if (!isset($_SESSION["role"]) || !in_array($_SESSION["role"], $allowedRoles, true)) {
  header("Location: login.php?msg=unauthorized");
  exit;
}

/* =========================
   HELPERS
   ========================= */
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8"); }

function parseDetails(?string $details): array {
  if (!$details) return [];
  $details = trim($details);
  // JSON details preferred
  if ($details !== "" && ($details[0] === "{" || $details[0] === "[")) {
    $json = json_decode($details, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($json)) return $json;
  }
  // fallback plain text
  return ["raw" => $details];
}

function buildQueryString(array $keep, array $override = []): string {
  $q = array_merge($keep, $override);
  foreach ($q as $k => $v) {
    if ($v === null || $v === "" || $v === "all") unset($q[$k]);
  }
  return http_build_query($q);
}

/* =========================
   READ FILTERS (GET)
   ========================= */
$search     = trim((string)($_GET["q"] ?? ""));
$action     = trim((string)($_GET["action"] ?? "all"));
$entity     = trim((string)($_GET["entity"] ?? "all"));
$user       = trim((string)($_GET["user"] ?? "all")); // user_id
$dateFrom   = trim((string)($_GET["from"] ?? ""));
$dateTo     = trim((string)($_GET["to"] ?? ""));
$pageNo     = max(1, (int)($_GET["page"] ?? 1));
$perPage    = 20;

$offset = ($pageNo - 1) * $perPage;

/* =========================
   BUILD WHERE
   ========================= */
$where = [];
$params = [];
$types  = "";

if ($search !== "") {
  // search in action/entity/details/username/fullname
  $where[] = "(al.action LIKE ? OR al.entity LIKE ? OR al.details LIKE ? OR u.username LIKE ? OR e.full_name LIKE ?)";
  $like = "%{$search}%";
  $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
  $types .= "sssss";
}

if ($action !== "" && $action !== "all") {
  $where[] = "al.action = ?";
  $params[] = $action;
  $types .= "s";
}
if ($entity !== "" && $entity !== "all") {
  $where[] = "al.entity = ?";
  $params[] = $entity;
  $types .= "s";
}
if ($user !== "" && $user !== "all") {
  $where[] = "al.user_id = ?";
  $params[] = (int)$user;
  $types .= "i";
}

// date filters (created_at is TIMESTAMP)
if ($dateFrom !== "") {
  $where[] = "al.created_at >= ?";
  $params[] = $dateFrom . " 00:00:00";
  $types .= "s";
}
if ($dateTo !== "") {
  $where[] = "al.created_at <= ?";
  $params[] = $dateTo . " 23:59:59";
  $types .= "s";
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

/* =========================
   EXPORT CSV
   ========================= */
if (isset($_GET["export"]) && $_GET["export"] === "csv") {
  $sql = "
    SELECT 
      al.log_id, al.created_at, al.user_id,
      COALESCE(e.full_name, u.username, 'Unknown') AS actor_name,
      u.username,
      al.action, al.entity, al.entity_id, al.details
    FROM activity_logs al
    LEFT JOIN users u ON u.user_id = al.user_id
    LEFT JOIN employees e ON e.user_id = u.user_id
    $whereSql
    ORDER BY al.created_at DESC
    LIMIT 5000
  ";
  $stmt = $conn->prepare($sql);
  if ($types !== "") $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $res = $stmt->get_result();

  header("Content-Type: text/csv; charset=utf-8");
  header("Content-Disposition: attachment; filename=activity_logs.csv");

  $out = fopen("php://output", "w");
  fputcsv($out, ["log_id","created_at","user_id","actor_name","username","action","entity","entity_id","details"]);
  while ($r = $res->fetch_assoc()) {
    fputcsv($out, $r);
  }
  fclose($out);
  exit;
}

/* =========================
   LOAD FILTER DROPDOWNS
   ========================= */
$actions = [];
$entities = [];
$users = [];

$r = $conn->query("SELECT DISTINCT action FROM activity_logs ORDER BY action ASC");
while ($row = $r->fetch_assoc()) $actions[] = $row["action"];

$r = $conn->query("SELECT DISTINCT entity FROM activity_logs WHERE entity IS NOT NULL AND entity <> '' ORDER BY entity ASC");
while ($row = $r->fetch_assoc()) $entities[] = $row["entity"];

// list users who have logs
$sqlUsers = "
  SELECT DISTINCT al.user_id,
         COALESCE(e.full_name, u.username, CONCAT('User#',al.user_id)) AS label,
         u.username
  FROM activity_logs al
  LEFT JOIN users u ON u.user_id = al.user_id
  LEFT JOIN employees e ON e.user_id = u.user_id
  WHERE al.user_id IS NOT NULL
  ORDER BY label ASC
  LIMIT 500
";
$r = $conn->query($sqlUsers);
while ($row = $r->fetch_assoc()) $users[] = $row;

/* =========================
   COUNT FOR PAGINATION
   ========================= */
$sqlCount = "
  SELECT COUNT(*) AS total
  FROM activity_logs al
  LEFT JOIN users u ON u.user_id = al.user_id
  LEFT JOIN employees e ON e.user_id = u.user_id
  $whereSql
";
$stmt = $conn->prepare($sqlCount);
if ($types !== "") $stmt->bind_param($types, ...$params);
$stmt->execute();
$total = (int)($stmt->get_result()->fetch_assoc()["total"] ?? 0);

$totalPages = max(1, (int)ceil($total / $perPage));

/* =========================
   LOAD PAGE DATA
   ========================= */
$sql = "
  SELECT 
    al.log_id, al.user_id, al.action, al.entity, al.entity_id, al.details, al.created_at,
    u.username,
    COALESCE(e.full_name, u.username, 'Unknown') AS actor_name
  FROM activity_logs al
  LEFT JOIN users u ON u.user_id = al.user_id
  LEFT JOIN employees e ON e.user_id = u.user_id
  $whereSql
  ORDER BY al.created_at DESC
  LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($sql);

if ($types !== "") {
  $types2 = $types . "ii";
  $params2 = array_merge($params, [$perPage, $offset]);
  $stmt->bind_param($types2, ...$params2);
} else {
  $stmt->bind_param("ii", $perPage, $offset);
}
$stmt->execute();
$res = $stmt->get_result();

$keep = [
  "q" => $search,
  "action" => $action,
  "entity" => $entity,
  "user" => $user,
  "from" => $dateFrom,
  "to" => $dateTo
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Activity Logs</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    body { background:#f6f7fb; }
    .card { border:0; box-shadow:0 10px 25px rgba(0,0,0,.06); border-radius:14px; }
    .badge-soft { background:#eef2ff; color:#3730a3; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    .table td { vertical-align: middle; }
    .small-muted { color:#6b7280; font-size:.88rem; }
  </style>
</head>
<body>

<div class="container py-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h3 class="mb-0">Activity Logs</h3>
      <div class="small-muted">Audit trail — who did what, where, and when</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary"
         href="?<?= h(buildQueryString($keep, ["export"=>"csv"])) ?>">
        Export CSV
      </a>
      
    </div>
  </div>

  <div class="card p-3 mb-3">
    <form class="row g-2" method="get" action="">
      <div class="col-md-3">
        <input type="text" name="q" value="<?= h($search) ?>" class="form-control" placeholder="Search action/entity/user/details...">
      </div>

      <div class="col-md-2">
        <select name="action" class="form-select">
          <option value="all">All Actions</option>
          <?php foreach ($actions as $a): ?>
            <option value="<?= h($a) ?>" <?= ($action===$a?'selected':'') ?>><?= h($a) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-2">
        <select name="entity" class="form-select">
          <option value="all">All Entities</option>
          <?php foreach ($entities as $en): ?>
            <option value="<?= h($en) ?>" <?= ($entity===$en?'selected':'') ?>><?= h($en) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-2">
        <select name="user" class="form-select">
          <option value="all">All Users</option>
          <?php foreach ($users as $u): ?>
            <option value="<?= h((string)$u["user_id"]) ?>" <?= ((string)$user === (string)$u["user_id"] ? 'selected':'') ?>>
              <?= h($u["label"]) ?><?= $u["username"] ? " (" . h($u["username"]) . ")" : "" ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-1">
        <input type="date" name="from" value="<?= h($dateFrom) ?>" class="form-control" title="From">
      </div>
      <div class="col-md-1">
        <input type="date" name="to" value="<?= h($dateTo) ?>" class="form-control" title="To">
      </div>

      <div class="col-md-1 d-grid">
        <button class="btn btn-primary">Filter</button>
      </div>
    </form>
  </div>

  <div class="card p-3">
    <div class="d-flex align-items-center justify-content-between mb-2">
      <div class="small-muted">
        Total: <b><?= (int)$total ?></b> logs
      </div>
      <div class="small-muted">
        Page <?= (int)$pageNo ?> / <?= (int)$totalPages ?>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>When</th>
            <th>User</th>
            <th>Action</th>
            <th>Entity</th>
            <th>Entity ID</th>
            <th>Where / IP</th>
            <th>Details</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($res->num_rows === 0): ?>
          <tr><td colspan="8" class="text-center py-4 small-muted">No logs found.</td></tr>
        <?php endif; ?>

        <?php while ($row = $res->fetch_assoc()): 
          $detailsArr = parseDetails($row["details"] ?? "");
          $pageName = $detailsArr["page"] ?? ($detailsArr["module"] ?? "");
          $ip = $detailsArr["ip"] ?? "";
          $role = $detailsArr["role"] ?? "";
          $ua = $detailsArr["ua"] ?? ($detailsArr["user_agent"] ?? "");
          $summary = $detailsArr["summary"] ?? ($detailsArr["message"] ?? ($detailsArr["raw"] ?? ""));
          $summaryShort = mb_strimwidth((string)$summary, 0, 80, "…", "UTF-8");

          // json for modal
          $jsonForModal = json_encode($detailsArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        ?>
          <tr>
            <td class="mono"><?= (int)$row["log_id"] ?></td>
            <td>
              <div><?= h($row["created_at"]) ?></div>
              <?php if ($role): ?><div class="small-muted">Role: <?= h($role) ?></div><?php endif; ?>
            </td>
            <td>
              <div><b><?= h($row["actor_name"] ?: "Unknown") ?></b></div>
              <div class="small-muted"><?= $row["username"] ? "@".h($row["username"]) : "UserID: ".h((string)$row["user_id"]) ?></div>
            </td>
            <td><span class="badge badge-soft"><?= h($row["action"]) ?></span></td>
            <td><?= h($row["entity"] ?? "") ?></td>
            <td class="mono"><?= h((string)($row["entity_id"] ?? "")) ?></td>
            <td>
              <div class="small-muted"><?= $pageName ? "Page: ".h($pageName) : "—" ?></div>
              <div class="small-muted"><?= $ip ? "IP: ".h($ip) : "—" ?></div>
            </td>
            <td>
              <div><?= h($summaryShort ?: "—") ?></div>
              <button type="button"
                      class="btn btn-sm btn-outline-primary mt-1"
                      onclick='showLogDetails(<?= (int)$row["log_id"] ?>, <?= json_encode($row["action"]) ?>, <?= json_encode($row["entity"]) ?>, <?= json_encode($row["entity_id"]) ?>, <?= json_encode($row["created_at"]) ?>, <?= json_encode($row["actor_name"]) ?>, <?= json_encode($row["username"]) ?>, <?= json_encode($jsonForModal) ?>)'>
                View
              </button>
            </td>
          </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <nav class="d-flex justify-content-between align-items-center">
      <div class="small-muted">
        Showing <?= ($total===0?0:($offset+1)) ?>–<?= min($offset+$perPage, $total) ?>
      </div>

      <ul class="pagination mb-0">
        <?php
          $prev = max(1, $pageNo - 1);
          $next = min($totalPages, $pageNo + 1);

          $basePrev = buildQueryString($keep, ["page"=>$prev]);
          $baseNext = buildQueryString($keep, ["page"=>$next]);
        ?>
        <li class="page-item <?= ($pageNo<=1?'disabled':'') ?>">
          <a class="page-link" href="?<?= h($basePrev) ?>">Prev</a>
        </li>

        <?php
          // show compact page numbers
          $start = max(1, $pageNo - 2);
          $end   = min($totalPages, $pageNo + 2);
          for ($p=$start; $p<=$end; $p++):
            $qs = buildQueryString($keep, ["page"=>$p]);
        ?>
          <li class="page-item <?= ($p===$pageNo?'active':'') ?>">
            <a class="page-link" href="?<?= h($qs) ?>"><?= (int)$p ?></a>
          </li>
        <?php endfor; ?>

        <li class="page-item <?= ($pageNo>=$totalPages?'disabled':'') ?>">
          <a class="page-link" href="?<?= h($baseNext) ?>">Next</a>
        </li>
      </ul>
    </nav>
  </div>
</div>

<script>
function safeJsonParse(str){
  try { return JSON.parse(str); } catch(e){ return {raw: str}; }
}

function pretty(obj){
  try { return JSON.stringify(obj, null, 2); } catch(e){ return String(obj); }
}

function showLogDetails(logId, action, entity, entityId, createdAt, actorName, username, detailsJsonString) {
  const detailsObj = safeJsonParse(detailsJsonString);

  const page = detailsObj.page || detailsObj.module || "—";
  const ip = detailsObj.ip || "—";
  const role = detailsObj.role || "—";
  const ua = detailsObj.ua || detailsObj.user_agent || "—";

  let html = `
    <div style="text-align:left">
      <div><b>Log ID:</b> <span class="mono">${logId}</span></div>
      <div><b>When:</b> ${createdAt}</div>
      <div><b>User:</b> ${actorName} ${username ? "(" + username + ")" : ""}</div>
      <div><b>Role:</b> ${role}</div>
      <hr/>
      <div><b>Action:</b> ${action}</div>
      <div><b>Entity:</b> ${entity || "—"}</div>
      <div><b>Entity ID:</b> ${entityId || "—"}</div>
      <div><b>Where (Page):</b> ${page}</div>
      <div><b>IP:</b> ${ip}</div>
      <div><b>User Agent:</b> <div class="small text-muted" style="word-break:break-word">${ua}</div></div>
      <hr/>
      <div><b>Details:</b></div>
      <pre class="mono" style="background:#0b1020;color:#d1d5db;padding:12px;border-radius:10px;max-height:320px;overflow:auto">${pretty(detailsObj)}</pre>
    </div>
  `;

  Swal.fire({
    title: "Log Details",
    html: html,
    width: 900,
    confirmButtonText: "Close"
  });
}
</script>

</body>
</html>
