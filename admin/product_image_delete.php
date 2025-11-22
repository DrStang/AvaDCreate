<?php
require_once __DIR__.'/../lib/auth.php'; admin_required();
require_once __DIR__.'/../lib/db.php';

$id  = (int)($_GET['id'] ?? 0);
$pid = (int)($_GET['pid'] ?? 0);
if ($id && $pid) {
    // if deleting current primary, we’ll switch primary to next image (if any)
    $row = db()->prepare("SELECT is_primary FROM product_images WHERE id=? AND product_id=?");
    $row->execute([$id,$pid]); $img = $row->fetch();

    db()->prepare("DELETE FROM product_images WHERE id=? AND product_id=?")->execute([$id,$pid]);

    if (!empty($img['is_primary'])) {
        $r = db()->prepare("SELECT image_url FROM product_images WHERE product_id=? ORDER BY sort_order ASC, id ASC LIMIT 1");
        $r->execute([$pid]); $next = $r->fetch();
        if ($next) {
            db()->prepare("UPDATE product_images SET is_primary=1 WHERE product_id=? AND image_url=?")->execute([$pid,$next['image_url']]);
            db()->prepare("UPDATE products SET image_url=? WHERE id=?")->execute([$next['image_url'],$pid]);
        } else {
            // no gallery left; keep products.image_url as-is (or null it if you prefer)
        }
    }
}
header('Location: /admin/product_edit.php?id='.$pid);
