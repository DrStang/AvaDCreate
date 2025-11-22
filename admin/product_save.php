<?php
require_once __DIR__.'/../lib/auth.php'; admin_required();
require_once __DIR__.'/../lib/db.php'; require_once __DIR__.'/../lib/util.php';
require_once __DIR__ . '/../config.php';


if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok()) die('Bad request');

$id    = (int)($_POST['id'] ?? 0);
$name  = trim($_POST['name'] ?? '');
$cat   = $_POST['category'] ?? 'bracelet';
$price = (float)($_POST['price'] ?? 0);
$stock = (int)($_POST['stock'] ?? 0);
$featured = !empty($_POST['featured']) ? 1 : 0;
$desc  = $_POST['description'] ?? '';
$image_url = null;



if ($id) {
    // update
    if ($image_url) {
        $sql = "UPDATE products SET name=?, category=?, price=?, description=?, stock=?, featured=?, image_url=?, updated_at=NOW() WHERE id=?";
        db()->prepare($sql)->execute([$name,$cat,$price,$desc,$stock,$featured,$image_url,$id]);
        $pid = $id;
    } else {
        $sql = "UPDATE products SET name=?, category=?, price=?, description=?, stock=?, featured=?, updated_at=NOW() WHERE id=?";
        db()->prepare($sql)->execute([$name,$cat,$price,$desc,$stock,$featured,$id]);
    }
} else {
    // insert
    $sql = "INSERT INTO products (name,category,price,description,stock,featured,image_url) VALUES (?,?,?,?,?,?,?)";
    db()->prepare($sql)->execute([$name,$cat,$price,$desc,$stock,$featured,$image_url]);
    $pid = (int)db()->lastInsertId();
}

// ---- Handle multiple gallery uploads
// Determine product id (on insert you probably just inserted above)
$pid = $id ?: (int)db()->lastInsertId();

// MAIN IMAGE (single)
if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg','jpeg','png','webp'])) {
        if (!is_dir(UPLOAD_PATH)) { mkdir(UPLOAD_PATH, 0775, true); }
        $fname = 'prod_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest  = UPLOAD_PATH . '/' . $fname;     // filesystem path
        if (!move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
            die('Upload failed (main image)');
        }
        $url = UPLOAD_URL . '/' . $fname;        // URL to store in DB
        db()->prepare("UPDATE products SET image_url=?, updated_at=NOW() WHERE id=?")
            ->execute([$url, $pid]);              // $pid is your product id
    }
}

// GALLERY (multiple)
if (!empty($_FILES['gallery']['name'][0])) {
    $files = $_FILES['gallery'];
    $firstNewUrl = null;
    if (!is_dir(UPLOAD_PATH)) { mkdir(UPLOAD_PATH, 0775, true); }

    for ($i=0; $i<count($files['name']); $i++) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','webp'])) continue;

        $fname = 'prodgal_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
        $dest  = UPLOAD_PATH . '/' . $fname;
        if (!move_uploaded_file($files['tmp_name'][$i], $dest)) {
            // skip this one; don’t kill the whole request
            continue;
        }
        $url = UPLOAD_URL . '/' . $fname;
        if ($firstNewUrl === null) $firstNewUrl = $url;

        db()->prepare("INSERT INTO product_images (product_id, image_url) VALUES (?, ?)")
            ->execute([$pid, $url]);
    }

    if (!empty($_POST['make_primary_from_gallery']) && $firstNewUrl) {
        db()->prepare("UPDATE product_images SET is_primary=0 WHERE product_id=?")->execute([$pid]);
        db()->prepare("UPDATE product_images SET is_primary=1 WHERE product_id=? AND image_url=?")->execute([$pid, $firstNewUrl]);
        db()->prepare("UPDATE products SET image_url=? WHERE id=?")->execute([$firstNewUrl, $pid]);
    }




}



header('Location: /admin/products.php'); exit;
