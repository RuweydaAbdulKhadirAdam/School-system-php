<?php
// conncation.php  (DB Connection + helpers)

declare(strict_types=1);

$DB_HOST = "localhost";
$DB_USER = "root";
$DB_PASS = "";           
$DB_NAME = "school_system_db";
$DB_PORT = 3306;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
  $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
  $conn->set_charset("utf8mb4");
} catch (Throwable $e) {
  // Ha muujin error faahfaahin production
  die("Database connection failed.");
}

/**
 * Clean output (XSS protection)
 */
function e(string $str): string {
  return htmlspecialchars($str, ENT_QUOTES, "UTF-8");
}
