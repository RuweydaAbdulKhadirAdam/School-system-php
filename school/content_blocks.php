<?php
// content_blocks.php
// Helper + simple API for editable content blocks (Main Content Area)
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . "/conncation.php";

function cb_ensure_table(mysqli $conn): void {
  $sql = "CREATE TABLE IF NOT EXISTS content_blocks (
    block_key VARCHAR(120) NOT NULL PRIMARY KEY,
    content TEXT,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
  $conn->query($sql);
}

function cb_get(mysqli $conn, string $key): string {
  cb_ensure_table($conn);
  $stmt = $conn->prepare("SELECT content FROM content_blocks WHERE block_key = ? LIMIT 1");
  $stmt->bind_param('s', $key);
  $stmt->execute();
  $res = $stmt->get_result();
  $out = '';
  if ($row = $res->fetch_assoc()) $out = (string)$row['content'];
  $stmt->close();
  return $out;
}

function cb_save(mysqli $conn, string $key, string $content): bool {
  cb_ensure_table($conn);
  // upsert
  $stmt = $conn->prepare("INSERT INTO content_blocks (block_key, content) VALUES (?, ?) ON DUPLICATE KEY UPDATE content = VALUES(content), updated_at = CURRENT_TIMESTAMP");
  $stmt->bind_param('ss', $key, $content);
  $ok = $stmt->execute();
  $stmt->close();
  return (bool)$ok;
}

// Simple API endpoint: POST {action:save, key, content}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'save_block' || ($_POST['action'] ?? '') === 'save_block_ajax')) {
  // require admin
  $isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'ADMIN';
  if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'msg'=>'Unauthorized']);
    exit;
  }

  $key = (string)($_POST['key'] ?? '');
  $content = (string)($_POST['content'] ?? '');
  if ($key === '') {
    echo json_encode(['ok'=>false,'msg'=>'Missing key']);
    exit;
  }

  $saved = false;
  try {
    $saved = cb_save($conn, $key, $content);
  } catch (Throwable $e) {
    echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    exit;
  }

  if (isset($_POST['action']) && $_POST['action'] === 'save_block') {
    // redirect back if non-AJAX
    $back = $_POST['back'] ?? 'users_roles.php';
    header('Location: ' . $back . '?type=' . ($saved ? 'success' : 'error') . '&msg=' . urlencode($saved ? 'Saved' : 'Save failed'));
    exit;
  }

  header('Content-Type: application/json');
  echo json_encode(['ok'=>$saved]);
  exit;
}

// If included normally, just provide functions.
return;