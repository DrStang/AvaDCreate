<?php
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/util.php';
require_once __DIR__.'/../lib/reviews.php';

$nav_active = 'reviews';
include __DIR__.'/../partials/header.php';

$rows = db()->query(
    "SELECT r.*, p.name AS product_name
     FROM reviews r
     LEFT JOIN products p ON p.id = r.product_id
     WHERE r.status = 'approved'
     ORDER BY r.created_at DESC"
)->fetchAll();

$count = count($rows);
$avg = 0.0;
if ($count) {
    $sum = 0;
    foreach ($rows as $r) $sum += (int)$r['rating'];
    $avg = $sum / $count;
}
?>
<style>
    .rv-hero{
        background:linear-gradient(135deg,var(--brand),var(--brand-2));
        color:#fff;border-radius:20px;padding:26px 24px;margin:6px 0 22px;
        box-shadow:0 16px 34px rgba(122,61,145,.22);
    }
    .rv-hero h1{margin:0 0 6px;font-size:28px;color:#fff}
    .rv-hero .sub{opacity:.92;font-weight:600}
    .rv-avg{display:flex;align-items:center;gap:14px;margin-top:10px;flex-wrap:wrap}
    .rv-avg .num{font-size:34px;font-weight:800;line-height:1}
    .rv-avg .stars{font-size:24px;letter-spacing:2px;color:#ffd76a}
    .rv-avg .stars-empty{color:rgba(255,255,255,.45)}
    .rv-avg .cnt{opacity:.9}

    .rv-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px}
    .rv-card{
        background:#fff;border:1px solid #eee;border-radius:16px;padding:18px;
        box-shadow:0 12px 28px rgba(20,16,37,.06);display:flex;flex-direction:column;gap:8px;
    }
    .rv-card .stars{font-size:18px;letter-spacing:2px;color:#e8a52a}
    .rv-card .stars-empty{color:#e5cbb0}
    .rv-card .title{font-weight:800;color:#3b1c46}
    .rv-card .body{color:#333;line-height:1.5}
    .rv-card .meta{margin-top:auto;color:var(--muted);font-size:13px;display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap}
    .rv-badge{display:inline-block;background:#f3e9ff;color:#6b21a8;border-radius:999px;padding:2px 10px;font-size:12px;font-weight:700}
    .rv-empty{background:#fff;border:1px dashed #e2d3ef;border-radius:16px;padding:28px;text-align:center;color:var(--muted)}
</style>

<div class="rv-hero">
    <h1>What customers are saying</h1>
    <div class="sub">Real reviews from real Ava D Creates buyers 💕</div>
    <?php if ($count): ?>
        <div class="rv-avg">
            <span class="num"><?= number_format($avg, 1) ?></span>
            <?= review_stars_html((int)round($avg)) ?>
            <span class="cnt"><?= $count ?> review<?= $count===1?'':'s' ?></span>
        </div>
    <?php endif; ?>
</div>

<?php if (!$count): ?>
    <div class="rv-empty">
        No reviews just yet — check back soon! Every piece is handmade with love. 💜
    </div>
<?php else: ?>
    <div class="rv-grid">
        <?php foreach ($rows as $r): ?>
            <div class="rv-card">
                <?= review_stars_html((int)$r['rating']) ?>
                <?php if (!empty($r['title'])): ?>
                    <div class="title"><?= h($r['title']) ?></div>
                <?php endif; ?>
                <div class="body"><?= nl2br(h($r['body'])) ?></div>
                <div class="meta">
                    <span>— <?= h($r['author_name']) ?></span>
                    <span>
                        <?php if (!empty($r['product_name'])): ?>
                            <span class="rv-badge"><?= h($r['product_name']) ?></span>
                        <?php endif; ?>
                        <?php if ($r['source']==='etsy'): ?>
                            <span class="rv-badge">via Etsy</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php include __DIR__.'/../partials/footer.php'; ?>
