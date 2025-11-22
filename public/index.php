<?php require_once __DIR__.'/../lib/db.php'; require_once __DIR__.'/../lib/util.php'; $nav_active='home'; include __DIR__.'/../partials/header.php';
$rows = db()->query("SELECT * FROM products WHERE featured = 1 ORDER BY created_at DESC LIMIT 12")->fetchAll();
header('Content-Type: text/html; charset=utf-8');

?>
<h1>Welcome to <?= h(APP_NAME) ?></h1>
<p>Hand-made jewelry made with love and care. Please contact me for any questions or concerns</p>

<h2>Featured</h2>
<div class="grid">
    <?php foreach ($rows as $p): ?>
        <div class="card">
            <a href="/product.php?id=<?= (int)$p['id'] ?>">
                <img src="<?= h($p['image_url'] ?: '/placeholder.jpg') ?>" alt="<?= h($p['name']) ?>">
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

<h2 id="about" style="margin-top:28px">About Me</h2>
<p>Hi I'm Ava! I love to make jewelry to style up outfits. I have a YouTube channel and Instagram where I share my daily life and making the jewelry. My goal is to make others happy with my designs and inspire others to create. Thank you for the support, I cherish every part of it 💕</p>
<?php include __DIR__.'/../partials/footer.php'; ?>
