<?php
// public/superadmin/download_backup.php - Secure Backup Download
session_start();
require_once __DIR__ . '/../../vendor/autoload.php';

// Check authentication
if (!isset($_SESSION['super_admin_logged_in']) || $_SESSION['super_admin_logged_in'] !== true) {
    http_response_code(401);
    die('Authentication required');
}

$filename = $_GET['file'] ?? '';
if (empty($filename) || !preg_match('/^backup_[a-zA-Z0-9_-]+\.zip$/', $filename)) {
    http_response_code(400);
    die('Invalid filename');
}

$backupPath = __DIR__ . '/../../backups/' . $filename;
if (!file_exists($backupPath) || !is_readable($backupPath)) {
    http_response_code(404);
    die('File not found');
}

// Set headers for download
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($backupPath));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Expires: 0');

// Output file
readfile($backupPath);

// Log the download
use Bot\SuperAdminManager;
$manager = new SuperAdminManager();
$manager->logAction('Backup Downloaded', "Downloaded backup file: {$filename}");

exit;
