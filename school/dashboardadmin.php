<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . "/conncation.php";

/* =========================
   ADMIN GUARD
   ========================= */
if (!isset($_SESSION["user_id"])) { header("Location: login.php"); exit; }
if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "ADMIN") { header("Location: login.php"); exit; }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$userId   = (int)($_SESSION["user_id"]);
$username = (string)($_SESSION["username"] ?? "Admin");
$role     = (string)($_SESSION["role"] ?? "ADMIN");

/* =========================
   LOGOUT
   ========================= */
if (isset($_GET["logout"]) && $_GET["logout"] === "1") {
  session_destroy();
  header("Location: login.php");
  exit;
}

/* =========================
   FLASH ALERT (SweetAlert)
   ========================= */
function setAlert(string $type, string $title, string $text): void {
  $_SESSION["flash_alert"] = ["type"=>$type, "title"=>$title, "text"=>$text];
}
function popAlert(): ?array {
  if (!isset($_SESSION["flash_alert"])) return null;
  $a = $_SESSION["flash_alert"];
  unset($_SESSION["flash_alert"]);
  return $a;
}
$alert = popAlert();

/* =========================
   HELPERS
   ========================= */
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8"); }

/** Safe count */
function safeCount(mysqli $conn, string $sql, string $label = "Query"): int {
  try {
    $res = $conn->query($sql);
    $row = $res ? $res->fetch_row() : null;
    return (int)($row[0] ?? 0);
  } catch (Throwable $e) {
    if (!isset($_SESSION["_db_warned"])) {
      $_SESSION["_db_warned"] = 1;
      setAlert("warning", "Database Warning", "Some dashboard stats could not be loaded (missing table/column). Please check database structure.");
    }
    return 0;
  }
}

/** Safe sum */
function safeSum(mysqli $conn, string $sql): float {
  try {
    $res = $conn->query($sql);
    $row = $res ? $res->fetch_assoc() : null;
    return (float)($row["s"] ?? 0);
  } catch (Throwable $e) {
    if (!isset($_SESSION["_db_warned"])) {
      $_SESSION["_db_warned"] = 1;
      setAlert("warning", "Database Warning", "Some dashboard totals could not be loaded. Please verify finance tables.");
    }
    return 0.0;
  }
}

/** Safe group (for charts) -> returns array rows */
function safeRows(mysqli $conn, string $sql): array {
  try {
    $res = $conn->query($sql);
    $out = [];
    if ($res) while ($r = $res->fetch_assoc()) $out[] = $r;
    return $out;
  } catch (Throwable $e) {
    if (!isset($_SESSION["_db_warned"])) {
      $_SESSION["_db_warned"] = 1;
      setAlert("warning", "Database Warning", "Some charts could not be loaded. Please check required tables/columns.");
    }
    return [];
  }
}


/* =========================
   FETCH ADMIN PROFILE (employees)
   ========================= */
$adminName   = $username;
$adminPhone  = "";
$adminPhoto  = "";
$adminStatus = "ACTIVE";

