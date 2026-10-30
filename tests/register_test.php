<?php
// Exercise the real registration entrypoint against isolated database stubs.
$root = sys_get_temp_dir() . '/cms-register-' . bin2hex(random_bytes(6));
foreach (['pages', 'includes', 'languages'] as $dir) mkdir("$root/$dir", 0700, true);
copy(__DIR__ . '/../pages/register.php', "$root/pages/register.php");
file_put_contents("$root/includes/paths.php", '<?php $project_root = dirname(__DIR__) . "/"; $base_path = "/"; $site_url = "http://localhost/";');
file_put_contents("$root/includes/session.php", <<<'STUB'
<?php
$_SESSION = ['csrf_token' => 'test-csrf'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['csrf_token' => getenv('BAD_CSRF') ? 'wrong' : 'test-csrf', 'username' => getenv('USERNAME') ?: 'PLAYER1', 'password' => getenv('SHORT') ? 'short' : 'password1', 'confirm_password' => getenv('SHORT') ? 'short' : 'password1', 'email' => getenv('EMAIL') ?: ''];
$operator_owner_username = getenv('MISSING_SETTINGS') ? '' : 'OWNER'; $operator_service_username = 'SERVICE';
class Result { public $num_rows = 0; }
class Statement { public function __construct(private string $sql) {} public function bind_param($types, &...$values) {} public function execute() { if (str_starts_with($this->sql, 'INSERT')) $GLOBALS['inserted']++; return true; } public function get_result() { return new Result; } public function close() {} }
class Database { public $connect_error = false; public function prepare($sql) { return new Statement($sql); } }
$inserted = 0; $site_db = new Database; $auth_db = new Database;
STUB);
file_put_contents("$root/languages/language.php", '<?php function translate($key, $fallback) { return $fallback; }');
file_put_contents("$root/includes/config.settings.php", '<?php $site_title_name = "Test";');
file_put_contents("$root/includes/config.cap.php", '<?php');
file_put_contents("$root/includes/config.mail.php", '<?php $smtp_enabled = getenv("SMTP") === "1";');
file_put_contents("$root/includes/srp6.php", '<?php class SRP6 { static function GenerateSalt() { return "salt"; } static function CalculateVerifier($u,$p,$s) { return "verifier"; } }');
file_put_contents("$root/includes/header.php", '<?php');
file_put_contents("$root/includes/footer.php", '<?php');
file_put_contents("$root/run.php", '<?php ob_start(); require __DIR__ . "/pages/register.php"; ob_end_clean(); echo json_encode(["errors" => $errors, "success" => $success, "inserted" => $inserted]);');
$cases = [
    ['smtp off accepts empty email', [], true],
    ['smtp on requires email', ['SMTP' => '1'], false],
    ['invalid email rejected', ['EMAIL' => 'invalid'], false],
    ['short password rejected', ['SHORT' => '1'], false],
    ['invalid csrf rejected', ['BAD_CSRF' => '1'], false],
    ['reserved name rejected', ['USERNAME' => 'owner'], false],
    ['missing protections fail closed', ['MISSING_SETTINGS' => '1'], false],
];
foreach ($cases as [$label, $env, $shouldInsert]) {
    $process = proc_open([PHP_BINARY, "$root/run.php"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $status = proc_close($process);
    $result = json_decode($out, true);
    if ($status !== 0 || !is_array($result) || ($result['inserted'] === 1) !== $shouldInsert || (!$shouldInsert && !$result['errors'])) {
        throw new RuntimeException("$label failed: $err $out");
    }
}
foreach (glob("$root/includes/*") as $path) unlink($path);
unlink("$root/languages/language.php"); unlink("$root/pages/register.php"); unlink("$root/run.php");
rmdir("$root/includes"); rmdir("$root/languages"); rmdir("$root/pages"); rmdir($root);
echo "registration checks passed\n";
