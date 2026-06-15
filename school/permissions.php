<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . "/conncation.php";

// ADMIN guard
if (!isset($_SESSION["user_id"]) || ($_SESSION["role"] ?? "") !== "ADMIN") {
  header("Location: login.php");
  exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$msg = trim((string)($_GET['msg'] ?? ''));
$type = trim((string)($_GET['type'] ?? 'success'));

// Handle POST actions: add, delete, update role mapping
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'add_permission') {
    $key = trim($_POST['permission_key'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    if ($key === '') {
      header("Location: permissions.php?type=error&msg=" . urlencode('Permission key required'));
      exit;
    }
    // ensure unique
    $stmt = $conn->prepare("SELECT permission_id FROM permissions WHERE permission_key = ? LIMIT 1");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->fetch_assoc()) {
      $stmt->close();
      header("Location: permissions.php?type=error&msg=" . urlencode('Permission key already exists'));
      exit;
    }
    $stmt->close();

    $stmt = $conn->prepare("INSERT INTO permissions (permission_key, description) VALUES (?, ?)");
    $stmt->bind_param('ss', $key, $desc);
    $stmt->execute();
    $stmt->close();

    header("Location: permissions.php?type=success&msg=" . urlencode('Permission added'));
    exit;
  }

  if ($action === 'delete_permission') {
    $pid = (int)($_POST['permission_id'] ?? 0);
    if ($pid <= 0) {
      header("Location: permissions.php?type=error&msg=" . urlencode('Invalid permission'));
      exit;
    }
    // deleting permission will cascade role_permissions via FK
    $stmt = $conn->prepare("DELETE FROM permissions WHERE permission_id = ?");
    $stmt->bind_param('i', $pid);
    $stmt->execute();
    $stmt->close();

    header("Location: permissions.php?type=success&msg=" . urlencode('Permission deleted'));
    exit;
  }

  if ($action === 'update_perm_roles') {
    $pid = (int)($_POST['permission_id'] ?? 0);
    $roles = $_POST['roles'] ?? [];
    if ($pid <= 0) {
      header("Location: permissions.php?type=error&msg=" . urlencode('Invalid permission'));
      exit;
    }

    $conn->begin_transaction();
    try {
      $stmt = $conn->prepare("DELETE FROM role_permissions WHERE permission_id = ?");
      $stmt->bind_param('i', $pid);
      $stmt->execute();
      $stmt->close();

      if (is_array($roles) && count($roles) > 0) {
        $stmt = $conn->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
        foreach ($roles as $rid) {
          $ridi = (int)$rid;
          if ($ridi <= 0) continue;
          $stmt->bind_param('ii', $ridi, $pid);
          $stmt->execute();
        }
        $stmt->close();
      }

      $conn->commit();
      header("Location: permissions.php?type=success&msg=" . urlencode('Permission roles updated'));
      exit;
    } catch (Throwable $e) {
      $conn->rollback();
      header("Location: permissions.php?type=error&msg=" . urlencode('DB error: ' . $e->getMessage()));
      exit;
    }
  }

  if ($action === 'update_perm_users') {
    $pid = (int)($_POST['permission_id'] ?? 0);
    $users_selected = $_POST['users'] ?? [];
    if ($pid <= 0) {
      header("Location: permissions.php?type=error&msg=" . urlencode('Invalid permission'));
      exit;
    }

    $conn->begin_transaction();
    try {
      $stmt = $conn->prepare("DELETE FROM user_permissions WHERE permission_id = ?");
      $stmt->bind_param('i', $pid);
      $stmt->execute();
      $stmt->close();

      if (is_array($users_selected) && count($users_selected) > 0) {
        $stmt = $conn->prepare("INSERT INTO user_permissions (user_id, permission_id, granted_by) VALUES (?, ?, ?)");
        $granted_by = (int)($_SESSION['user_id'] ?? 0);
        foreach ($users_selected as $uid) {
          $u = (int)$uid;
          if ($u <= 0) continue;
          $stmt->bind_param('iii', $u, $pid, $granted_by);
          $stmt->execute();
        }
        $stmt->close();
      }

      $conn->commit();
      header("Location: permissions.php?type=success&msg=" . urlencode('Permission users updated'));
      exit;
    } catch (Throwable $e) {
      $conn->rollback();
      header("Location: permissions.php?type=error&msg=" . urlencode('DB error: ' . $e->getMessage()));
      exit;
    }
  }

  if ($action === 'update_user_permissions') {
    $uid = (int)($_POST['user_id'] ?? 0);
    $perms_selected = $_POST['permissions'] ?? [];
    if ($uid <= 0) {
      header("Location: permissions.php?type=error&msg=" . urlencode('Invalid user'));
      exit;
    }

    $conn->begin_transaction();
    try {
      $stmt = $conn->prepare("DELETE FROM user_permissions WHERE user_id = ?");
      $stmt->bind_param('i', $uid);
      $stmt->execute();
      $stmt->close();

      if (is_array($perms_selected) && count($perms_selected) > 0) {
        $stmt = $conn->prepare("INSERT INTO user_permissions (user_id, permission_id, granted_by) VALUES (?, ?, ?)");
        $granted_by = (int)($_SESSION['user_id'] ?? 0);
        foreach ($perms_selected as $pid) {
          $p = (int)$pid;
          if ($p <= 0) continue;
          $stmt->bind_param('iii', $uid, $p, $granted_by);
          $stmt->execute();
        }
        $stmt->close();
      }

      $conn->commit();
      header("Location: permissions.php?type=success&msg=" . urlencode('User permissions updated'));
      exit;
    } catch (Throwable $e) {
      $conn->rollback();
      header("Location: permissions.php?type=error&msg=" . urlencode('DB error: ' . $e->getMessage()));
      exit;
    }
  }
}

