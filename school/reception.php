<?php
session_start();
if (!isset($_SESSION['user_id'])) {
  header('Location: login.php');
  exit;
}
// try to include DB connection if available
@include_once 'conncation.php';

$tot_students = 0;
$enrollments = 0;
$active_students = 0;
if (isset($conn) && $conn) {
  try {
    $r = $conn->query("SELECT COUNT(*) AS c FROM students");
    if ($r) { $tot_students = (int)$r->fetch_assoc()['c']; }
    $r = $conn->query("SELECT COUNT(*) AS c FROM enrollments");
    if ($r) { $enrollments = (int)$r->fetch_assoc()['c']; }
    $r = $conn->query("SELECT COUNT(*) AS c FROM students WHERE status='active'");
    if ($r) { $active_students = (int)$r->fetch_assoc()['c']; }
  } catch (Exception $e) {
    // ignore DB errors, keep zeros
  }
} elseif (isset($mysqli) && $mysqli) {
  try {
    $r = $mysqli->query("SELECT COUNT(*) AS c FROM students");
    if ($r) { $tot_students = (int)$r->fetch_assoc()['c']; }
  } catch (Exception $e) {}
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Reception Dashboard</title>
    <link rel="stylesheet" href="bootstrap.css">
    <link rel="stylesheet" href="dashboardadmin.css">
    <style>
      :root{--sidebar-bg:#0b1624;--panel-bg:#fff;--muted:#6b7280}
      body{margin:0;font-family:Inter,system-ui,Segoe UI,Roboto,Helvetica,Arial;background:#f3f6fb}
      .app { display:flex; min-height:100vh }
      .sidebar { width:220px; background:var(--sidebar-bg); color:#e6eefc; padding:20px 12px; box-sizing:border-box; position:fixed; left:0; top:0; bottom:0 }
      .brand{font-weight:800;margin-bottom:18px;color:#fff}
      .profile{background:#0f172a;padding:12px;border-radius:10px;margin-bottom:18px}
      .nav{margin-top:8px}
      .nav a{display:block;color:#cbd5e1;padding:10px 12px;border-radius:8px;margin-bottom:6px;text-decoration:none}
      .nav a.active{background:rgba(255,255,255,0.06);color:#fff}
      .main { margin-left:240px; padding:24px 32px; flex:1 }
      .topbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:18px }
      .cards { display:flex; gap:18px; margin-bottom:18px }
      .card { background:var(--panel-bg); border-radius:12px; padding:18px; box-shadow:0 8px 20px rgba(16,24,40,0.04); flex:1 }
      .card h3{margin:0;font-size:12px;color:var(--muted);text-transform:uppercase}
      .card .num{font-size:28px;font-weight:800;margin-top:6px}
      .content { background:#fff;border-radius:12px;padding:12px; min-height:420px; box-shadow:0 6px 20px rgba(16,24,40,0.04) }
      /* focus mode hides dashboard chrome so only the embedded form remains */
      .main.focus-mode .cards{display:none}
      .main.focus-mode .breadcrumb-card{display:none}
      #pageContainer{min-height:520px}
      .content .controls { text-align:right; margin-bottom:8px }
      iframe.page-frame{width:100%;height:560px;border:0;border-radius:10px}
      .breadcrumb-card{margin-top:18px;padding:14px;border-radius:26px;background:#fff;display:flex;align-items:center;gap:12px}
    </style>
  </head>
  <body>
    <div class="app">
      <aside class="sidebar">
        <div class="brand">HIGH SCHOOL<br/><small style="opacity:.7">Reception - 2026</small></div>
        <div class="profile">
          <div style="font-weight:700"><?php echo htmlspecialchars($_SESSION['username'] ?? 'Guest'); ?></div>
          <div style="font-size:12px;color:#93c5fd">Role: RECEPTION</div>
        </div>
        <nav class="nav">
          <a href="#" class="active" data-page="dashboard">Dashboard</a>
          <a href="#" data-page="students_add.php">Create Student</a>
          <a href="students.php">Students</a>
          <a href="enrollments.php">Enrollments</a>
          <a href="sms.php">SMS</a>
        </nav>
      </aside>

      <main class="main">
        <div class="topbar">
          <div>
            <div style="font-size:18px;font-weight:800">Welcome, <?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?> 👋</div>
            <div style="font-size:12px;color:#6b7280">Reception Dashboard</div>
          </div>
          <div>
            <button onclick="location.href='logout.php'" class="btn btn-sm btn-outline">Logout</button>
          </div>
        </div>

        <div class="cards">
          <div class="card">
            <h3>Total Students</h3>
            <div class="num"><?php echo $tot_students ?: 0; ?></div>
            <div style="font-size:12px;color:#64748b">Active: <?php echo $active_students ?: 0; ?></div>
          </div>
          <div class="card">
            <h3>Enrollments</h3>
            <div class="num"><?php echo $enrollments ?: 0; ?></div>
            <div style="font-size:12px;color:#64748b">Enrolled now</div>
          </div>
          <div class="card">
            <h3>Create Student</h3>
            <div class="num">Quick Action</div>
            <div style="font-size:12px;color:#64748b">Use menu to add</div>
          </div>
        </div>

        <div class="content">
          <div style="font-weight:700;margin-bottom:8px"> <span id="pageTitle">students_add.php</span></div>
          <div class="controls">
            <button id="backBtn" onclick="goBack()" class="btn btn-sm">Back</button>
            <button id="reloadBtn" onclick="reloadFrame()" class="btn btn-sm">Reload</button>
          </div>
          <!-- Embedded page container for focused views -->
          <div id="pageContainer" style="display:none"></div>
          <!-- iframe fallback for full page loads -->
          <iframe id="pageFrame" class="page-frame" src="students_add.php"></iframe>
        </div>

        <div class="breadcrumb-card">
          <div style="font-weight:700">Students</div>
          <div style="color:#64748b">|</div>
          <div style="color:#64748b">🏠 - Admission Form</div>
        </div>

      </main>
    </div>

    <script>
      // Menu handling: load page into iframe or embed focused Admission form
      let embeddedMode = false;
      let embeddedPage = '';
      document.querySelectorAll('.nav a').forEach(a=>{
        a.addEventListener('click', function(e){
          e.preventDefault();
          document.querySelectorAll('.nav a').forEach(x=>x.classList.remove('active'));
          this.classList.add('active');
          const page = this.getAttribute('data-page');
          if (!page || page === 'dashboard') {
            // show dashboard (cards + breadcrumb) and clear embedded content
            exitEmbedded();
            document.getElementById('pageFrame').src = 'about:blank';
            document.getElementById('pageTitle').textContent = 'Dashboard';
            return;
          }
          // If target is the Admission form, embed it for a distraction-free flow
          if (page === 'students_add.php') {
            loadEmbedded(page);
            return;
          }
          // default: load into iframe
          exitEmbedded();
          document.getElementById('pageFrame').src = page;
          document.getElementById('pageTitle').textContent = page;
        });
      });

      async function loadEmbedded(page){
        try{
          const res = await fetch(page, {credentials:'same-origin'});
          const html = await res.text();
          const parser = new DOMParser();
          const doc = parser.parseFromString(html, 'text/html');
          const form = doc.querySelector('form');
          const container = document.getElementById('pageContainer');
          if (!form) {
            // fallback to iframe if no form found
            exitEmbedded();
            document.getElementById('pageFrame').src = page;
            document.getElementById('pageTitle').textContent = page;
            return;
          }
          // prepare form
          form.classList.add('embedded-form');
          form.querySelectorAll('script').forEach(s=>s.remove());
          container.innerHTML = '';
          container.appendChild(form);
          // enter focus mode
          document.querySelector('.main').classList.add('focus-mode');
          container.style.display = '';
          document.getElementById('pageFrame').style.display = 'none';
          document.getElementById('pageTitle').innerHTML = '<span style="font-weight:800">🏠</span> &nbsp; Admission Form';
          embeddedMode = true;
          embeddedPage = page;
          // focus first input
          const first = container.querySelector('input, select, textarea, button');
          if (first) first.focus();
          window.scrollTo({top:0,behavior:'smooth'});
        }catch(err){
          console.error(err);
          exitEmbedded();
          document.getElementById('pageFrame').src = page;
        }
      }

      function exitEmbedded(){
        embeddedMode = false;
        embeddedPage = '';
        const container = document.getElementById('pageContainer');
        container.innerHTML = '';
        container.style.display = 'none';
        document.getElementById('pageFrame').style.display = '';
        document.querySelector('.main').classList.remove('focus-mode');
        document.getElementById('pageTitle').textContent = '';
      }

      function goBack(){
        if (embeddedMode) {
          exitEmbedded();
          return;
        }
        const f = document.getElementById('pageFrame');
        try { f.contentWindow.history.back(); } catch(e){ /*ignore*/ }
      }

      function reloadFrame(){
        if (embeddedMode && embeddedPage) {
          loadEmbedded(embeddedPage);
          return;
        }
        const f = document.getElementById('pageFrame');
        f.src = f.src;
      }
    </script>
  </body>
</html>
