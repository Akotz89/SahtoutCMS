<?php
// Exercise the account entrypoint and next-request session validation with isolated DB stubs.
$root = sys_get_temp_dir() . '/cms-self-service-' . bin2hex(random_bytes(6));
foreach (['pages', 'includes', 'languages', 'sessions'] as $dir) mkdir("$root/$dir", 0700, true);
foreach (['pages/account.php', 'includes/session.php', 'includes/account_username.php'] as $file) copy(__DIR__ . '/../' . $file, "$root/$file");
file_put_contents("$root/includes/paths.php", '<?php $project_root = dirname(__DIR__) . "/"; $base_path = "/";');
file_put_contents("$root/includes/config.settings.php", '<?php $operator_owner_username = "OWNER"; $operator_service_username = "SERVICE";');
file_put_contents("$root/includes/config.mail.php", '<?php $smtp_enabled = false;');
file_put_contents("$root/languages/language.php", '<?php function translate($key, $fallback) { return $fallback; }');
file_put_contents("$root/includes/srp6.php", <<<'STUB'
<?php
class SRP6 {
    static function VerifyPassword($username, $password, $salt, $verifier) { return $password === 'oldpass' && $salt === 'old-salt' && $verifier === 'old-verifier'; }
    static function GenerateSalt() { return 'new-salt'; }
    static function CalculateVerifier($username, $password, $salt) { return 'new-verifier'; }
}
STUB);
file_put_contents("$root/includes/config.php", <<<'STUB'
<?php
if (!defined('MYSQLI_ASSOC')) define('MYSQLI_ASSOC', 1);
$db_auth_host = $db_site_host = 'localhost'; $db_auth_port = $db_site_port = 3306; $db_site_name = 'site';
class FakeResult {
    public $num_rows;
    function __construct(private array $rows) { $this->num_rows = count($rows); }
    function fetch_assoc() { return $this->rows[0] ?? null; }
    function fetch_all($mode) { return $this->rows; }
}
class FakeStatement {
    public $affected_rows = 1; private array $args = [];
    function __construct(private string $sql) {}
    function bind_param($types, &...$values) { $this->args = &$values; }
    function execute() {
        $state = json_decode(file_get_contents(__DIR__ . '/../state.json'), true);
        if (getenv('FAIL_WRITE') && str_starts_with($this->sql, 'UPDATE account SET')) return false;
        if (getenv('FAIL_AUDIT') && str_starts_with($this->sql, 'INSERT INTO website_activity_log')) throw new RuntimeException('audit unavailable');
        if (str_starts_with($this->sql, 'UPDATE account SET username')) {
            [$state['username'], $state['salt'], $state['verifier']] = $this->args;
        } elseif (str_starts_with($this->sql, 'UPDATE account SET salt')) {
            if ($state['username'] !== $this->args[3] || $state['salt'] !== $this->args[4] || $state['verifier'] !== $this->args[5]) { $this->affected_rows = 0; return true; }
            [$state['salt'], $state['verifier']] = $this->args;
        } elseif (str_starts_with($this->sql, 'UPDATE `site`.user_currencies')) {
            $state['site_username'] = $this->args[0];
        }
        file_put_contents(__DIR__ . '/../state.json', json_encode($state));
        return true;
    }
    function get_result() {
        $state = json_decode(file_get_contents(__DIR__ . '/../state.json'), true);
        if (str_contains($this->sql, 'information_schema')) return new FakeResult([['ENGINE'=>'InnoDB'], ['ENGINE'=>'InnoDB']]);
        if (str_contains($this->sql, 'SELECT role')) return new FakeResult([['role'=>'player']]);
        if (str_contains($this->sql, 'SELECT gmlevel')) return new FakeResult([]);
        if (str_contains($this->sql, 'FROM `site`.user_currencies')) return new FakeResult([['username'=>$state['site_username']]]);
        if (str_contains($this->sql, 'WHERE id = ? AND username = ?') && $this->args[1] !== $state['username']) return new FakeResult([]);
        return new FakeResult([['id'=>5, 'username'=>$state['username'], 'salt'=>$state['salt'], 'verifier'=>$state['verifier'], 'email'=>'']]);
    }
    function close() {}
}
class FakeDb {
    public $connect_error = false;
    function prepare($sql) { return new FakeStatement($sql); }
    function begin_transaction() { return true; }
    function commit() { return true; }
    function rollback() { return true; }
}
$auth_db = new FakeDb; $site_db = new FakeDb; $char_db = new FakeDb;
STUB);
file_put_contents("$root/run.php", <<<'STUB'
<?php
session_save_path(__DIR__ . '/sessions');
session_id(getenv('SESSION_ID') ?: 'selfsession');
if (getenv('MODE') === 'seed') {
    session_start(); $_SESSION = ['user_id'=>5, 'username'=>'OLDNAME', 'csrf_token'=>'test-csrf', 'credential_fingerprint'=>hash('sha256', pack('N', 8) . 'old-salt' . 'old-verifier'), 'last_regeneration'=>time()]; session_write_close(); exit;
}
$_SERVER['PHP_SELF'] = '/account.php'; $_SERVER['REQUEST_METHOD'] = getenv('MODE') === 'change' ? 'POST' : 'GET';
if (getenv('MODE') === 'change') {
    $_POST = ['csrf_token'=>'test-csrf', 'current_password'=>'oldpass'];
    if (getenv('ACTION') === 'rename') $_POST += ['change_username'=>'1', 'new_username'=>'NEWNAME'];
    else $_POST += ['change_password'=>'1', 'new_password'=>'newpass123', 'confirm_password'=>'newpass123'];
}
register_shutdown_function(function () {
    $result = ['session_id'=>session_id(), 'user'=>$_SESSION['username'] ?? null, 'message'=>$_SESSION['message'] ?? null, 'error'=>$_SESSION['error'] ?? null];
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    echo json_encode($result);
});
if (getenv('MODE') === 'change') require __DIR__ . '/pages/account.php';
else require __DIR__ . '/includes/session.php';
STUB);
function request($root, $env) {
    $proc = proc_open([PHP_BINARY, "$root/run.php"], [1=>['pipe','w'], 2=>['pipe','w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $status = proc_close($proc);
    if ($status !== 0) throw new RuntimeException("fixture process failed: $err $out");
    return $out === '' ? [] : json_decode($out, true, 512, JSON_THROW_ON_ERROR);
}
foreach (['password', 'rename'] as $action) {
    file_put_contents("$root/state.json", json_encode(['username'=>'OLDNAME', 'site_username'=>'OLDNAME', 'salt'=>'old-salt', 'verifier'=>'old-verifier']));
    request($root, ['MODE'=>'seed', 'SESSION_ID'=>'selfsession']);
    request($root, ['MODE'=>'seed', 'SESSION_ID'=>'othersession']);
    $changed = request($root, ['MODE'=>'change', 'ACTION'=>$action, 'SESSION_ID'=>'selfsession']);
    if (!$changed['message'] || $changed['error'] || $changed['session_id'] === 'selfsession') throw new RuntimeException("$action change did not succeed and rotate session");
    $self = request($root, ['MODE'=>'verify', 'SESSION_ID'=>$changed['session_id']]);
    $other = request($root, ['MODE'=>'verify', 'SESSION_ID'=>'othersession']);
    $expectedName = $action === 'rename' ? 'NEWNAME' : 'OLDNAME';
    if ($self['user'] !== $expectedName || $self['message'] !== $changed['message']) throw new RuntimeException("$action did not retain current success session");
    if ($other['user'] !== null) throw new RuntimeException("$action did not revoke other session");
    file_put_contents("$root/state.json", json_encode(['username'=>'OLDNAME', 'site_username'=>'OLDNAME', 'salt'=>'old-salt', 'verifier'=>'old-verifier']));
    request($root, ['MODE'=>'seed', 'SESSION_ID'=>'selfsession']);
    request($root, ['MODE'=>'seed', 'SESSION_ID'=>'othersession']);
    $failed = request($root, ['MODE'=>'change', 'ACTION'=>$action, 'SESSION_ID'=>'selfsession', 'FAIL_WRITE'=>'1']);
    if (!$failed['error'] || $failed['message']) throw new RuntimeException("$action failure was not reported");
    $self = request($root, ['MODE'=>'verify', 'SESSION_ID'=>$failed['session_id']]);
    $other = request($root, ['MODE'=>'verify', 'SESSION_ID'=>'othersession']);
    if ($self['user'] !== 'OLDNAME' || $other['user'] !== 'OLDNAME') throw new RuntimeException("$action failure revoked a valid session");
    file_put_contents("$root/state.json", json_encode(['username'=>'OLDNAME', 'site_username'=>'OLDNAME', 'salt'=>'old-salt', 'verifier'=>'old-verifier']));
    request($root, ['MODE'=>'seed', 'SESSION_ID'=>'selfsession']);
    $changed = request($root, ['MODE'=>'change', 'ACTION'=>$action, 'SESSION_ID'=>'selfsession', 'FAIL_AUDIT'=>'1']);
    $self = request($root, ['MODE'=>'verify', 'SESSION_ID'=>$changed['session_id']]);
    if (!$changed['message'] || $changed['error'] || $self['user'] !== $expectedName || $self['message'] !== $changed['message']) throw new RuntimeException("$action audit failure hid successful change");
}
foreach (glob("$root/sessions/*") as $path) unlink($path);
foreach (glob("$root/includes/*") as $path) unlink($path);
unlink("$root/languages/language.php"); unlink("$root/pages/account.php"); unlink("$root/state.json"); unlink("$root/run.php");
rmdir("$root/sessions"); rmdir("$root/includes"); rmdir("$root/languages"); rmdir("$root/pages"); rmdir($root);
echo "self-service session checks passed\n";