// Fetch data for UI
$roles = [];
$r = $conn->query("SELECT role_id, role_name FROM roles ORDER BY role_name");
if ($r) while ($row = $r->fetch_assoc()) $roles[] = $row;

$permissions = [];
$pq = $conn->query("SELECT permission_id, permission_key, description FROM permissions ORDER BY permission_key");
if ($pq) while ($row = $pq->fetch_assoc()) $permissions[] = $row;

// permission -> roles map
$permRoles = [];
$rpq = $conn->query("SELECT role_id, permission_id FROM role_permissions");
if ($rpq) while ($rrow = $rpq->fetch_assoc()) {
  $pid = (int)$rrow['permission_id'];
  $rid = (int)$rrow['role_id'];
  if (!isset($permRoles[$pid])) $permRoles[$pid] = [];
  $permRoles[$pid][] = $rid;
}

// permission -> users map (user_permissions)
$permUsers = [];
try {
  $upq = $conn->query("SELECT user_id, permission_id FROM user_permissions");
  if ($upq) while ($ur = $upq->fetch_assoc()) {
    $pid = (int)$ur['permission_id'];
    $uid = (int)$ur['user_id'];
    if (!isset($permUsers[$pid])) $permUsers[$pid] = [];
    $permUsers[$pid][] = $uid;
  }
} catch (Throwable $e) {
  // table may not exist yet; ignore
}

// build user -> permissions map for user-centric UI
$userPerms = [];
foreach ($permUsers as $pid => $uids) {
  foreach ($uids as $u) {
    if (!isset($userPerms[$u])) $userPerms[$u] = [];
    $userPerms[$u][] = $pid;
  }
}

// fetch users for assign modal
$users = [];
try {
  $uq = $conn->query("SELECT u.user_id, u.username, COALESCE(e.full_name, u.username) AS full_name, u.email FROM users u LEFT JOIN employees e ON e.user_id = u.user_id ORDER BY u.user_id DESC");
  if ($uq) while ($row = $uq->fetch_assoc()) $users[] = $row;
} catch (Throwable $e) { }

