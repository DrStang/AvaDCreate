<?php require_once __DIR__.'/../lib/auth.php'; admin_required(); require_once __DIR__.'/../lib/db.php'; require_once __DIR__.'/../lib/util.php';
$rows = db()->query("SELECT * FROM products ORDER BY created_at DESC")->fetchAll();
?>
<!doctype html><meta charset="utf-8"><title>Products · Admin</title>
<div style="max-width:1000px;margin:20px auto">
    <div style="display:flex;justify-content:space-between;align-items:center">
        <h1>Products</h1>
        <div><a href="/admin/product_edit.php" class="btn">Add New</a> · <a href="/admin/dashboard.php">Back</a></div>
    </div>
    <table border="1" cellspacing="0" cellpadding="8" style="width:100%;border-color:#eee">
        <tr><th>ID</th><th>Name</th><th>Category</th><th>Price</th><th>Stock</th><th>Featured</th><th>Actions</th></tr>
        <?php foreach ($rows as $p): ?>
            <tr>
                <td><?= (int)$p['id'] ?></td>
                <td><?= h($p['name']) ?></td>
                <td><?= h($p['category']) ?></td>
                <td>$<?= money((float)$p['price']) ?></td>
                <td><?= (int)$p['stock'] ?></td>
                <td><?= $p['featured'] ? 'Yes':'No' ?></td>
                <td>
                    <a href="/admin/product_edit.php?id=<?= (int)$p['id'] ?>">Edit</a> ·
                    <a href="/admin/product_delete.php?id=<?= (int)$p['id'] ?>" onclick="return confirm('Delete product?')">Delete</a>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>
