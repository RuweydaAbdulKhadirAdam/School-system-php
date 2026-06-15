<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . "/conncation.php";

/* =========================
   GUARD (ADMIN only)
   ========================= */
if (!isset($_SESSION["user_id"]) || ($_SESSION["role"] ?? "") !== "ADMIN") {
  header("Location: login.php");
  exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* Helpers */
if (!function_exists("h")) {
  function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8"); }
}

/* =========================
   FLASH MSG via query param
   ========================= */
$msg  = trim((string)($_GET["msg"] ?? ""));
$type = trim((string)($_GET["type"] ?? "success")); // success | error | info

/* =========================
   ADD ROLE TO USER
   ========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "add_role") {
  $userId = (int)($_POST["user_id"] ?? 0);
  $roleId = (int)($_POST["role_id"] ?? 0);

  if ($userId <= 0 || $roleId <= 0) {
    header("Location: users_roles.php?type=error&msg=" . urlencode("User ama Role ma saxna."));
    exit;
  }
  // Replace behavior: if user already has role(s), delete them and insert the new one.
  $conn->begin_transaction();
  try {
    // check existing roles
    $stmt = $conn->prepare("SELECT role_id FROM user_roles WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $existing = [];
    while ($r = $res->fetch_assoc()) $existing[] = (int)$r['role_id'];
    $stmt->close();

    if (!empty($existing)) {
      // delete existing roles
      $stmt = $conn->prepare("DELETE FROM user_roles WHERE user_id = ?");
      $stmt->bind_param("i", $userId);
      $stmt->execute();
      $stmt->close();

      // insert new role
      $stmt = $conn->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
      $stmt->bind_param("ii", $userId, $roleId);
      $stmt->execute();
      $stmt->close();

      $conn->commit();
      header("Location: users_roles.php?type=success&msg=" . urlencode("Role-ka hore waa la beddelay."));
      exit;
    }

    // no existing role -> just insert
    $stmt = $conn->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $userId, $roleId);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    header("Location: users_roles.php?type=success&msg=" . urlencode("Role ayaa lagu daray user-ka."));
    exit;

  } catch (Throwable $e) {
    $conn->rollback();
    header("Location: users_roles.php?type=error&msg=" . urlencode("Khalad: " . $e->getMessage()));
    exit;
  }

  header("Location: users_roles.php?type=success&msg=" . urlencode("Role ayaa lagu daray user-ka."));
  exit;
}

/* =========================
   REMOVE ROLE FROM USER
   ========================= */
if (isset($_GET["remove"]) && $_GET["remove"] === "1") {
  $userId = (int)($_GET["user_id"] ?? 0);
  $roleId = (int)($_GET["role_id"] ?? 0);

  if ($userId <= 0 || $roleId <= 0) {
    header("Location: users_roles.php?type=error&msg=" . urlencode("User ama Role ma saxna."));
    exit;
  }

  $stmt = $conn->prepare("DELETE FROM user_roles WHERE user_id=? AND role_id=?");
  $stmt->bind_param("ii", $userId, $roleId);
  $stmt->execute();
  $stmt->close();

  header("Location: users_roles.php?type=success&msg=" . urlencode("Role ayaa laga saaray user-ka."));
  exit;
}

/* =========================
   GET ROLES
   ========================= */
$roles = [];
$r = $conn->query("SELECT role_id, role_name FROM roles ORDER BY role_name ASC");
while ($row = $r->fetch_assoc()) $roles[] = $row;

/* =========================
   GET USERS
   ========================= */
$users = [];
$res = $conn->query("SELECT user_id, username, phone, email, is_active, created_at FROM users ORDER BY user_id DESC");
while ($row = $res->fetch_assoc()) $users[] = $row;

/* =========================
   USER ROLES MAP
   ========================= */
$userRoles = [];
$res = $conn->query("
  SELECT ur.user_id, r.role_id, r.role_name
  FROM user_roles ur
  JOIN roles r ON r.role_id = ur.role_id
  ORDER BY r.role_name ASC
");
while ($row = $res->fetch_assoc()) {
  $uid = (int)$row["user_id"];
  if (!isset($userRoles[$uid])) $userRoles[$uid] = [];
  $userRoles[$uid][] = $row;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Users & Roles</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- SweetAlert2 -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <style>
    :root{
      --bg:#0b1220;
      --card:#0f1b33;
      --card2:#0c162b;
      --text:#e9eefc;
      --muted:#a7b3d1;
      --line:rgba(255,255,255,.10);
      --primary:#4f7cff;
      --danger:#ff4d6d;
      --success:#2dd4bf;
      --shadow: 0 12px 35px rgba(0,0,0,.35);
    }
    *{box-sizing:border-box}
    body{
      margin:0;
      font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial;
      background: radial-gradient(1200px 600px at 20% -20%, rgba(79,124,255,.35), transparent 60%),
                  radial-gradient(900px 500px at 110% 10%, rgba(45,212,191,.25), transparent 55%),
                  var(--bg);
      color:var(--text);
      padding:24px;
    }
    .wrap{max-width:1200px; margin:0 auto;}
    .topbar{
      display:flex; flex-wrap:wrap; gap:12px;
      align-items:center; justify-content:space-between;
      margin-bottom:16px;
    }
    .title{
      display:flex; flex-direction:column; gap:2px;
    }
    .title h1{margin:0; font-size:22px; letter-spacing:.2px;}
    .title p{margin:0; color:var(--muted); font-size:13px;}
    .search{
      display:flex; gap:10px; align-items:center;
      background: rgba(255,255,255,.06);
      border:1px solid var(--line);
      padding:10px 12px;
      border-radius:12px;
      min-width:320px;
    }
    .search input{
      width:100%;
      background:transparent;
      border:0; outline:none;
      color:var(--text);
      font-size:14px;
    }
    .pill{
      padding:8px 10px; border-radius:10px;
      border:1px solid var(--line);
      background: rgba(255,255,255,.06);
      color:var(--muted);
      font-size:12px;
      white-space:nowrap;
    }
    .card{
      background: linear-gradient(180deg, rgba(255,255,255,.08), rgba(255,255,255,.04));
      border:1px solid var(--line);
      border-radius:16px;
      box-shadow: var(--shadow);
      overflow:hidden;
    }
    table{
      width:100%;
      border-collapse:collapse;
      min-width:1050px;
    }
    thead th{
      text-align:left;
      font-size:12px;
      letter-spacing:.3px;
      text-transform:uppercase;
      color:var(--muted);
      background: rgba(0,0,0,.25);
      border-bottom:1px solid var(--line);
      padding:14px 14px;
      position:sticky;
      top:0;
      z-index:2;
    }
    tbody td{
      padding:14px;
      border-bottom:1px solid var(--line);
      vertical-align:top;
      font-size:14px;
    }
    tbody tr:hover{background: rgba(255,255,255,.04);}
    .small{font-size:12px; color:var(--muted);}
    .badge{
      display:inline-flex; align-items:center; gap:8px;
      padding:7px 10px;
      border-radius:999px;
      background: rgba(79,124,255,.14);
      border:1px solid rgba(79,124,255,.28);
      color:var(--text);
      font-size:12px;
      margin:0 6px 6px 0;
    }
    .badge .x{
      display:inline-block;
      width:18px; height:18px; line-height:18px;
      text-align:center;
      border-radius:999px;
      background: rgba(255,77,109,.18);
      border:1px solid rgba(255,77,109,.35);
      color: var(--danger);
      font-weight:700;
      cursor:pointer;
      text-decoration:none;
    }
    .actions{
      display:flex; gap:8px; align-items:center; flex-wrap:wrap;
    }
    select, button{
      padding:10px 12px;
      border-radius:12px;
      border:1px solid var(--line);
      background: rgba(255,255,255,.06);
      color:var(--text);
      outline:none;
    }
    select option{background:#0b1220; color:var(--text);}
    button{
      background: linear-gradient(135deg, rgba(79,124,255,.95), rgba(79,124,255,.65));
      border-color: rgba(79,124,255,.45);
      cursor:pointer;
      font-weight:700;
    }
    button:hover{filter:brightness(1.05);}
    .status{
      display:inline-flex; align-items:center; gap:8px;
      padding:7px 10px;
      border-radius:999px;
      font-size:12px;
      border:1px solid var(--line);
      background: rgba(255,255,255,.06);
    }
    .dot{width:8px; height:8px; border-radius:999px; background: var(--muted);}
    .dot.on{background: var(--success);}
    .dot.off{background: var(--danger);}
    .table-wrap{overflow:auto;}
    .muted{color:var(--muted)}
  </style>
</head>

<body>
<div class="wrap">

  <div class="topbar">
    <div class="title">
      <h1>Users & Roles</h1>
      <p>Manage roles: ADMIN, TEACHER, RECEPTION, FINANCE, STUDENT</p>
    </div>

    <div class="search" title="Live search">
      <span class="muted">🔎</span>
      <input id="searchBox" type="text" placeholder="Search by username / email / phone..." />
      <span id="counter" class="pill">0</span>
    </div>
  </div>
  <?php
    // Main Content Area for users_roles
    require_once __DIR__ . '/content_blocks.php';
    $users_roles_main = cb_get($conn, 'users_roles_main');
  ?>
  <div class="card" style="margin-bottom:12px; padding:12px;">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px;">
      <div style="flex:1;"> <div id="block_users_roles_main"><?= $users_roles_main !== '' ? $users_roles_main : '<div class="small">Users & Roles main content. Click Edit to customize.</div>' ?></div></div>
      <!-- Edit button removed per request -->
    </div>
  </div>
    <div class="table-wrap">
      <table id="usersTable">
        <thead>
          <tr>
            <th style="width:70px;">ID</th>
            <th style="width:170px;">Username</th>
            <th style="width:220px;">Email</th>
            <th style="width:140px;">Phone</th>
            <th style="width:120px;">Active</th>
            <th>Roles</th>
            <th style="width:320px;">Update Role</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
          <?php $uid = (int)$u["user_id"]; ?>
          <tr class="row"
              data-search="<?= h(strtolower(($u["username"] ?? "")." ".($u["email"] ?? "")." ".($u["phone"] ?? "")." ".$uid)) ?>">
            <td><?= $uid ?></td>
            <td>
              <div style="font-weight:800;"><?= h($u["username"] ?? "") ?></div>
              <div class="small">Created: <?= h((string)$u["created_at"]) ?></div>
            </td>
            <td><?= h($u["email"] ?? "") ?: "<span class='small'>—</span>" ?></td>
            <td><?= h($u["phone"] ?? "") ?: "<span class='small'>—</span>" ?></td>
            <td>
              <?php $active = ((int)$u["is_active"] === 1); ?>
              <span class="status">
                <span class="dot <?= $active ? "on" : "off" ?>"></span>
                <?= $active ? "YES" : "NO" ?>
              </span>
            </td>

            <td>
              <?php if (!empty($userRoles[$uid])): ?>
                <?php foreach ($userRoles[$uid] as $rr): ?>
                  <span class="badge">
                    <?= h($rr["role_name"]) ?>
                    <a class="x"
                       href="users_roles.php?remove=1&user_id=<?= $uid ?>&role_id=<?= (int)$rr["role_id"] ?>"
                       data-remove="1"
                       data-username="<?= h($u["username"] ?? "") ?>"
                       data-role="<?= h($rr["role_name"]) ?>"
                       title="Remove role">×</a>
                  </span>
                <?php endforeach; ?>
              <?php else: ?>
                <span class="small">No roles</span>
              <?php endif; ?>
            </td>

            <td>
                <?php $hasRole = !empty($userRoles[$uid]); ?>
                <?php
                  $currentRoleId = 0;
                  $currentRoleName = '';
                  if ($hasRole) {
                    $currentRoleId = (int)$userRoles[$uid][0]['role_id'];
                    $currentRoleName = (string)$userRoles[$uid][0]['role_name'];
                  }
                ?>

                <form method="post" class="actions" data-has-role="<?= $hasRole ? '1' : '0' ?>" data-username="<?= h($u['username'] ?? '') ?>" data-current-role="<?= $currentRoleId ?>">
                  <input type="hidden" name="action" value="add_role">
                  <input type="hidden" name="user_id" value="<?= $uid ?>">
                  <select name="role_id" required>
                    <option value="">Select role</option>
                    <?php foreach ($roles as $r): ?>
                      <option value="<?= (int)$r["role_id"] ?>"><?= h($r["role_name"]) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit"><?= $hasRole ? "Bedel Role" : "Ku dar Role" ?></button>
                </form>

                <div class="actions" style="margin-top:6px; gap:6px;">
                  <button type="button" class="editRoleBtn" data-user="<?= $uid ?>" data-current-role="<?= $currentRoleId ?>">Tafatir</button>
                  <?php if ($hasRole): ?>
                    <button type="button" class="deleteRoleBtn" data-del-url="users_roles.php?remove=1&user_id=<?= $uid ?>&role_id=<?= $currentRoleId ?>" data-user="<?= $uid ?>" data-role="<?= h($currentRoleName) ?>">Ka saar</button>
                  <?php endif; ?>
                </div>

                <div class="small" style="margin-top:6px;">
                  <?= $hasRole ? "This will replace the existing role for this user." : "Tip: assign one role per user." ?>
                </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<script>
  // SweetAlert flash msg
  const msg  = <?= json_encode($msg) ?>;
  const type = <?= json_encode($type) ?>;

  if (msg) {
    Swal.fire({
      icon: (type === "error" ? "error" : (type === "info" ? "info" : "success")),
      title: msg,
      timer: 2200,
      showConfirmButton: false
    });
  }

  // Remove role confirm
  document.querySelectorAll('a[data-remove="1"]').forEach(a => {
    a.addEventListener('click', function(e){
      e.preventDefault();
      const url = this.getAttribute('href');
      const user = this.dataset.username || '';
      const role = this.dataset.role || '';
      Swal.fire({
        icon: "warning",
        title: "Remove role?",
        html: `<b>${user}</b> <br> Role: <b>${role}</b>`,
        showCancelButton: true,
        confirmButtonText: "Yes, remove",
        cancelButtonText: "Cancel"
      }).then((res)=>{
        if(res.isConfirmed) window.location.href = url;
      });
    });
  });

  // Live search filter
  const searchBox = document.getElementById('searchBox');
  const rows = Array.from(document.querySelectorAll('#usersTable tbody .row'));
  const counter = document.getElementById('counter');

  function updateCounter(n){
    counter.textContent = n;
  }

  function filterRows(){
    const q = (searchBox.value || "").toLowerCase().trim();
    let visible = 0;
    rows.forEach(r=>{
      const s = r.getAttribute('data-search') || "";
      const show = q === "" ? true : s.includes(q);
      r.style.display = show ? "" : "none";
      if (show) visible++;
    });
    updateCounter(visible);
  }

  searchBox.addEventListener('input', filterRows);
  filterRows(); // init
</script>

<script>
  // Confirm before replacing existing role
  document.querySelectorAll('form.actions').forEach(form => {
    form.addEventListener('submit', function(e){
      const has = this.dataset.hasRole === '1';
      if (!has) return; // no confirm needed
      e.preventDefault();
      const username = this.dataset.username || '';
      const sel = this.querySelector('select[name="role_id"]');
      const selectedText = sel ? (sel.options[sel.selectedIndex]?.text || '') : '';
      Swal.fire({
        icon: 'warning',
        title: 'Ma bedeli doonaa role-ka?',
        html: `<b>${username}</b><br>Role-ka hadda ayaa la bedeli doonaa oo noqonaya: <b>${selectedText}</b>`,
        showCancelButton: true,
        confirmButtonText: 'Haa, bedel',
        cancelButtonText: 'Jooji'
      }).then(res => {
        if (res.isConfirmed) {
          // allow the form to submit normally
          this.submit();
        }
      });
    });
  });
</script>

<script>
  // Edit block functionality removed (Edit buttons were removed).
</script>

<script>
  // Roles data for JS
  const rolesList = <?= json_encode($roles) ?>;

  // Edit Role button handler -> open modal select then submit
  document.querySelectorAll('.editRoleBtn').forEach(btn => {
    btn.addEventListener('click', function(){
      const userId = this.dataset.user;
      const currentRole = parseInt(this.dataset.currentRole || '0', 10);
      // build select
      let options = '<select id="swalRoleSelect" style="padding:8px; width:100%">';
      options += '<option value="">-- Select Role --</option>';
      for (const r of rolesList) {
        const sel = (r.role_id == currentRole) ? 'selected' : '';
        options += `<option value="${r.role_id}" ${sel}>${r.role_name}</option>`;
      }
      options += '</select>';

      Swal.fire({
        title: 'Edit role for user #' + userId,
        html: options,
        showCancelButton: true,
        confirmButtonText: 'Save',
        preConfirm: () => {
          const sel = document.getElementById('swalRoleSelect');
          return sel ? sel.value : '';
        }
      }).then(res => {
        if (res.isConfirmed) {
          const newRoleId = res.value;
            if (!newRoleId) {
            Swal.fire({icon:'error', title:'Fadlan dooro role.'});
            return;
          }
          // create a small form to submit
          const f = document.createElement('form');
          f.method = 'POST';
          f.action = 'users_roles.php';
          const a = document.createElement('input'); a.type='hidden'; a.name='action'; a.value='add_role'; f.appendChild(a);
          const u = document.createElement('input'); u.type='hidden'; u.name='user_id'; u.value=userId; f.appendChild(u);
          const r = document.createElement('input'); r.type='hidden'; r.name='role_id'; r.value=newRoleId; f.appendChild(r);
          document.body.appendChild(f);
          f.submit();
        }
      });
    });
  });

  // Delete Role button handler
  document.querySelectorAll('.deleteRoleBtn').forEach(btn => {
    btn.addEventListener('click', function(){
      const url = this.dataset.delUrl;
      const user = this.dataset.user || '';
      const role = this.dataset.role || '';
      Swal.fire({
        icon: 'warning',
        title: 'Ma ka saari doonaa role-ka?',
        html: `<b>${user}</b><br>Role: <b>${role}</b>`,
        showCancelButton: true,
        confirmButtonText: 'Haa, ka saar',
        cancelButtonText: 'Jooji'
      }).then(res => { if (res.isConfirmed) window.location.href = url; });
    });
  });
</script>

</body>
</html>