// common permission templates for UI help
$commonPermissions = [
  'dashboard.view' => 'View dashboard and charts',
  'students.view'  => 'View student list and profiles',
  'students.create'=> 'Add new students',
  'students.edit'  => 'Edit student records',
  'users.manage'   => 'Manage user accounts (create/delete)',
  'users.roles'    => 'Manage user roles and assignments',
  'marks.view'     => 'View marks and results',
  'marks.edit'     => 'Edit exam marks',
  'attendance.view'=> 'View attendance reports',
  'attendance.mark'=> 'Mark attendance for sessions',
  'finance.invoices.view' => 'View invoices',
  'finance.invoices.create' => 'Create invoices',
  'sms.send'       => 'Send SMS messages',
  'reports.view'   => 'View system reports',
  'settings.manage'=> 'Access system settings'
];

// If role selected, fetch users for that role (to show names)
$selectedRoleId = (int)($_GET['role_id'] ?? 0);
$roleUsers = [];
if ($selectedRoleId > 0) {
  $stmt = $conn->prepare(
    "SELECT u.user_id, u.username, COALESCE(e.full_name, u.username) AS full_name
     FROM user_roles ur
     JOIN users u ON u.user_id = ur.user_id
     LEFT JOIN employees e ON e.user_id = u.user_id
     WHERE ur.role_id = ?
     ORDER BY e.full_name ASC, u.username ASC"
  );
  $stmt->bind_param('i', $selectedRoleId);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($rw = $res->fetch_assoc()) $roleUsers[] = $rw;
  $stmt->close();
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Permissions - HIGH School</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="bootstrap.css">
  <style>
    :root{
      --bg:#f6f8fb;
      --card:#ffffff;
      --muted:#6b7280;
      --accent:#4f7cff;
      --danger:#ef4444;
      --border:#e6e9f0;
      --radius:12px;
      --pad:14px;
      --shadow: 0 8px 24px rgba(16,24,40,.06);
    }
    html,body{height:100%;}
    body{
      margin:0; font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
      background:var(--bg); color:#111; padding:20px;
      -webkit-font-smoothing:antialiased; -moz-osx-font-smoothing:grayscale;
    }
    .card{background:var(--card);padding:18px;border-radius:var(--radius);border:1px solid var(--border);box-shadow:var(--shadow);}
    h2{margin:0 0 6px 0; font-size:20px}
    .muted{color:var(--muted)}

    /* Grid layout: roles | main | quick */
    .perm-grid{display:grid;grid-template-columns:260px 1fr 300px;gap:18px;align-items:start}
    @media (max-width:1000px){ .perm-grid{grid-template-columns:1fr; } .right-col{order:3} }

    .roles-col{background:linear-gradient(180deg,#fbfdff,#f7fbff);border:1px solid var(--border);padding:12px;border-radius:10px}
    .roles-col a{display:flex;justify-content:space-between;padding:10px;border-radius:8px;text-decoration:none;color:#111;border:1px solid transparent}
    .roles-col a:hover{background:#eef6ff}
    .roles-col .roleCount{color:var(--muted);font-size:13px}

    .main-col{display:flex;flex-direction:column;gap:12px}
    .perm-hero{display:flex;gap:12px;align-items:flex-start}
    .perm-hero .block{flex:1}

    /* Add permission form */
    .add-form{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
    .add-form input, .add-form select{padding:10px 12px;border-radius:10px;border:1px solid var(--border);background:#fff;outline:none}
    .add-form .btn-primary{background:var(--accent);color:#fff;border:none;padding:10px 14px;border-radius:10px;cursor:pointer}

    /* Quick open column */
    .quick-col .btn{display:block;text-align:left;background:#fff;border:1px solid var(--border);padding:10px;border-radius:8px;margin-bottom:8px;color:#111;text-decoration:none}

    table{width:100%;border-collapse:collapse;border-radius:8px;overflow:hidden;background:#fff}
    /* Compact table styles */
    thead th{background:#fafafa;padding:8px 10px;text-align:left;color:var(--muted);font-size:12px;border-bottom:1px solid var(--border)}
    tbody td{padding:8px 10px;border-bottom:1px solid var(--border);vertical-align:middle;font-size:13px}
    tr.compact-row td{vertical-align:middle}
    .chip{display:inline-block;padding:5px 8px;border-radius:999px;background:#f1f5f9;border:1px solid var(--border);font-size:12px}
    .role-checkbox input{transform:scale(0.95); margin-right:6px}
    .perm-actions .btn-sm{padding:6px 8px;border-radius:8px;font-size:13px}
    .compact-search input{width:100%;padding:8px 10px;border-radius:8px;border:1px solid var(--border);}
    .perm-actions{display:flex;gap:8px;align-items:center}
    .chip{display:inline-block;padding:6px 10px;border-radius:999px;background:#f1f5f9;border:1px solid var(--border);font-size:13px}

    .tabBtn{padding:8px 12px;border-radius:8px;border:1px solid var(--border);background:#fff;cursor:pointer}
    .tabBtn.active{background:#eef6ff;border-color:rgba(79,124,255,.35);font-weight:700}
  </style>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>

<div class="card">
  <h2>Permissions</h2>
  <p class="muted">Manage permission keys and assign them to roles (role_permissions).</p>
  <?php if ($msg): ?>
    <script>Swal.fire({icon: <?= json_encode($type === 'error' ? 'error' : 'success') ?>, title: <?= json_encode($msg) ?>, timer:2000, showConfirmButton:false});</script>
  <?php endif; ?>
  <div style="margin-top:12px; margin-bottom:8px; display:flex; gap:12px; align-items:center;">
    <button id="btnShowAdd" class="tabBtn" type="button">Add Permission</button>
    <button id="btnShowList" class="tabBtn" type="button">Permissions List</button>
    <button id="btnShowByUser" class="tabBtn" type="button">By User</button>
  </div>

  <div id="addSection" style="margin-top:12px; margin-bottom:18px;">
    <div class="perm-grid">
      <div class="roles-col">
        <div style="font-weight:800; margin-bottom:10px;">Roles</div>
        <div style="display:flex; flex-direction:column; gap:8px;">
          <?php foreach ($roles as $r): $rid=(int)$r['role_id']; ?>
            <a href="permissions.php?role_id=<?= $rid ?>" class="roleLink" style="background:<?= $selectedRoleId=== $rid ? '#eef6ff' : 'transparent' ?>">
              <span><?= h($r['role_name']) ?></span>
              <span class="roleCount">View</span>
            </a>
          <?php endforeach; ?>
        </div>

        <div style="margin-top:16px; font-weight:700;">Role Users</div>
        <div style="margin-top:8px; color:var(--muted); font-size:13px;">
          <?php if ($selectedRoleId <= 0): ?>
            Select a role to view users.
          <?php else: ?>
            <?php if (count($roleUsers) === 0): ?>
              No users assigned to this role.
            <?php else: ?>
              <ul style="padding-left:18px; margin-top:6px;">
                <?php foreach ($roleUsers as $ru): ?>
                  <li><?= h($ru['full_name']) ?> (<?= h($ru['username']) ?>)</li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="main-col">
        <?php
          // Main Content Area for permissions
          require_once __DIR__ . '/content_blocks.php';
          $permissions_main = cb_get($conn, 'permissions_main');
        ?>
        <div class="card" style="padding:12px;">
          <div id="block_permissions_main"><?= $permissions_main !== '' ? $permissions_main : '<div class="small">Permissions main content. Customize permissions and role mappings here.</div>' ?></div>
        </div>

        <div class="perm-hero">
          <div class="block">
            <div style="font-weight:800; margin-bottom:8px;">Add Permission</div>
            <form method="post" id="addPermForm">
              <input type="hidden" name="action" value="add_permission">
              <div class="add-form">
                <select id="permTemplate" style="min-width:220px;">
                  <option value="">-- Templates (optional) --</option>
                  <?php foreach ($commonPermissions as $k=>$d): ?>
                    <option value="<?= h($k) ?>"><?= h($k) ?> - <?= h($d) ?></option>
                  <?php endforeach; ?>
                </select>

                <input id="permission_key" name="permission_key" placeholder="permission_key (e.g. users.create)" style="flex:1; min-width:220px;" required>
                <input id="description" name="description" placeholder="description (optional)" style="min-width:220px;">
                <button class="btn-primary" type="submit">Add</button>
              </div>
              <div id="permHelp" style="margin-top:8px; color:var(--muted); font-size:13px;"></div>
            </form>
          </div>

          <div class="block" style="width:320px;">
            <div style="font-weight:800; margin-bottom:8px;">Quick Open</div>
            <div class="quick-col">
              <a class="btn" href="dashboardadmin.php" target="_blank">📊 Dashboard (charts)</a>
              <a class="btn" href="students.php" target="_blank">👩‍🎓 Students</a>
              <a class="btn" href="users_roles.php" target="_blank">🛡️ Users & Roles</a>
              <a class="btn" href="permissions.php" target="_blank">🔑 Permissions</a>
              <a class="btn" href="marks.php" target="_blank">📝 Marks / Results</a>
              <a class="btn" href="attendance_admin.php" target="_blank">📋 Attendance</a>
              <a class="btn" href="finance_invoices.php" target="_blank">💰 Invoices</a>
            </div>
          </div>
        </div>
      </div>
      
      <div class="right-col">
        <!-- placeholder for additional controls if needed -->
      </div>
    </div>
  </div>

  <!-- By User Section -->
  <div id="byUserSection" style="display:none; margin-top:18px;">
    <div class="card" style="padding:12px;">
      <div style="display:flex; gap:12px; align-items:center;">
        <div style="min-width:280px;">
          <label style="display:block; font-weight:700; margin-bottom:8px;">Select User</label>
          <select id="byUserSelect" style="width:100%; padding:8px; border-radius:8px; border:1px solid var(--border);">
            <option value="">-- Select user --</option>
            <?php foreach ($users as $u): ?>
              <option value="<?= (int)$u['user_id'] ?>"><?= h($u['full_name'] ?: $u['username']) ?> (<?= h($u['email'] ?: $u['username']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="flex:1;">
          <div style="font-weight:800">User Permissions</div>
          <div class="small muted">Select permissions to assign to the chosen user. Save to persist.</div>
        </div>
      </div>

      <form method="post" id="userPermForm" style="margin-top:12px;">
        <input type="hidden" name="action" value="update_user_permissions">
        <input type="hidden" name="user_id" id="userPermUserId" value="">
        <div style="max-height:380px; overflow:auto; border:1px solid var(--border); border-radius:8px; padding:8px; margin-top:8px; background:#fff">
          <?php foreach ($permissions as $p): $pid=(int)$p['permission_id']; ?>
            <label style="display:flex; align-items:center; gap:10px; padding:6px; border-bottom:1px solid rgba(0,0,0,.03);">
              <input type="checkbox" name="permissions[]" value="<?= $pid ?>" class="user-perm-checkbox" data-perm-id="<?= $pid ?>">
              <div style="flex:1;"><strong><?= h($p['permission_key']) ?></strong><div class="small muted"><?= h($p['description'] ?: '') ?></div></div>
            </label>
          <?php endforeach; ?>
        </div>

        <div style="margin-top:12px; display:flex; gap:8px;">
          <button type="submit" class="btn btn-primary">Save User Permissions</button>
          <button type="button" class="btn" id="clearUserPerms">Clear Selection</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    // Client-side help for permission templates
    const templates = <?= json_encode($commonPermissions) ?>;
    const permTemplate = document.getElementById('permTemplate');
    const permKey = document.getElementById('permission_key');
    const permDesc = document.getElementById('description');
    const permHelp = document.getElementById('permHelp');

    function showHelpFor(key){
      if (!key) { permHelp.textContent = '' ; return; }
      if (templates[key]) {
        permHelp.textContent = 'Suggestion: ' + templates[key];
      } else {
        permHelp.textContent = '';
      }
    }

    permTemplate.addEventListener('change', function(){
      const v = this.value;
      if (!v) return;
      permKey.value = v;
      if (templates[v]) permDesc.value = templates[v];
      showHelpFor(v);
    });

    permKey.addEventListener('input', function(){ showHelpFor(this.value.trim()); });
  </script>

  <div id="listSection" style="display:none; margin-top:18px;">
    <h4>Permissions List</h4>
    <div style="margin:8px 0 12px; max-width:520px;" class="compact-search">
      <input id="permSearch" type="search" placeholder="Search permissions by key or description..." />
    </div>
    <?php if (count($permissions) === 0): ?>
      <div class="muted">No permissions defined yet.</div>
    <?php else: ?>
      <table id="permTable">
        <thead>
          <tr>
            <th style="width:40%">Key</th>
            <th style="width:35%">Description</th>
            <th style="width:25%">Assigned Roles & Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($permissions as $p): $pid = (int)$p['permission_id']; ?>
            <tr class="compact-row" data-search="<?= h(strtolower($p['permission_key'] . ' ' . ($p['description'] ?? ''))) ?>">
              <td style="font-weight:700; color:#0f172a"><?= h($p['permission_key']) ?></td>
              <td style="color:var(--muted)"><?= h($p['description']) ?: '<span style="color:var(--muted)">—</span>' ?></td>
              <td>
                <form method="post" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; width:100%;">
                  <input type="hidden" name="action" value="update_perm_roles">
                  <input type="hidden" name="permission_id" value="<?= $pid ?>">
                  <div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                    <?php foreach ($roles as $r): $rid=(int)$r['role_id']; $checked = in_array($rid, $permRoles[$pid] ?? []); ?>
                      <label class="role-checkbox" style="display:inline-flex; align-items:center; gap:6px; font-size:13px; color:#111;">
                        <input type="checkbox" name="roles[]" value="<?= $rid ?>" <?= $checked ? 'checked' : '' ?>> <span class="chip"><?= h($r['role_name']) ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                  <div style="margin-left:auto; display:flex; gap:8px; align-items:center;">
                    <button type="submit" class="btn btn-sm btn-primary">Save</button>
                    <button type="button" class="btn btn-sm" style="background:#eef2ff; color:#0f172a; border-radius:8px; padding:6px 8px;" onclick="openAssignUsers(<?= $pid ?>)">Assign Users</button>
                    </form>

                    <form method="post" style="display:inline-block;" onsubmit="return confirm('Delete permission?');">
                      <input type="hidden" name="action" value="delete_permission">
                      <input type="hidden" name="permission_id" value="<?= $pid ?>">
                      <button type="submit" class="btn btn-sm" style="background:#fee2e2; color:#b91c1c; border-radius:8px; padding:6px 8px;">Delete</button>
                    </form>
                  </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

</div>

<!-- tab styles are defined above -->

<script>
  // Toggle Add / List / ByUser sections and wire By User behavior
  (function(){
    const btnAdd = document.getElementById('btnShowAdd');
    const btnList = document.getElementById('btnShowList');
    const btnByUser = document.getElementById('btnShowByUser');
    const addSec = document.getElementById('addSection');
    const listSec = document.getElementById('listSection');
    const byUserSec = document.getElementById('byUserSection');

    function showAdd(){ addSec.style.display='flex'; listSec.style.display='none'; byUserSec.style.display='none'; btnAdd.classList.add('active'); btnList.classList.remove('active'); btnByUser.classList.remove('active'); }
    function showList(){ addSec.style.display='none'; listSec.style.display='block'; byUserSec.style.display='none'; btnAdd.classList.remove('active'); btnList.classList.add('active'); btnByUser.classList.remove('active'); }
    function showByUser(){ addSec.style.display='none'; listSec.style.display='none'; byUserSec.style.display='block'; btnAdd.classList.remove('active'); btnList.classList.remove('active'); btnByUser.classList.add('active'); }

    btnAdd.addEventListener('click', showAdd);
    btnList.addEventListener('click', showList);
    btnByUser.addEventListener('click', showByUser);

    // initial state: show add
    showAdd();

    // By-User behavior
    const byUserSelect = document.getElementById('byUserSelect');
    const userPermUserId = document.getElementById('userPermUserId');
    const permCheckboxes = Array.from(document.querySelectorAll('.user-perm-checkbox'));
    const clearBtn = document.getElementById('clearUserPerms');

    // mapping from user_id -> [permission_id,...]
    const userPermsMap = <?= json_encode($userPerms) ?>;

    function populateUserPerms(uid) {
      userPermUserId.value = uid || '';
      const assigned = userPermsMap[uid] || [];
      permCheckboxes.forEach(cb => {
        const pid = parseInt(cb.dataset.permId, 10);
        cb.checked = assigned.includes(pid);
      });
    }

    if (byUserSelect) {
      byUserSelect.addEventListener('change', function(){
        const v = parseInt(this.value||'0',10);
        populateUserPerms(v);
      });
    }

    if (clearBtn) clearBtn.addEventListener('click', ()=> permCheckboxes.forEach(cb=>cb.checked=false));
  })();
</script>

<script>
  // Compact search for permissions list
  (function(){
    const search = document.getElementById('permSearch');
    const rows = Array.from(document.querySelectorAll('#permTable tbody tr'));
    if (!search) return;
    function filter(){
      const q = (search.value||'').toLowerCase().trim();
      let visible = 0;
      rows.forEach(r=>{
        const s = r.getAttribute('data-search') || '';
        const ok = q === '' ? true : s.includes(q);
        r.style.display = ok ? '' : 'none';
        if (ok) visible++;
      });
    }
    search.addEventListener('input', filter);
  })();
</script>

<script>
  // Assign Users modal
  const usersList = <?= json_encode($users) ?>;
  const permUsersMap = <?= json_encode($permUsers) ?>;

  function openAssignUsers(permissionId){
    // build checkboxes
    let html = '<div style="max-height:320px; overflow:auto; text-align:left;">';
    for (const u of usersList) {
      const checked = (permUsersMap[permissionId] || []).includes(parseInt(u.user_id,10)) ? 'checked' : '';
      html += `<label style="display:block; padding:6px 8px; border-bottom:1px solid rgba(0,0,0,.03);"><input type=\"checkbox\" value=\"${u.user_id}\" ${checked} /> <strong style=\"margin-left:8px\">${escapeHtml(u.full_name||u.username)}</strong> <span style=\"color:var(--muted); margin-left:6px\">(${escapeHtml(u.email||'')})</span></label>`;
    }
    html += '</div>';

    Swal.fire({
      title: 'Assign Users',
      html: html,
      width: 700,
      showCancelButton: true,
      confirmButtonText: 'Save',
      preConfirm: () => {
        const container = document.createElement('div');
        container.innerHTML = html;
        const checked = Array.from(container.querySelectorAll('input[type=checkbox]:checked')).map(n=>n.value);
        return checked;
      }
    }).then(res => {
      if (!res.isConfirmed) return;
      const selected = res.value || [];
      const fd = new FormData();
      fd.append('action','update_perm_users');
      fd.append('permission_id', permissionId);
      for (const s of selected) fd.append('users[]', s);
      fetch('permissions.php', { method:'POST', body: fd }).then(r=>{
        // reload page to reflect changes
        window.location.reload();
      }).catch(e=>Swal.fire({icon:'error', title:e.message}));
    });
  }

  function escapeHtml(s){ return String(s).replace(/[&<>"]+/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
</script>
</body>
</html>
