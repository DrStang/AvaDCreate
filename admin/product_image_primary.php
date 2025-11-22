<?php
require_once __DIR__.'/../lib/auth.php'; admin_required();
require_once __DIR__.'/../lib/db.php';

$id  = (int)($_GET['id'] ?? 0);
$pid = (int)($_GET['pid'] ?? 0);
if ($id && $pid) {
    db()->prepare("UPDATE product_images SET is_primary=0 WHERE product_id=?")->execute([$pid]);
    db()->prepare("UPDATE product_images SET is_primary=1 WHERE id=? AND product_id=?")->execute([$id, $pid]);

    // also sync to products.image_url
    $row = db()->prepare("SELECT image_url FROM product_images WHERE id=? AND product_id=?");
    $row->execute([$id,$pid]); $img = $row->fetch();
    if ($img) db()->prepare("UPDATE products SET image_url=? WHERE id=?")->execute([$img['image_url'], $pid]);
}
header('Location: /admin/product_edit.php?id='.$pid);
