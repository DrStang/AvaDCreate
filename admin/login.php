<?php require_once __DIR__.'/../lib/auth.php'; require_once __DIR__.'/../lib/util.php';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!csrf_ok()) die('Bad CSRF');
    if (admin_login($_POST['username']??'', $_POST['password']??'')) {
        header('Location: /admin/dashboard.php'); exit;
    }
    $err = 'Invalid credentials';
}
?>
<!doctype html><meta charset="utf-8"><title>Admin Login</title>
<form method="post" style="max-width:360px;margin:80px auto;border:1px solid #eee;padding:16px;border-radius:12px">
    <h2>Admin Login</h2>
    <?php if (!empty($err)) echo '<div style="color:red">'.h($err).'</div>'; ?>
    <input name="username" placeholder="Username" required style="width:100%;margin:8px 0;padding:10px">
    <input type="password" name="password" placeholder="Password" required style="width:100%;margin:8px 0;padding:10px">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <button type="submit" style="padding:10px 14px;background:#7a3d91;color:#fff;border:0;border-radius:8px">Sign in</button>
</form>
