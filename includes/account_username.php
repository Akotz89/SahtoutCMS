<?php
if (!defined('ALLOWED_ACCESS')) exit('Direct access not allowed.');

function changeAccountUsername($db, string $siteName, int $id, string $oldName, string $newName, string $password, array $reserved): array
{
    if (count($reserved) !== 2 || !is_string($reserved[0]) || !is_string($reserved[1]) || trim($reserved[0]) === '' || trim($reserved[1]) === '') {
        throw new RuntimeException('Account rename is not configured.');
    }
    $oldName = strtoupper($oldName);
    $newName = strtoupper(trim($newName));
    $reserved = array_map(fn($name) => strtoupper(trim($name)), $reserved);
    if (!preg_match('/^[A-Z0-9]{3,16}$/D', $newName) || in_array($oldName, $reserved, true) || in_array($newName, $reserved, true) || $oldName === $newName || $password === '') {
        throw new InvalidArgumentException('Invalid username or password.');
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/D', $siteName)) {
        throw new RuntimeException('Account rename is not configured.');
    }

    $engines = $db->prepare("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE (TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'account') OR (TABLE_SCHEMA = ? AND TABLE_NAME = 'user_currencies')");
    $engines->bind_param('s', $siteName);
    if (!$engines->execute()) throw new RuntimeException('Account rename requires transactional tables.');
    $rows = $engines->get_result()->fetch_all(MYSQLI_ASSOC);
    $engines->close();
    if (count($rows) !== 2 || array_filter($rows, fn($row) => strtoupper($row['ENGINE'] ?? '') !== 'INNODB')) {
        throw new RuntimeException('Account rename requires transactional tables.');
    }

    if (!$db->begin_transaction()) throw new RuntimeException('Unable to start account transaction.');
    try {
        $stmt = $db->prepare('SELECT username, salt, verifier FROM account WHERE id = ? FOR UPDATE');
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) throw new RuntimeException('Account lookup failed.');
        $account = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$account || strtoupper($account['username']) !== $oldName || !SRP6::verifyPassword($account['username'], $password, $account['salt'], $account['verifier'])) {
            throw new InvalidArgumentException('Invalid username or password.');
        }
        $stmt = $db->prepare("SELECT username FROM `{$siteName}`.user_currencies WHERE account_id = ? FOR UPDATE");
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) throw new RuntimeException('Site account lookup failed.');
        $site = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$site || strtoupper($site['username']) !== $oldName) {
            throw new RuntimeException('Account records are not synchronized.');
        }
        $salt = SRP6::generateSalt();
        $verifier = SRP6::calculateVerifier($newName, $password, $salt);
        $stmt = $db->prepare('UPDATE account SET username = ?, salt = ?, verifier = ? WHERE id = ?');
        $stmt->bind_param('sssi', $newName, $salt, $verifier, $id);
        if (!$stmt->execute()) throw new RuntimeException('Account update failed.');
        if ($stmt->affected_rows !== 1) throw new RuntimeException('Account update failed.');
        $stmt->close();
        $stmt = $db->prepare("UPDATE `{$siteName}`.user_currencies SET username = ? WHERE account_id = ?");
        $stmt->bind_param('si', $newName, $id);
        if (!$stmt->execute()) throw new RuntimeException('Site account update failed.');
        $stmt->close();
        if (!$db->commit()) throw new RuntimeException('Account transaction failed.');
        return [$salt, $verifier];
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}
