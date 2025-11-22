<?php require_once __DIR__.'/../lib/auth.php'; admin_required(); require_once __DIR__.'/../lib/db.php';
$id = (int)($_GET['id'] ?? 0);
if ($id) db()->prepare("DELETE FROM products WHERE id=?")->execute([$id]);
header('Location: /admin/products.php'); exit;