try {
  $stmt = $conn->prepare("
    SELECT e.full_name, e.phone, e.photo_url, e.status
    FROM employees e
    WHERE e.user_id = ?
    LIMIT 1
  ");
  $stmt->bind_param("i", $userId);
  $stmt->execute();
  $empRes = $stmt->get_result();
  if ($emp = $empRes->fetch_assoc()) {
    $adminName   = (string)($emp["full_name"] ?? $adminName);
    $adminPhone  = (string)($emp["phone"] ?? "");
    $adminStatus = (string)($emp["status"] ?? "ACTIVE");
  }
  $stmt->close();
} catch (Throwable $e) {
  // ignore
}

/* =========================
   DASHBOARD STATS (DB)
   ========================= */
$totalStudents      = safeCount($conn, "SELECT COUNT(*) FROM students", "Students");
$activeStudents     = safeCount($conn, "SELECT COUNT(*) FROM students WHERE status='ACTIVE'", "Active Students");
$totalTeachers      = safeCount($conn, "SELECT COUNT(*) FROM teachers", "Teachers");
$totalSections      = safeCount($conn, "SELECT COUNT(*) FROM sections", "Sections");
$totalSubjects      = safeCount($conn, "SELECT COUNT(*) FROM subjects WHERE is_active=1", "Subjects");
$totalUsers         = safeCount($conn, "SELECT COUNT(*) FROM users", "Users");

$totalEnrollments   = safeCount($conn, "SELECT COUNT(*) FROM enrollments", "Enrollments");
$enrolledNow        = safeCount($conn, "SELECT COUNT(*) FROM enrollments WHERE status='ENROLLED'", "Enrolled");

$totalInvoices      = safeCount($conn, "SELECT COUNT(*) FROM student_invoices", "Invoices");
$unpaidInvoices     = safeCount($conn, "SELECT COUNT(*) FROM student_invoices WHERE status IN ('ISSUED','PARTIAL')", "Unpaid");
$totalPayments      = safeCount($conn, "SELECT COUNT(*) FROM payments", "Payments");
$totalRevenue       = safeSum($conn, "SELECT COALESCE(SUM(amount),0) AS s FROM payments");

/* Current academic year name */
$currentYearName = "N/A";
try {
  $yr = $conn->query("SELECT year_name FROM academic_years WHERE is_current=1 LIMIT 1");
  if ($yr && $yrRow = $yr->fetch_assoc()) $currentYearName = (string)($yrRow["year_name"] ?? "N/A");
} catch (Throwable $e) { $currentYearName = "N/A"; }

/* =========================
   RECENT PAYMENTS (table)
   ========================= */
$recentPayments = [];
try {
  $rp = $conn->query("
    SELECT p.payment_id, p.amount, p.paid_date, p.reference_no, pm.method_name,
           si.invoice_no
    FROM payments p
    JOIN payment_methods pm ON pm.method_id = p.method_id
    JOIN student_invoices si ON si.invoice_id = p.invoice_id
    ORDER BY p.paid_date DESC
    LIMIT 8
  ");
  if ($rp) while ($row = $rp->fetch_assoc()) $recentPayments[] = $row;
} catch (Throwable $e) {}

/* =========================
   RECENT ACTIVITY LOGS
   ========================= */
$recentLogs = [];
try {
  $lg = $conn->query("
    SELECT log_id, action, entity, entity_id, created_at
    FROM activity_logs
    ORDER BY created_at DESC
    LIMIT 8
  ");
  if ($lg) while ($row = $lg->fetch_assoc()) $recentLogs[] = $row;
} catch (Throwable $e) {}

/* =========================
   CHARTS (3) — DB DATA
   ========================= */

/* Chart 1: Monthly revenue (last 6 months) */
$monthlyRevenueRows = safeRows($conn, "
  SELECT DATE_FORMAT(paid_date,'%Y-%m') AS ym,
         COALESCE(SUM(amount),0) AS total
  FROM payments
  WHERE paid_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
  GROUP BY DATE_FORMAT(paid_date,'%Y-%m')
  ORDER BY ym ASC
");

/* Chart 2: Invoice status distribution */
$invoiceStatusRows = safeRows($conn, "
  SELECT status, COUNT(*) AS c
  FROM student_invoices
  GROUP BY status
  ORDER BY c DESC
");

/* Chart 3: Enrollment status distribution */
$enrollStatusRows = safeRows($conn, "
  SELECT status, COUNT(*) AS c
  FROM enrollments
  GROUP BY status
  ORDER BY c DESC
");

/* Prepare chart JSON */
$chartMonthlyLabels = [];
$chartMonthlyValues = [];
foreach ($monthlyRevenueRows as $r) {
  $chartMonthlyLabels[] = (string)($r["ym"] ?? "");
  $chartMonthlyValues[] = (float)($r["total"] ?? 0);
}

$chartInvoiceLabels = [];
$chartInvoiceValues = [];
foreach ($invoiceStatusRows as $r) {
  $chartInvoiceLabels[] = (string)($r["status"] ?? "UNKNOWN");
  $chartInvoiceValues[] = (int)($r["c"] ?? 0);
}

$chartEnrollLabels = [];
$chartEnrollValues = [];
foreach ($enrollStatusRows as $r) {
  $chartEnrollLabels[] = (string)($r["status"] ?? "UNKNOWN");
  $chartEnrollValues[] = (int)($r["c"] ?? 0);
}

$APP_VERSION = "v3.0.0 (Ultra Modern Dashboard)";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Dashboard - HIGH School</title>

  <link rel="stylesheet" href="bootstrap.css">
  <link rel="stylesheet" href="dashboardadmin.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>

<body>
<div class="app">

  <!-- Desktop Sidebar -->
  <aside class="sidebar" id="desktopSidebar">
    <div class="brand">
      <div class="brandLeft">
        <div class="brandLogo"><i class="fa-solid fa-school"></i></div>
        <div class="brandText">
          <div class="t1">HIGH SCHOOL</div>
          <div class="t2">Admin Panel • <?= h($currentYearName) ?></div>
        </div>

        

      </div>
    </div>

    <div class="adminCard">
      <div class="adminAvatar">
        <?php if ($adminPhoto !== ""): ?>
          <img src="<?= h($adminPhoto) ?>" alt="Admin">
        <?php else: ?>
          <i class="fa-solid fa-user" style="font-size:18px; opacity:.95;"></i>
        <?php endif; ?>
      </div>
      <div class="adminMeta">
        <div class="n" title="<?= h($adminName) ?>"><?= h($adminName) ?></div>
        <div class="r"><?= h($username) ?><?= $adminPhone ? " • " . h($adminPhone) : "" ?></div>
        <div class="statusPill"><i class="fa-solid fa-circle-check"></i> <?= h($adminStatus) ?></div>
      </div>
    </div>

    <div class="menuWrap">
      <div class="menuTitle">Main <span class="chip">ultra</span></div>
      <nav class="menu">

        <a href="dashboardadmin.php" class="menuLink active" data-mode="dashboard" data-title="Dashboard" data-file="dashboardadmin.php">
          <span class="left"><i class="fa-solid fa-chart-line"></i> <span class="text">Dashboard</span></span>
        </a>

        <button type="button" class="menuToggle" data-target="sm_students_desktop">
          <span class="left"><i class="fa-solid fa-user-graduate"></i> <span class="text">Students</span></span>
          <i class="fa-solid fa-chevron-down chev"></i>
        </button>
        <div class="submenu" id="sm_students_desktop">
          <a href="students_add.php" class="menuLink" data-mode="page" data-title="Add Student" data-file="students_add.php" data-page="students_add.php">
            <span class="left"><i class="fa-solid fa-plus"></i> Add Student</span>
          </a>
          <a href="students.php" class="menuLink" data-mode="page" data-title="List Students" data-file="students.php" data-page="students.php">
            <span class="left"><i class="fa-solid fa-list"></i> List Students</span>
          </a>
          <a href="enrollments.php" class="menuLink" data-mode="page" data-title="Enrollments" data-file="enrollments.php" data-page="enrollments.php">
            <span class="left"><i class="fa-solid fa-id-card"></i> Enrollments</span>
          </a>
        </div>

        <button type="button" class="menuToggle" data-target="sm_teachers_desktop">
          <span class="left"><i class="fa-solid fa-chalkboard-user"></i> <span class="text">Teachers</span></span>
          <i class="fa-solid fa-chevron-down chev"></i>
        </button>
        <div class="submenu" id="sm_teachers_desktop">
          <a href="teachers_add.php" class="menuLink" data-mode="page" data-title="Add Teacher" data-file="teachers_add.php" data-page="teachers_add.php">
            <span class="left"><i class="fa-solid fa-plus"></i> Add Teacher</span>
          </a>
          <a href="teachers.php" class="menuLink" data-mode="page" data-title="List Teachers" data-file="teachers.php" data-page="teachers.php">
            <span class="left"><i class="fa-solid fa-list"></i> List Teachers</span>
          </a>
          <a href="teacher_subjects.php" class="menuLink" data-mode="page" data-title="Teacher Subjects" data-file="teacher_subjects.php" data-page="teacher_subjects.php">
            <span class="left"><i class="fa-solid fa-book-open"></i> Teacher Subjects</span>
          </a>
        </div>

        <div class="menuTitle">Academics <span class="chip">school</span></div>

        <a href="grades_sections.php" class="menuLink" data-mode="page" data-title="Grades & Sections" data-file="grades_sections.php" data-page="grades_sections.php">
          <span class="left"><i class="fa-solid fa-layer-group"></i> <span class="text">Grades & Sections</span></span>
        </a>

        <a href="academic.php" class="menuLink" data-mode="page" data-title="Academics" data-file="academic.php" data-page="academic.php">
          <span class="left"><i class="fa-solid fa-graduation-cap"></i> <span class="text">Academics</span></span>
        </a>

        <a href="subjects.php" class="menuLink" data-mode="page" data-title="Subjects" data-file="subjects.php" data-page="subjects.php">
          <span class="left"><i class="fa-solid fa-book"></i> <span class="text">Subjects</span></span>
        </a>

        <button type="button" class="menuToggle" data-target="sm_timetable_desktop">
          <span class="left"><i class="fa-solid fa-calendar-days"></i> <span class="text">Timetable</span></span>
          <i class="fa-solid fa-chevron-down chev"></i>
        </button>
        <div class="submenu" id="sm_timetable_desktop">
          <a href="timetable.php" class="menuLink" data-mode="page" data-title="Create Timetable" data-file="timetable.php" data-page="timetable.php">
            <span class="left"><i class="fa-solid fa-pen"></i> Create Timetable</span>
          </a>
          <a href="timetable_view.php" class="menuLink" data-mode="page" data-title="View Timetable" data-file="timetable_view.php" data-page="timetable_view.php">
            <span class="left"><i class="fa-solid fa-eye"></i> View Timetable</span>
          </a>
        </div>

        <button type="button" class="menuToggle" data-target="sm_att_desktop">
          <span class="left"><i class="fa-solid fa-clipboard-check"></i> <span class="text">Attendance</span></span>
          <i class="fa-solid fa-chevron-down chev"></i>
        </button>
        <div class="submenu" id="sm_att_desktop">
          <a href="attendance_admin.php" class="menuLink" data-mode="page" data-title="Attendance Reports" data-file="attendance_admin.php" data-page="attendance_admin.php">
            <span class="left"><i class="fa-solid fa-chart-simple"></i> Attendance Reports</span>
          </a>
          <a href="attendance_sessions.php" class="menuLink" data-mode="page" data-title="Attendance Sessions" data-file="attendance_sessions.php" data-page="attendance_sessions.php">
            <span class="left"><i class="fa-solid fa-calendar-check"></i> Sessions</span>
          </a>
        </div>

        <button type="button" class="menuToggle" data-target="sm_exams_desktop">
          <span class="left"><i class="fa-solid fa-pen-to-square"></i> <span class="text">Exams & Marks</span></span>
          <i class="fa-solid fa-chevron-down chev"></i>
        </button>
        <div class="submenu" id="sm_exams_desktop">
          <a href="exams.php" class="menuLink" data-mode="page" data-title="Exams" data-file="exams.php" data-page="exams.php">
            <span class="left"><i class="fa-solid fa-file-circle-check"></i> Exams</span>
          </a>
          <a href="marks.php" class="menuLink" data-mode="page" data-title="Marks" data-file="marks.php" data-page="marks.php">
            <span class="left"><i class="fa-solid fa-clipboard-list"></i> Marks</span>
          </a>
          <a href="results.php" class="menuLink" data-mode="page" data-title="Results" data-file="results.php" data-page="results.php">
            <span class="left"><i class="fa-solid fa-ranking-star"></i> Results</span>
          </a>
        </div>

        <div class="menuTitle">Finance <span class="chip">cash</span></div>

        <button type="button" class="menuToggle" data-target="sm_fin_desktop">
          <span class="left"><i class="fa-solid fa-coins"></i> <span class="text">Finance</span></span>
          <i class="fa-solid fa-chevron-down chev"></i>
        </button>
        <div class="submenu" id="sm_fin_desktop">
          <a href="finance_invoices.php" class="menuLink" data-mode="page" data-title="Invoices" data-file="finance_invoices.php" data-page="finance_invoices.php">
            <span class="left"><i class="fa-solid fa-file-invoice-dollar"></i> Invoices</span>
          </a>
          <a href="finance_payments.php" class="menuLink" data-mode="page" data-title="Payments" data-file="finance_payments.php" data-page="finance_payments.php">
            <span class="left"><i class="fa-solid fa-money-bill-wave"></i> Payments</span>
          </a>
          <a href="fee_structure.php" class="menuLink" data-mode="page" data-title="Fee Structure" data-file="fee_structure.php" data-page="fee_structure.php">
            <span class="left"><i class="fa-solid fa-tags"></i> Fee Structure</span>
          </a>
        </div>

        <a href="sms.php" class="menuLink" data-mode="page" data-title="SMS / Notifications" data-file="sms.php" data-page="sms.php">
          <span class="left"><i class="fa-solid fa-comment-sms"></i> <span class="text">SMS / Notifications</span></span>
        </a>

        <div class="menuTitle">System <span class="chip">admin</span></div>

        <a href="users_roles.php" class="menuLink" data-mode="page" data-title="Users & Roles" data-file="users_roles.php" data-page="users_roles.php">
          <span class="left"><i class="fa-solid fa-shield-halved"></i> <span class="text">Users & Roles</span></span>
        </a>

        <a href="permissions.php" class="menuLink" data-mode="page" data-title="Permissions" data-file="permissions.php" data-page="permissions.php">
          <span class="left"><i class="fa-solid fa-key"></i> <span class="text">Permissions</span></span>
        </a>

        <a href="activity_logs.php" class="menuLink" data-mode="page" data-title="Activity Logs" data-file="activity_logs.php" data-page="activity_logs.php">
          <span class="left"><i class="fa-solid fa-clock-rotate-left"></i> <span class="text">Activity Logs</span></span>
        </a>

        <a href="registration.php" class="menuLink" data-mode="page" data-title="Create Admin" data-file="registration.php" data-page="registration.php">
          <span class="left"><i class="fa-solid fa-user-plus"></i> <span class="text">Create Admin</span></span>
        </a>

      </nav>
    </div>

    <div class="sidebarFooter">
      <div>
        <b><?= h($role) ?></b>
        <div style="margin-top:6px;">© <?= date("Y") ?> HIGH School</div>
      </div>
      <div style="text-align:right; opacity:.95;">
        <div style="font-weight:950; color: rgba(255,255,255,.88);"><?= h($currentYearName) ?></div>
        <div style="margin-top:6px; font-weight:950;"><?= h($APP_VERSION) ?></div>
      </div>
    </div>
  </aside>

  <!-- Mobile overlay + sidebar -->
  <div class="overlay" id="overlay"></div>

  <div class="mobileSide" id="mobileSide">
    <aside class="sidebar" style="position:relative; box-shadow:none;">
      <div class="brand">
        <div class="brandLeft">
          <div class="brandLogo"><i class="fa-solid fa-school"></i></div>
          <div class="brandText">
            <div class="t1">HIGH SCHOOL</div>
            <div class="t2">Admin Panel • <?= h($currentYearName) ?></div>
          </div>
        </div>
        <button class="iconBtn" type="button" id="btnCloseMobile" title="Close">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </div>

      <div class="adminCard">
        <div class="adminAvatar">
          <?php if ($adminPhoto !== ""): ?>
            <img src="<?= h($adminPhoto) ?>" alt="Admin">
          <?php else: ?>
            <i class="fa-solid fa-user" style="font-size:18px; opacity:.95;"></i>
          <?php endif; ?>
        </div>
        <div class="adminMeta">
          <div class="n" title="<?= h($adminName) ?>"><?= h($adminName) ?></div>
          <div class="r"><?= h($username) ?><?= $adminPhone ? " • " . h($adminPhone) : "" ?></div>
          <div class="statusPill"><i class="fa-solid fa-circle-check"></i> <?= h($adminStatus) ?></div>
        </div>
      </div>

      <div class="menuWrap">
        <div class="menuTitle">Menu <span class="chip">mobile</span></div>
        <div id="mobileMenuClone"></div>
      </div>

      <div class="sidebarFooter">
        <div>
          <b><?= h($role) ?></b>
          <div style="margin-top:6px;">© <?= date("Y") ?> HIGH School</div>
        </div>
        <div style="text-align:right; opacity:.95;">
          <div style="font-weight:950; color: rgba(255,255,255,.88);"><?= h($currentYearName) ?></div>
          <div style="margin-top:6px; font-weight:950;"><?= h($APP_VERSION) ?></div>
        </div>
      </div>
    </aside>
  </div>

  <!-- MAIN -->
  <main class="main">

    <!-- TOPBAR -->
    <div class="topbar" id="topbar">
      <div class="tleft">
        <button class="hamb" id="btnHamb" type="button" title="Menu">
          <i class="fa-solid fa-bars"></i>
        </button>

        <div class="topTitle">
          <p class="hello">Welcome, <?= h($adminName) ?> 👋</p>
          <p class="sub">Ultra Modern Dashboard (cards + charts + shell)</p>

          <div class="nowView" id="nowView">
            <i class="fa-solid fa-eye"></i>
            Now Viewing:
            <code id="nowFile">dashboardadmin.php</code>
          </div>
        </div>
      </div>

      <div class="tright">
        <div class="searchBox" title="UI Search (Optional)">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="text" placeholder="Search menu... (UI only)" id="menuSearch" />
        </div>

        <button type="button" class="iconBtn" id="btnTheme" title="Theme">
          <i class="fa-solid fa-moon"></i>
        </button>

        <button type="button" class="iconBtn" id="btnFs" title="Fullscreen">
          <i class="fa-solid fa-expand"></i>
        </button>

        <div class="profileTop">
          <div class="avatarTop">
            <?php if ($adminPhoto !== ""): ?>
              <img src="<?= h($adminPhoto) ?>" alt="Admin">
            <?php else: ?>
              <i class="fa-solid fa-user" style="color: var(--primary);"></i>
            <?php endif; ?>
          </div>
          <div class="ptext">
            <div class="n"><?= h($adminName) ?></div>
            <div class="r"><?= h($username) ?> • <?= h($role) ?></div>
          </div>
        </div>

        <button type="button" class="iconBtn" id="btnLogout" title="Logout" style="border-color: rgba(239,68,68,.35);">
          <i class="fa-solid fa-right-from-bracket"></i>
        </button>
      </div>
    </div>

    <!-- DASHBOARD CONTENT -->
    <div id="dashboardBlock">
      <div class="sectionTitle">
        <span>Quick Statistics</span>
        <span class="small">Academic Year: <b><?= h($currentYearName) ?></b> • <b><?= h($APP_VERSION) ?></b></span>
      </div>

      <?php
      // Main Content Area block for dashboard
      require_once __DIR__ . '/content_blocks.php';
      $dashboard_main = cb_get($conn, 'dashboard_main');
      ?>
      <div class="card" style="margin:12px 0; padding:12px;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px;">
          <div style="flex:1;">
            <div id="block_dashboard_main"><?= $dashboard_main !== '' ? $dashboard_main : '<div style="color:#475569">Dashboard main content area. Click Edit to customize.</div>' ?></div>
          </div>
          <!-- Edit button removed per request -->
        </div>
      </div>

      <div class="cards">
        <div class="statCard">
          <div class="statLeft">
            <div class="label">Total Students</div>
            <div class="value"><?= number_format($totalStudents) ?></div>
            <div class="mini">Active: <?= number_format($activeStudents) ?></div>
            <svg class="spark" viewBox="0 0 120 26" preserveAspectRatio="none"><path d="M2 20 L20 18 L34 21 L48 14 L62 16 L76 9 L90 12 L108 6"/></svg>
          </div>
          <div class="statIcon"><i class="fa-solid fa-user-graduate"></i></div>
        </div>

        <div class="statCard">
          <div class="statLeft">
            <div class="label">Total Teachers</div>
            <div class="value"><?= number_format($totalTeachers) ?></div>
            <div class="mini">Teachers table</div>
            <svg class="spark" viewBox="0 0 120 26" preserveAspectRatio="none"><path d="M2 18 L18 19 L32 14 L46 16 L60 10 L74 12 L88 8 L108 6"/></svg>
          </div>
          <div class="statIcon"><i class="fa-solid fa-chalkboard-user"></i></div>
        </div>

        <div class="statCard">
          <div class="statLeft">
            <div class="label">Sections</div>
            <div class="value"><?= number_format($totalSections) ?></div>
            <div class="mini">Grades & Sections</div>
            <svg class="spark" viewBox="0 0 120 26" preserveAspectRatio="none"><path d="M2 20 L22 17 L36 18 L50 12 L66 13 L80 10 L96 9 L108 7"/></svg>
          </div>
          <div class="statIcon"><i class="fa-solid fa-layer-group"></i></div>
        </div>

        <div class="statCard">
          <div class="statLeft">
            <div class="label">Active Subjects</div>
            <div class="value"><?= number_format($totalSubjects) ?></div>
            <div class="mini">Enabled subjects</div>
            <svg class="spark" viewBox="0 0 120 26" preserveAspectRatio="none"><path d="M2 19 L18 16 L32 17 L46 13 L62 14 L78 10 L92 11 L108 8"/></svg>
          </div>
          <div class="statIcon"><i class="fa-solid fa-book"></i></div>
        </div>

        <div class="statCard">
          <div class="statLeft">
            <div class="label">Enrollments</div>
            <div class="value"><?= number_format($totalEnrollments) ?></div>
            <div class="mini">Enrolled now: <?= number_format($enrolledNow) ?></div>
            <svg class="spark" viewBox="0 0 120 26" preserveAspectRatio="none"><path d="M2 20 L16 18 L30 15 L44 16 L58 12 L72 10 L88 11 L108 7"/></svg>
          </div>
          <div class="statIcon"><i class="fa-solid fa-id-card"></i></div>
        </div>

        <div class="statCard">
          <div class="statLeft">
            <div class="label">Invoices</div>
            <div class="value"><?= number_format($totalInvoices) ?></div>
            <div class="mini">Unpaid/Partial: <?= number_format($unpaidInvoices) ?></div>
            <svg class="spark" viewBox="0 0 120 26" preserveAspectRatio="none"><path d="M2 18 L18 19 L34 16 L48 14 L62 15 L76 12 L92 9 L108 10"/></svg>
          </div>
          <div class="statIcon"><i class="fa-solid fa-file-invoice-dollar"></i></div>
        </div>

        <div class="statCard">
          <div class="statLeft">
            <div class="label">Payments</div>
            <div class="value"><?= number_format($totalPayments) ?></div>
            <div class="mini">Total payment records</div>
            <svg class="spark" viewBox="0 0 120 26" preserveAspectRatio="none"><path d="M2 21 L18 18 L34 19 L48 15 L62 13 L78 10 L92 11 L108 6"/></svg>
          </div>
          <div class="statIcon"><i class="fa-solid fa-money-bill-wave"></i></div>
        </div>

        <div class="statCard">
          <div class="statLeft">
            <div class="label">Total Revenue</div>
            <div class="value">$<?= number_format($totalRevenue, 2) ?></div>
            <div class="mini">SUM(payments.amount)</div>
            <svg class="spark" viewBox="0 0 120 26" preserveAspectRatio="none"><path d="M2 22 L16 19 L30 18 L44 14 L60 13 L74 9 L92 8 L108 6"/></svg>
          </div>
          <div class="statIcon"><i class="fa-solid fa-coins"></i></div>
        </div>
      </div>

      <!-- CHARTS (3) -->
      <div class="sectionTitle">
        <span>Analytics (Live from Database)</span>
        <span class="small">3 charts connected to Payments / Invoices / Enrollments</span>
      </div>

      <div class="gridCharts">
        <div class="panel">
          <div class="panelHead">
            <h5><i class="fa-solid fa-chart-column"></i> Revenue Trend (Last 6 Months)</h5>
            <span class="badge-soft"><i class="fa-solid fa-database"></i> Live</span>
          </div>
          <div class="chartBox"><canvas id="chartRevenue"></canvas></div>
        </div>

        <div class="panel">
          <div class="panelHead">
            <h5><i class="fa-solid fa-chart-pie"></i> Invoice Status Distribution</h5>
            <span class="badge-soft"><i class="fa-solid fa-file-invoice"></i> Live</span>
          </div>
          <div class="chartBox"><canvas id="chartInvoices"></canvas></div>
        </div>
      </div>

      <div class="gridCharts2">
        <div class="panel">
          <div class="panelHead">
            <h5><i class="fa-solid fa-chart-simple"></i> Enrollment Status Overview</h5>
            <span class="badge-soft"><i class="fa-solid fa-id-card"></i> Live</span>
          </div>
          <div class="chartBox"><canvas id="chartEnrollments"></canvas></div>
        </div>
      </div>

      <!-- TABLES + QUICK ACTIONS -->
      <div class="grid2">
        <div class="panel">
          <div class="panelHead">
            <h5><i class="fa-solid fa-coins"></i> Recent Payments</h5>
            <div class="d-flex gap-2">
              <button type="button" class="btn btn-primary btn-sm btnRound menuJump"
                      data-title="Payments" data-file="finance_payments.php" data-page="finance_payments.php">
                <i class="fa-solid fa-arrow-up-right-from-square"></i> Open inside
              </button>
            </div>
          </div>

          <div class="table-responsive">
            <table class="table table-hover align-middle">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Invoice</th>
                  <th>Method</th>
                  <th>Amount</th>
                  <th>Date</th>
                  <th>Ref</th>
                </tr>
              </thead>
              <tbody>
                <?php if (count($recentPayments) === 0): ?>
                  <tr><td colspan="6" class="text-center" style="color:var(--muted); font-weight:950;">No payments found.</td></tr>
                <?php else: ?>
                  <?php foreach ($recentPayments as $p): ?>
                    <tr>
                      <td><?= (int)$p["payment_id"] ?></td>
                      <td style="font-weight:950;"><?= h($p["invoice_no"] ?? "") ?></td>
                      <td><span class="badge-soft"><?= h($p["method_name"] ?? "") ?></span></td>
                      <td style="font-weight:950;">$<?= number_format((float)$p["amount"], 2) ?></td>
                      <td><?= h($p["paid_date"] ?? "") ?></td>
                      <td><?= h($p["reference_no"] ?? "-") ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="panel">
          <div class="panelHead">
            <h5><i class="fa-solid fa-bolt"></i> Quick Actions</h5>
          </div>

          <div class="d-grid gap-2">
            <button type="button" class="btn btn-primary menuJump" style="border-radius:16px; font-weight:950;"
                    data-title="Add Student" data-file="students_add.php" data-page="students_add.php">
              <i class="fa-solid fa-user-plus"></i> Add Student (inside)
            </button>

            <button type="button" class="btn btn-outline-primary menuJump" style="border-radius:16px; font-weight:950;"
                    data-title="List Students" data-file="students.php" data-page="students.php">
              <i class="fa-solid fa-list"></i> List Students (inside)
            </button>

            <button type="button" class="btn btn-outline-warning menuJump" style="border-radius:16px; font-weight:950;"
                    data-title="Invoices" data-file="finance_invoices.php" data-page="finance_invoices.php">
              <i class="fa-solid fa-file-invoice-dollar"></i> Create Invoice (inside)
            </button>
          </div>

          <hr style="border-color: var(--border); opacity:1; margin: 14px 0;">

          <div class="panelHead" style="margin-top:0;">
            <h5><i class="fa-solid fa-clock-rotate-left"></i> Recent Logs</h5>
            <button type="button" class="btn btn-sm btn-outline-dark btnRound menuJump"
                    style="border-color:var(--border); color:var(--text); font-weight:950;"
                    data-title="Activity Logs" data-file="activity_logs.php" data-page="activity_logs.php">
              View inside
            </button>
          </div>

          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead>
                <tr>
                  <th>Action</th>
                  <th>Entity</th>
                  <th>Time</th>
                </tr>
              </thead>
              <tbody>
                <?php if (count($recentLogs) === 0): ?>
                  <tr><td colspan="3" class="text-center" style="color:var(--muted); font-weight:950;">No logs found.</td></tr>
                <?php else: ?>
                  <?php foreach ($recentLogs as $l): ?>
                    <tr>
                      <td style="font-weight:950;"><?= h($l["action"] ?? "") ?></td>
                      <td><?= h(($l["entity"] ?? "") . ($l["entity_id"] ? " #" . $l["entity_id"] : "")) ?></td>
                      <td style="color:var(--muted); font-weight:950;"><?= h($l["created_at"] ?? "") ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

        </div>
      </div>
    </div>

    <!-- PAGE VIEW (inside dashboard) -->
    <div id="pageBlock" style="display:none;">
      <div class="contentWrap">
        <div class="contentHead">
          <div class="left">
            <i class="fa-solid fa-window-maximize"></i>
            <div style="min-width:0;">
              <div class="title" id="viewTitle">Page</div>
              <div class="file">File: <code id="viewFile">file.php</code></div>
            </div>
          </div>
          <div class="actions">
            <button class="btnMini" type="button" id="btnBackDash">
              <i class="fa-solid fa-arrow-left"></i> Back Dashboard
            </button>
            <button class="btnMini" type="button" id="btnReloadFrame">
              <i class="fa-solid fa-rotate-right"></i> Reload
            </button>
            <button class="btnMini" type="button" id="btnOpenNewTab">
              <i class="fa-solid fa-up-right-from-square"></i> Open New Tab
            </button>
          </div>
        </div>

        <iframe id="pageFrame" src="about:blank"></iframe>
      </div>
    </div>

  </main>
</div>

<?php if ($alert): ?>
<script>
  Swal.fire({
    icon: <?= json_encode($alert["type"]) ?>,
    title: <?= json_encode($alert["title"]) ?>,
    text: <?= json_encode($alert["text"]) ?>,
    confirmButtonColor: "#2563eb",
    width: 560
  });
</script>
<?php endif; ?>

<script>
  /* =========================
     SweetAlert helpers (global)
     ========================= */
  const Toast = Swal.mixin({
    toast: true,
    position: "top-end",
    showConfirmButton: false,
    timer: 2600,
    timerProgressBar: true
  });
  function toastOk(msg){ Toast.fire({ icon:"success", title: msg }); }
  function toastErr(msg){ Toast.fire({ icon:"error", title: msg }); }
  function swalErr(title, msg){
    Swal.fire({ icon:"error", title, text: msg, confirmButtonColor:"#2563eb", width: 560 });
  }

  /* =========================
     Mobile sidebar open/close
     ========================= */
  const overlay = document.getElementById("overlay");
  const mobileSide = document.getElementById("mobileSide");
  const btnHamb = document.getElementById("btnHamb");
  const btnCloseMobile = document.getElementById("btnCloseMobile");

  function openMobile(){
    mobileSide.classList.add("open");
    overlay.classList.add("show");
    document.body.style.overflow = "hidden";
  }
  function closeMobile(){
    mobileSide.classList.remove("open");
    overlay.classList.remove("show");
    document.body.style.overflow = "";
  }
  if(btnHamb) btnHamb.addEventListener("click", openMobile);
  if(btnCloseMobile) btnCloseMobile.addEventListener("click", closeMobile);
  overlay.addEventListener("click", closeMobile);

  /* =========================
     Clone Desktop menu into Mobile
     ========================= */
  const desktopMenu = document.querySelector("#desktopSidebar .menu");
  const mobileCloneBox = document.getElementById("mobileMenuClone");
  if(desktopMenu && mobileCloneBox){
    const clone = desktopMenu.cloneNode(true);
    clone.id = "menuMobile";
    mobileCloneBox.appendChild(clone);
  }

  /* =========================
     Submenus (both desktop + mobile)
     ========================= */
  function attachSubmenuToggles(root){
    const toggles = root.querySelectorAll(".menuToggle");
    toggles.forEach(btn=>{
      btn.addEventListener("click", ()=>{
        const id = btn.dataset.target;
        const box = root.querySelector("#"+CSS.escape(id));
        if(!box) return;
        const chev = btn.querySelector(".chev");
        const isOpen = box.classList.contains("open");
        box.classList.toggle("open");
        if(chev) chev.style.transform = isOpen ? "rotate(0deg)" : "rotate(180deg)";
      });
    });
  }
  attachSubmenuToggles(document);
  if(document.getElementById("menuMobile")) attachSubmenuToggles(document.getElementById("menuMobile"));

  /* =========================
     THE CORE: Keep dashboard shell, load pages inside iframe
     ========================= */
  const dashboardBlock = document.getElementById("dashboardBlock");
  const pageBlock = document.getElementById("pageBlock");

  const pageFrame = document.getElementById("pageFrame");
  const viewTitle = document.getElementById("viewTitle");
  const viewFile = document.getElementById("viewFile");

  const nowFile = document.getElementById("nowFile");

  let currentPageUrl = "";
  let currentPageFile = "dashboardadmin.php";
  let currentPageTitle = "Dashboard";

  function setActiveMenu(file){
    document.querySelectorAll(".menuLink").forEach(a => a.classList.remove("active"));
    document.querySelectorAll('.menuLink[data-file="'+CSS.escape(file)+'"]').forEach(a => a.classList.add("active"));
  }

  function showDashboard(){
    pageBlock.style.display = "none";
    dashboardBlock.style.display = "";
    currentPageUrl = "";
    currentPageFile = "dashboardadmin.php";
    currentPageTitle = "Dashboard";
    nowFile.textContent = currentPageFile;
    setActiveMenu("dashboardadmin.php");
    closeMobile();
  }

  function showPage(url, title, file){
    dashboardBlock.style.display = "none";
    pageBlock.style.display = "";

    currentPageUrl = url;
    currentPageTitle = title || "Page";
    currentPageFile = file || url;

    viewTitle.textContent = currentPageTitle;
    viewFile.textContent = currentPageFile;
    nowFile.textContent = currentPageFile;

    Toast.fire({ icon:"info", title: "Loading " + currentPageFile + "..." });

    pageFrame.src = url;
    setActiveMenu(currentPageFile);
    closeMobile();
  }

  pageFrame.addEventListener("load", ()=>{
    if(currentPageUrl) toastOk("Loaded: " + currentPageFile);
  });
  pageFrame.addEventListener("error", ()=>{
    swalErr("Page Load Error", "Could not load: " + (currentPageFile || "page") + ". Please check file exists and path is correct.");
  });

  function attachMenuLinkClicks(root){
    root.querySelectorAll(".menuLink").forEach(link=>{
      link.addEventListener("click", (e)=>{
        const mode = link.dataset.mode || "page";
        if(mode === "dashboard"){
          e.preventDefault();
          showDashboard();
          return;
        }
        const url = link.dataset.page || link.getAttribute("href");
        const title = link.dataset.title || "Page";
        const file = link.dataset.file || url;
        if(url){
          e.preventDefault();
          showPage(url, title, file);
        }
      });
    });
  }
  attachMenuLinkClicks(document);
  if(document.getElementById("menuMobile")) attachMenuLinkClicks(document.getElementById("menuMobile"));

  document.querySelectorAll(".menuJump").forEach(btn=>{
    btn.addEventListener("click", ()=>{
      const url = btn.dataset.page;
      const title = btn.dataset.title || "Page";
      const file = btn.dataset.file || url;
      if(url) showPage(url, title, file);
    });
  });

  document.getElementById("btnBackDash").addEventListener("click", showDashboard);

  document.getElementById("btnReloadFrame").addEventListener("click", ()=>{
    try{
      // If we're viewing the main dashboard (no page loaded in iframe), reload the top-level page
      if (!currentPageUrl) {
        window.location.reload();
        return;
      }

      // Otherwise attempt to reload the iframe content; if that's blocked, reset the src
      if (pageFrame && pageFrame.contentWindow) {
        try {
          pageFrame.contentWindow.location.reload();
          toastOk("Reloaded");
          return;
        } catch (err) {
          // fallthrough to resetting src
        }
      }

      if (currentPageUrl) {
        pageFrame.src = currentPageUrl;
        toastOk("Reloaded");
        return;
      }
    } catch (e) {
      swalErr("Reload Error", "Could not reload page. " + (e.message || ''));
    }
  });

  document.getElementById("btnOpenNewTab").addEventListener("click", ()=>{
    if(currentPageUrl) window.open(currentPageUrl, "_blank");
    else toastErr("No page opened");
  });

  /* =========================
     Theme (Dark/Light) persistent
     ========================= */
  const body = document.body;
  const btnTheme = document.getElementById("btnTheme");

  function applyTheme(mode){
    if(mode === "dark") body.classList.add("dark");
    else body.classList.remove("dark");
    localStorage.setItem("theme_mode", mode);
    updateThemeIcon();
  }

  function updateThemeIcon(){
    const isDark = body.classList.contains("dark");
    btnTheme.innerHTML = isDark ? '<i class="fa-solid fa-sun"></i>' : '<i class="fa-solid fa-moon"></i>';
    btnTheme.title = isDark ? "Light mode" : "Dark mode";
  }

  const savedTheme = localStorage.getItem("theme_mode");
  if(savedTheme === "dark" || savedTheme === "light") applyTheme(savedTheme);
  else applyTheme("light");

  btnTheme.addEventListener("click", ()=>{
    const isDark = body.classList.contains("dark");
    applyTheme(isDark ? "light" : "dark");
    toastOk(isDark ? "Light mode" : "Dark mode");
  });

  /* =========================
     Fullscreen
     ========================= */
  const btnFs = document.getElementById("btnFs");
  function fsIcon(){
    const isFs = !!document.fullscreenElement;
    btnFs.innerHTML = isFs ? '<i class="fa-solid fa-compress"></i>' : '<i class="fa-solid fa-expand"></i>';
    btnFs.title = isFs ? "Exit fullscreen" : "Fullscreen";
  }
  fsIcon();
  btnFs.addEventListener("click", async ()=>{
    try{
      if(!document.fullscreenElement){
        await document.documentElement.requestFullscreen();
      }else{
        await document.exitFullscreen();
      }
      fsIcon();
    }catch(e){
      Swal.fire({ icon:"error", title:"Fullscreen Error", text:"Your browser blocked fullscreen.", width:560, confirmButtonColor:"#2563eb" });
    }
  });
  document.addEventListener("fullscreenchange", fsIcon);

  /* =========================
     Logout confirm (SweetAlert)
     ========================= */
  document.getElementById("btnLogout").addEventListener("click", ()=>{
    Swal.fire({
      icon: "warning",
      title: "Logout?",
      text: "Do you really want to logout from Admin Dashboard?",
      showCancelButton: true,
      confirmButtonText: "Yes, Logout",
      cancelButtonText: "Cancel",
      confirmButtonColor: "#da1515ff",
      cancelButtonColor: "#53729cff",
      width: 560
    }).then((r)=>{
      if(r.isConfirmed){
        window.location.href = "logout.php";
      }
    });
  });

  /* =========================
     Menu search (UI only)
     ========================= */
  const menuSearch = document.getElementById("menuSearch");
  if(menuSearch){
    menuSearch.addEventListener("input", ()=>{
      const q = (menuSearch.value || "").toLowerCase().trim();
      const links = document.querySelectorAll("#desktopSidebar .menu a, #desktopSidebar .menu button");
      links.forEach(el=>{
        const text = (el.innerText || "").toLowerCase();
        el.style.display = (q === "" || text.includes(q)) ? "" : "none";
      });
    });
  }

  /* =========================
     Optional open page from URL query ?page=...
     ========================= */
  const params = new URLSearchParams(window.location.search);
  const qpage = params.get("page");
  if(qpage){ showPage(qpage, qpage, qpage); }
  else{ showDashboard(); }

  /* =========================
     CHARTS (3) — from PHP JSON
     ========================= */
  const monthlyLabels = <?= json_encode($chartMonthlyLabels, JSON_UNESCAPED_UNICODE) ?>;
  const monthlyValues = <?= json_encode($chartMonthlyValues, JSON_UNESCAPED_UNICODE) ?>;

  const invoiceLabels = <?= json_encode($chartInvoiceLabels, JSON_UNESCAPED_UNICODE) ?>;
  const invoiceValues = <?= json_encode($chartInvoiceValues, JSON_UNESCAPED_UNICODE) ?>;

  const enrollLabels  = <?= json_encode($chartEnrollLabels, JSON_UNESCAPED_UNICODE) ?>;
  const enrollValues  = <?= json_encode($chartEnrollValues, JSON_UNESCAPED_UNICODE) ?>;

  // Better labels if empty
  function ensureNonEmpty(labels, values, emptyLabel="No Data"){
    if(!labels || labels.length === 0){
      return { labels:[emptyLabel], values:[0] };
    }
    return { labels, values };
  }

  const r1 = ensureNonEmpty(monthlyLabels, monthlyValues, "No Payments");
  const r2 = ensureNonEmpty(invoiceLabels, invoiceValues, "No Invoices");
  const r3 = ensureNonEmpty(enrollLabels, enrollValues, "No Enrollments");

  // Global defaults (do not force colors in CSS; Chart.js uses defaults unless set here)
  Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
  Chart.defaults.plugins.legend.labels.boxWidth = 14;

  // Revenue Trend (Line)
  new Chart(document.getElementById("chartRevenue"), {
    type: "line",
    data: {
      labels: r1.labels,
      datasets: [{
        label: "Revenue",
        data: r1.values,
        tension: 0.35,
        fill: true
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        tooltip: { mode: "index", intersect: false },
        legend: { display: true }
      },
      interaction: { mode: "index", intersect: false },
      scales: {
        y: { beginAtZero: true }
      }
    }
  });

  // Invoice Status (Doughnut)
  new Chart(document.getElementById("chartInvoices"), {
    type: "doughnut",
    data: {
      labels: r2.labels,
      datasets: [{
        label: "Invoices",
        data: r2.values
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: "bottom" }
      }
    }
  });

  // Enrollment Status (Bar)
  new Chart(document.getElementById("chartEnrollments"), {
    type: "bar",
    data: {
      labels: r3.labels,
      datasets: [{
        label: "Enrollments",
        data: r3.values
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false }
      },
      scales: {
        y: { beginAtZero: true }
      }
    }
  });

  /* =========================
     Global JS errors -> console
     ========================= */
  window.addEventListener("error", (e)=>{
    console.error(e.error || e.message);
  });
</script>

</body>
</html>
