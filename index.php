<?php
declare(strict_types=1);
define('ALLOWED_ACCESS', true);
require_once __DIR__ . '/includes/paths.php';
require_once $project_root . 'includes/session.php';
$destination = empty($_SESSION['user_id']) ? 'login' : (($_SESSION['role'] ?? '') === 'admin' ? 'admin/users' : 'account');
header('Location: ' . $base_path . $destination, true, 303);
exit;
