<?php
declare(strict_types=1);

define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/includes/paths.php';
require_once $project_root . 'includes/session.php';

// This SahtoutCMS deployment is an operator console, not a public website.
$destination = !empty($_SESSION['user_id']) ? 'admin/users' : 'login';
header('Location: ' . $base_path . $destination, true, 303);
exit;
