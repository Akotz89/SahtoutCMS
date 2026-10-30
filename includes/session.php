<?php
// Detect HTTPS properly (works with proxies & Cloudflare)
$is_https = false;
if (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
) {
    $is_https = true;
}

// Force correct session cookie params
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',        // current domain
    'secure' => $is_https,
    'httponly' => true,
    'samesite' => 'Lax'
]);

ini_set('session.use_only_cookies', 1);

session_start();

require_once __DIR__ . '/paths.php';
// Include database configuration
require_once $project_root . 'includes/config.php';
function credentialFingerprint(string $salt, string $verifier): string {
    return hash('sha256', pack('N', strlen($salt)) . $salt . $verifier);
}
function sessionCredentialsMatch(array $account): bool {
    $fingerprint = $_SESSION['credential_fingerprint'] ?? null;
    return is_string($fingerprint) && is_string($account['salt'] ?? null)
        && is_string($account['verifier'] ?? null)
        && hash_equals($fingerprint, credentialFingerprint($account['salt'], $account['verifier']));
}
$operator_owner_username = $operator_owner_username ?? (getenv('CMS_OWNER_USERNAME') ?: '');
$operator_service_username = $operator_service_username ?? (getenv('CMS_SERVICE_USERNAME') ?: '');

// Initialize debug errors
$_SESSION['debug_errors'] = $_SESSION['debug_errors'] ?? [];

// Regenerate session ID periodically to prevent session fixation
if (!isset($_SESSION['last_regeneration']) || (time() - $_SESSION['last_regeneration']) > 1800) { // Every 30 minutes
    session_regenerate_id(true);
    $_SESSION['last_regeneration'] = time();
}

// Generate CSRF token if not already set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Define protected and admin pages
$protected_pages = ['account.php', 'buy_item.php'];
$admin_pages = ['resources.php', 'dashboard.php', 'users.php', 'anews.php','characters.php','ashop.php','gm_cmd.php', 'logout.php','save_general.php'];
$current_page = basename($_SERVER['PHP_SELF']);

// Validate credentials on every request, including pages available to guests.
if (!empty($_SESSION['user_id']) || !empty($_SESSION['username'])) {
    $valid_credentials = false;
    if (!empty($_SESSION['user_id']) && !empty($_SESSION['username'])) {
        $stmt = $auth_db->prepare("SELECT id, salt, verifier FROM account WHERE id = ? AND username = ?");
        $stmt->bind_param('is', $_SESSION['user_id'], $_SESSION['username']);
        $stmt->execute();
        $result = $stmt->get_result();
        $valid_credentials = $result->num_rows === 1 && sessionCredentialsMatch($result->fetch_assoc());
        $stmt->close();
    }
    if (!$valid_credentials) {
        session_unset();
        session_regenerate_id(true);
        $_SESSION['debug_errors'] = [];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['last_regeneration'] = time();
    }
}

// Validate session for protected and admin pages
if (in_array($current_page, $protected_pages) || in_array($current_page, $admin_pages)) {
    if (empty($_SESSION['user_id']) || empty($_SESSION['username'])) {
        $_SESSION['debug_errors'] = array_diff($_SESSION['debug_errors'], ["No user session detected. Ensure login script sets \$_SESSION['user_id'] and \$_SESSION['username']."]);
        $_SESSION['debug_errors'][] = "No user session detected. Ensure login script sets \$_SESSION['user_id'] and \$_SESSION['username'].";
        session_unset();
        session_destroy();
        header('Location: ' . $base_path . 'login?error=invalid_session');
        exit();
    }

    // Load user role
    /** @var \mysqli_stmt|false $stmt */
    $stmt = $site_db->prepare("SELECT role FROM user_currencies WHERE account_id = ?");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $_SESSION['role'] = $result->num_rows === 1 ? $result->fetch_assoc()['role'] : 'player';
    $stmt->close();

    // Load GM level
    /** @var \mysqli_stmt|false $stmt */
    $stmt = $auth_db->prepare("SELECT gmlevel FROM account_access WHERE id = ?");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $_SESSION['gmlevel'] = $result->num_rows > 0 ? $result->fetch_assoc()['gmlevel'] : 0;
    $stmt->close();

    // Restrict admin pages to admin or moderator
    if (in_array($current_page, $admin_pages) && !in_array($_SESSION['role'], ['admin', 'moderator'])) {
        $_SESSION['debug_errors'] = ["Unauthorized access to admin page."];
        header('Location: ' . $base_path . 'login?error=unauthorized');
        exit();
    }
}

// For login, register, and activate pages, redirect to account if already logged in
$public_pages = ['login.php', 'register.php', 'activate.php','forgot_password.php', 'reset_password.php','resend_activation.php'];
if (in_array($current_page, $public_pages) && !empty($_SESSION['user_id']) && !empty($_SESSION['username'])) {
    $role_stmt = $site_db->prepare("SELECT role FROM user_currencies WHERE account_id = ? LIMIT 1");
    $role_stmt->bind_param('i', $_SESSION['user_id']);
    $role_stmt->execute();
    $role_result = $role_stmt->get_result();
    $_SESSION['role'] = $role_result->num_rows === 1 ? $role_result->fetch_assoc()['role'] : 'player';
    $role_stmt->close();
    $destination = $_SESSION['role'] === 'admin' ? 'admin/users' : 'account';
    header('Location: ' . $base_path . $destination);
    exit();
}
?>
