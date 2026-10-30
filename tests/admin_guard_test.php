<?php
// Run the actual admin entrypoint with isolated session and database stubs.
$root = sys_get_temp_dir() . '/cms-admin-' . bin2hex(random_bytes(6));
foreach (['pages/admin', 'includes', 'languages'] as $dir) mkdir("$root/$dir", 0700, true);
copy(__DIR__ . '/../pages/admin/users.php', "$root/pages/admin/users.php");
file_put_contents("$root/includes/paths.php", '<?php $project_root = dirname(__DIR__) . "/"; $base_path = "/";');
file_put_contents("$root/includes/session.php", <<<'STUB'
<?php
$_SESSION = ['user_id' => 1, 'username' => 'ADMIN', 'csrf_token' => 'test-csrf'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['action' => 'update', 'csrf_token' => 'test-csrf', 'account_id' => 7];
if (getenv('DUPLICATE')) $_POST = ['action' => 'create_account', 'csrf_token' => 'test-csrf', 'username' => 'PLAYER1', 'email' => '', 'password' => 'password1'];
$db_auth_name = 'auth'; $db_char_name = 'chars'; $db_world_name = 'world'; $db_site_name = 'site';
if (!class_exists('mysqli_sql_exception')) { class mysqli_sql_exception extends Exception {} }
class Result { public $num_rows = 1; function __construct(private $row, $count = 1) { $this->num_rows = $count; } function fetch_assoc() { return $this->row; } }
class Statement { function __construct(private $query) {} function bind_param($types, &...$args) {} function execute() { if (getenv('DUPLICATE') && str_starts_with($this->query, 'INSERT INTO')) throw new mysqli_sql_exception('duplicate', 1062); return true; } function close() {} function get_result() { if (getenv('DUPLICATE') && str_contains($this->query, 'SELECT id FROM')) return new Result([], 0); return new Result(str_contains($this->query, 'SELECT role') ? ['role' => getenv('ROLE') ?: 'admin'] : ['username' => getenv('TARGET') ?: 'SERVICE']); } }
class Database { function prepare($query) { if (getenv('DUPLICATE') && str_starts_with($query, 'SELECT COUNT')) { echo $GLOBALS['update_message']; exit; } return new Statement($query); } }
$site_db = new Database; $auth_db = new Database; $char_db = new Database;
register_shutdown_function(fn() => print('CODE:' . http_response_code()));
STUB);
file_put_contents("$root/includes/srp6.php", '<?php class SRP6 { static function GenerateSalt() { return "salt"; } static function CalculateVerifier($u,$p,$s) { return "verifier"; } }');
file_put_contents("$root/includes/config.settings.php", '<?php if (!getenv("MISSING")) { $operator_owner_username = "OWNER"; $operator_service_username = "SERVICE"; }');
file_put_contents("$root/languages/language.php", '<?php function translate($key, $fallback) { return $fallback; }');
$cases = [
    ['moderator denied', ['ROLE' => 'moderator'], 403],
    ['missing account protections fail closed', ['MISSING' => '1'], 503],
    ['service account protected', [], 403],
    ['owner account protected', ['TARGET' => 'OWNER'], 403],
    ['duplicate account handled', ['DUPLICATE' => '1'], 'That username or email is already in use.'],
];
foreach ($cases as [$label, $env, $expected]) {
    $proc = proc_open([PHP_BINARY, "$root/pages/admin/users.php"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $status = proc_close($proc);
    $needle = is_int($expected) ? 'CODE:' . $expected : $expected;
    if ($status !== 0 || !str_contains($out, $needle)) throw new RuntimeException("$label failed: $err $out");
}
foreach (glob("$root/includes/*") as $path) unlink($path);
unlink("$root/languages/language.php"); unlink("$root/pages/admin/users.php");
rmdir("$root/includes"); rmdir("$root/languages"); rmdir("$root/pages/admin"); rmdir("$root/pages"); rmdir($root);
echo "admin guard checks passed\n";
