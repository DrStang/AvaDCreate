<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/util.php';

function admin_login(string $username, string $password): bool {
    $stmt = db()->prepare('SELECT * FROM admin_users WHERE username = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$username]);
    $u = $stmt->fetch();
    if ($u && password_verify($password, $u['password_hash'])) {
        $_SESSION['admin_id'] = (int)$u['id'];
        $_SESSION['admin_username'] = $u['username'];
        db()->prepare('UPDATE admin_users SET last_login = NOW() WHERE id=?')->execute([$u['id']]);
        return true;
    }
    return false;
}
function admin_required(): void {
    if (empty($_SESSION['admin_id'])) {
        header('Location: /admin/login.php'); exit;
    }
}
function admin_logout(): void {
    session_destroy();
    header('Location: /admin/login.php'); exit;
}
