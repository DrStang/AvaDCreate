<?php
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/util.php';

$c = ($_GET['c'] ?? '');
if (!in_array($c, ['bracelet','necklace'], true)) $c = 'bracelet';
$nav_active = ($c === 'necklace') ? 'necklaces' : 'bracelets';

$style = $_GET['style'] ?? '';            // '', 'clay', 'sets'
$sort  = $_GET['sort']  ?? 'newest';      // 'newest','price_asc','price_desc','name_asc'

include __DIR__.'/../partials/header.php';

// Build WHERE + ORDER BY
$where = 'category = ?';
$args  = [$c];

if ($c === 'bracelet' && in_array($style, ['clay','sets'], true)) {
    $where .= ' AND bracelet_type = ?';
    $args[] = $style;
}

switch ($sort) {
    case 'price_asc':
        $orderBy = 'price ASC, created_at DESC';
        break;
    case 'price_desc':
        $orderBy = 'price DESC, created_at DESC';
        break;
    case 'name_asc':
        $orderBy = 'name ASC';
        break;
    default:
        $orderBy = 'created_at DESC';
}

$sql = "SELECT * FROM products WHERE $where ORDER BY $orderBy";
$stmt = db()->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll();
?>
<h1><?= ucfirst($c) ?></h1>

<?php if ($c === 'bracelet'): ?>
    <form method="get" style="margin:12px 0;display:flex;flex-wrap:wrap;gap:8px;align-items:center">
        <input type="hidden" name="c" value="<?= h($c) ?>">
        <label>Style:
            <select name="style">
                <option value="">All bracelets</option>
                <option value="clay"   <?= $style === 'clay'   ? 'selected' : '' ?>>Clay beads</option>
                <option value="sets" <?= $style === 'sets' ? 'selected' : '' ?>>Sets</option>
            </select>
        </label>
        <label>Sort by:
            <select name="sort">
                <option value="newest"    <?= $sort === 'newest'    ? 'selected' : '' ?>>Newest</option>
                <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Price: low → high</option>
                <option value="price_desc"<?= $sort === 'price_desc'? 'selected' : '' ?>>Price: high → low</option>
                <option value="name_asc"  <?= $sort === 'name_asc'  ? 'selected' : '' ?>>Name A–Z</option>
            </select>
        </label>
        <button class="btn" type="submit">Apply</button>
    </form>
<?php endif; ?>

<div class="grid">
    <?php foreach ($rows as $p): ?>
        <div class="card">
            <a href="/product.php?id=<?= (int)$p['id'] ?>">
                <img src="<?= h($p['image_url'] ?: '/uploads/placeholder.jpg') ?>" alt="<?= h($p['name']) ?>">
            </a>
            <div class="p">
                <div style="display:flex;justify-content:space-between;align-items:center">
                    <div>
                        <strong><?= h($p['name']) ?></strong>
                        <?php if ($c === 'bracelet' && !empty($p['bracelet_type'])): ?>
                            <div style="font-size:0.8rem;color:#6b7280;margin-top:2px">
                                <?= $p['bracelet_type'] === 'clay' ? 'Clay beads bracelet' : 'Bracelet sets' ?>
                            </div>
                        <?php endif; ?>
                    </div>
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
