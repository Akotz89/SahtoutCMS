<?php
$root = sys_get_temp_dir() . '/cms-session-' . bin2hex(random_bytes(6));
mkdir($root, 0700); mkdir("$root/includes", 0700); mkdir("$root/sessions", 0700);
copy(__DIR__ . '/../includes/session.php', "$root/includes/session.php");
file_put_contents("$root/includes/paths.php", '<?php $project_root = __DIR__ . "/../"; $base_path = "/";');
file_put_contents("$root/includes/config.php", <<<'STUB'
<?php
class Result {
    public $num_rows = 1;
    function __construct(private array $row) {}
    function fetch_assoc() { return $this->row; }
}
class Statement {
    function __construct(private string $sql) {}
    function bind_param($types, &...$args) {}
    function execute() { return true; }
    function close() {}
    function get_result() {
        if (str_contains($this->sql, 'salt, verifier')) return new Result(['salt' => 'salt', 'verifier' => getenv('VERIFIER') ?: 'old']);
        if (str_contains($this->sql, 'role')) return new Result(['role' => 'player']);
        return new Result(['gmlevel' => 0]);
    }
}
class Database { function prepare($sql) { return new Statement($sql); } }
$auth_db = new Database; $site_db = new Database;
STUB);
file_put_contents("$root/run.php", <<<'STUB'
<?php
session_save_path(__DIR__ . '/sessions');
session_id('testsession'); session_start();
$_SESSION = ['user_id' => 5, 'username' => 'PLAYER', 'credential_fingerprint' => hash('sha256', pack('N', 4) . 'salt' . 'old')];
if (getenv('LEGACY')) unset($_SESSION['credential_fingerprint']);
if (getenv('PARTIAL')) unset($_SESSION['username']);
session_write_close();
$_SERVER['PHP_SELF'] = getenv('PAGE') ?: '/vote.php';
register_shutdown_function(fn() => print('USER:' . (isset($_SESSION['user_id']) ? 'yes' : 'no')));
require __DIR__ . '/includes/session.php';
STUB);
foreach ([
    ['valid session', [], 'USER:yes'],
    ['password reset revokes guest-accessible page', ['VERIFIER' => 'new'], 'USER:no'],
    ['password reset revokes protected page', ['VERIFIER' => 'new', 'PAGE' => '/account.php'], 'USER:no'],
    ['legacy session revoked', ['LEGACY' => '1'], 'USER:no'],
    ['partial session revoked', ['PARTIAL' => '1'], 'USER:no'],
] as [$label, $env, $expected]) {
    $proc = proc_open([PHP_BINARY, "$root/run.php"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $status = proc_close($proc);
    if ($status !== 0 || !str_contains($out, $expected)) throw new RuntimeException("$label failed: $err $out");
}
foreach (glob("$root/sessions/*") as $path) unlink($path);
foreach (glob("$root/includes/*") as $path) unlink($path);
unlink("$root/run.php"); rmdir("$root/sessions"); rmdir("$root/includes"); rmdir($root);
echo "session credential checks passed\n";
