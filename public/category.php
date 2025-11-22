<?php require_once __DIR__.'/../lib/db.php'; require_once __DIR__.'/../lib/util.php';
$c = ($_GET['c'] ?? ''); if (!in_array($c, ['bracelet','necklace'], true)) $c = 'bracelet';
$nav_active = ($c==='necklace') ? 'necklaces' : 'bracelets';
include __DIR__.'/../partials/header.php';
$stmt = db()->prepare("SELECT * FROM products WHERE category = ? ORDER BY created_at DESC");
$stmt->execute([$c]); $rows = $stmt->fetchAll();
?>
<h1><?= ucfirst($c) ?></h1>
<div class="grid">
    <?php foreach ($rows as $p): ?>
        <div class="card">
            <a href="/product.php?id=<?= (int)$p['id'] ?>">
                <img src="<?= h($p['image_url'] ?: '/uploads/placeholder.jpg') ?>" alt="<?= h($p['name']) ?>">
            </a>
            <div class="p">
                <div style="display:flex;justify-content:space-between;align-items:center">
                    <div><strong><?= h($p['name']) ?></strong></div>
                    <div class="pill">$<?= money((float)$p['price']) ?></div>
                </div>
                <div style="margin-top:8px">
                    <a class="btn" href="/product.php?id=<?= (int)$p['id'] ?>">View</a>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php include __DIR__.'/../partials/footer.php'; ?>
