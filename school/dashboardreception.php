<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . "/conncation.php";

// Redirect/guard: ensure logged in and Reception role, then forward to reception.php
if (!isset($_SESSION['user_id'])) {
  header('Location: login.php');
  exit;
}
$role = (string)($_SESSION['role'] ?? '');
if ($role !== 'RECEPTION') {
  // if admin, send to admin dashboard; otherwise back to login
  if ($role === 'ADMIN') {
    header('Location: dashboardadmin.php');
    exit;
  }
  header('Location: login.php');
  exit;
}

// Reception user: forward to the reception page (keeps URL as reception.php)
header('Location: reception.php');
exit;
