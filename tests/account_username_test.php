<?php
define('ALLOWED_ACCESS', true);
define('MYSQLI_ASSOC', 1);
class SRP6 {
    static function verifyPassword($name, $password, $salt, $verifier) { return $password === 'correct'; }
    static function generateSalt() { return 'new salt'; }
    static function calculateVerifier($name, $password, $salt) { return 'new verifier'; }
}
require __DIR__ . '/../includes/account_username.php';
class Result {
    function __construct(private $rows) {}
    function fetch_all($mode) { return $this->rows; }
    function fetch_assoc() { return $this->rows[0] ?? null; }
}
class Statement {
    public $affected_rows = 1;
    function __construct(private $db, private $sql) {}
    function bind_param($types, &...$values) {}
    function execute() { if ($this->db->failSite && str_starts_with($this->sql, 'UPDATE `')) throw new RuntimeException('denied'); if ($this->db->returnFalse && str_starts_with($this->sql, 'UPDATE `')) return false; return true; }
    function close() {}
    function get_result() {
        if (str_contains($this->sql, 'information_schema')) return new Result([['ENGINE' => 'InnoDB'], ['ENGINE' => $this->db->engine]]);
        if (str_contains($this->sql, 'FROM account WHERE')) return new Result([['username'=>'OLDNAME','salt'=>'old','verifier'=>'old']]);
        return new Result([['username'=>$this->db->siteName]]);
    }
}
class FakeDb {
    public $engine = 'InnoDB', $siteName = 'OLDNAME', $failSite = false, $returnFalse = false, $begun = false, $committed = false, $rolledBack = false;
    function prepare($sql) { return new Statement($this, $sql); }
    function begin_transaction() { $this->begun = true; return true; }
    function commit() { $this->committed = true; return true; }
    function rollback() { $this->rolledBack = true; }
}
function attempt($db, $old='OLDNAME', $new='NEWNAME', $password='correct', $reserved=['OWNER','SERVICE'], $schema='site') {
    try { changeAccountUsername($db, $schema, 5, $old, $new, $password, $reserved); return true; }
    catch (Throwable $e) { return false; }
}
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$db = new FakeDb(); check(attempt($db) && $db->committed && !$db->rolledBack, 'valid rename');
$db = new FakeDb(); check(!attempt($db, reserved:['OWNER', null]) && !$db->begun, 'missing reserved account');
$db = new FakeDb(); check(!attempt($db, new:'OWNER') && !$db->begun, 'reserved name');
$db = new FakeDb(); check(!attempt($db, schema:'bad-name') && !$db->begun, 'invalid schema');
$db = new FakeDb(); $db->engine='MyISAM'; check(!attempt($db) && !$db->begun, 'nontransactional table');
$db = new FakeDb(); check(!attempt($db, password:'wrong') && $db->rolledBack && !$db->committed, 'wrong password rolls back');
$db = new FakeDb(); $db->siteName='DIFFERENT'; check(!attempt($db) && $db->rolledBack && !$db->committed, 'drift rolls back');
$db = new FakeDb(); $db->failSite=true; check(!attempt($db) && $db->rolledBack && !$db->committed, 'site write failure rolls back');
$db = new FakeDb(); $db->returnFalse=true; check(!attempt($db) && $db->rolledBack && !$db->committed, 'false site write rolls back');
echo "account username checks passed\n";
