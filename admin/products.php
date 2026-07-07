<?php
require_once __DIR__.'/../lib/auth.php'; admin_required();
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/util.php';

$nav_active = 'products';
include __DIR__.'/_style.php';

/** ------- config ------- **/
$low_threshold = 3;

/** ------- helpers ------- **/
function get_categories(): array {
    // 1) prefer categories table: id, slug/name columns
    try {
        $st = db()->query("SELECT slug AS val, name AS label FROM categories ORDER BY sort_order, name");
        $rows = $st->fetchAll();
        if ($rows) {
            return array_map(fn($r)=>['val'=>$r['val'], 'label'=>$r['label']], $rows);
        }
    } catch (\Throwable $e) {}
    // 2) fallback: ENUM on products.category
    try {
        $sql = "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'products'
                  AND COLUMN_NAME = 'category'";
        $st = db()->query($sql);
        $enum = $st->fetchColumn();
        if ($enum && preg_match("/^enum\\('(.*)'\\)$/i", $enum, $m)) {
            $vals = explode("','", $m[1]);
            return array_map(fn($v)=>['val'=>$v, 'label'=>ucfirst($v)], $vals);
        }
    } catch (\Throwable $e) {}
    // safe default
    return [
        ['val'=>'bracelet','label'=>'Bracelet'],
        ['val'=>'necklace','label'=>'Necklace'],
    ];
}
function pill($text, $bg, $fg='#111') {
    return '<span style="display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:500;background:'.$bg.';color:'.$fg.';border:1px solid rgba(0,0,0,.06)">'.$text.'</span>';
}
function stock_badge($n, $low=3) {
    $n = (int)$n;
    if ($n <= 0)  return pill('Out of stock', '#fee2e2', '#991b1b');
    if ($n <= $low) return pill("Only $n left", '#f3e8ff', '#6b21a8');
    return pill("$n in stock", '#e5f6ff', '#0275d8');
}
function sortLink($label, $key) {
    $q = $_GET;
    $q['sort']=$key;
    $q['dir'] = (($_GET['sort']??'')===$key && strtolower($_GET['dir']??'desc')==='asc')?'desc':'asc';
    return '<a href="?'.htmlspecialchars(http_build_query($q)).'">'.$label.'</a>';
}
function category_options_html($selected) {
    $opts = '';
    foreach (get_categories() as $c) {
        $sel = ($selected === $c['val']) ? 'selected' : '';
        $opts .= '<option value="'.htmlspecialchars($c['val']).'" '.$sel.'>'.htmlspecialchars($c['label']).'</option>';
    }
    return $opts;
}

/** ------- bulk actions ------- **/
$flash = [];
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['bulk_action'])) {
    if (!csrf_ok()) { http_response_code(400); die('Bad CSRF'); }
    $action = $_POST['bulk_action'] ?? '';
    $ids    = array_map('intval', $_POST['ids'] ?? []);
    $ids    = array_values(array_filter($ids));
    if ($action && $ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        try {
            db()->beginTransaction();
            try {
                switch ($action) {
                    case 'feature':
                        db()->prepare("UPDATE products SET featured=1 WHERE id IN ($in)")->execute($ids);
                        $flash[] = ['type'=>'ok','msg'=>'Selected products marked as featured.'];
                        break;
                    case 'unfeature':
                        db()->prepare("UPDATE products SET featured=0 WHERE id IN ($in)")->execute($ids);
                        $flash[] = ['type'=>'ok','msg'=>'Selected products unfeatured.'];
                        break;
                    case 'stock_zero':
                        db()->prepare("UPDATE products SET stock=0 WHERE id IN ($in)")->execute($ids);
                        $flash[] = ['type'=>'ok','msg'=>'Selected products set to out of stock.'];
                        break;
                    case 'style_clay':
                        db()->prepare("UPDATE products SET bracelet_type='clay' WHERE id IN ($in)")->execute($ids);
                        $flash[] = ['type'=>'ok','msg'=>'Selected bracelets set to Clay beads.'];
                        break;
                    case 'style_sets':
                        db()->prepare("UPDATE products SET bracelet_type='sets' WHERE id IN ($in)")->execute($ids);
                        $flash[] = ['type'=>'ok','msg'=>'Selected bracelets set to Sets.'];
                        break;
                    case 'delete':
                        // Remove images, then products
                        db()->prepare("DELETE FROM product_images WHERE product_id IN ($in)")->execute($ids);
                        db()->prepare("DELETE FROM products WHERE id IN ($in)")->execute($ids);
                        $flash[] = ['type'=>'ok','msg'=>'Selected products deleted.'];
                        break;
                    default:
                        $flash[] = ['type'=>'warn','msg'=>'Unknown bulk action.'];
                }
                db()->commit();
            } catch (\Throwable $e) {
                db()->rollBack();
                $flash[] = ['type'=>'error','msg'=>'Bulk action failed: '.$e->getMessage()];
            }
        } catch (\Throwable $e) {
            $flash[] = ['type'=>'error','msg'=>'Bulk action failed: '.$e->getMessage()];
        }
    } elseif ($action) {
        $flash[] = ['type'=>'warn','msg'=>'No products selected.'];
    }
}

/** ------- filters ------- **/
$q        = trim($_GET['q'] ?? '');
$category = trim($_GET['category'] ?? '');
$featured = $_GET['featured'] ?? '';      // '', 'yes', 'no'
$stockf   = $_GET['stock'] ?? 'all';      // 'all','gt0','low','zero'
$sort     = $_GET['sort'] ?? 'created_at';
$dir      = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 25;
$off      = ($page-1)*$perPage;

$where = [];
$args  = [];
if ($q !== '') { $where[]='(name LIKE ? OR description LIKE ?)'; $args[]="%$q%"; $args[]="%$q%"; }
if ($category !== '') { $where[] = 'category = ?'; $args[] = $category; }
if ($featured === 'yes') { $where[] = 'featured = 1'; }
if ($featured === 'no')  { $where[] = 'featured = 0'; }
if ($stockf === 'gt0') { $where[] = 'stock > 0'; }
if ($stockf === 'zero') { $where[] = 'stock <= 0'; }
if ($stockf === 'low')  { $where[] = 'stock BETWEEN 1 AND ?'; $args[] = $low_threshold; }
$sqlWhere = $where ? ('WHERE '.implode(' AND ', $where)) : '';

// sort whitelist
$sortMap = ['id','name','category','bracelet_type','price','stock','featured','created_at'];
if (!in_array($sort, $sortMap, true)) $sort = 'created_at';

// totals + rows
$stmt = db()->prepare("SELECT COUNT(*) FROM products $sqlWhere");
$stmt->execute($args);
$total = (int)$stmt->fetchColumn();

$sql = "SELECT id,name,category,bracelet_type,price,stock,featured,created_at,image_url
        FROM products $sqlWhere ORDER BY $sort $dir LIMIT $perPage OFFSET $off";
$st  = db()->prepare($sql); $st->execute($args);
$rows = $st->fetchAll();

// kpis
$lowCt  = (int)db()->query("SELECT COUNT(*) FROM products WHERE stock BETWEEN 1 AND $low_threshold")->fetchColumn();
$outCt  = (int)db()->query("SELECT COUNT(*) FROM products WHERE stock <= 0")->fetchColumn();
$featCt = (int)db()->query("SELECT COUNT(*) FROM products WHERE featured = 1")->fetchColumn();
?>
<div class="admin-header">
    <div class="admin-wrap">
        <div class="admin-brand">Products</div>
        <div class="admin-actions">
            <a class="btn" href="/admin/product_edit.php">Add New</a>
        </div>
    </div>
</div>
<div class="admin-hero">
    <div class="admin-hero-inner">
        <div class="admin-hero-card">
            <div class="admin-title">🧾 Manage Products</div>
            <div class="admin-actions">
                <a class="tab" href="/admin/dashboard.php">📊 Stats </a>
                <a class="tab active" href="/admin/products.php">🧾 Manage Products</a>
                <a class="tab " href="/admin/analytics.php"> Analytics</a>
                <a class="tab" href="/admin/email_customers.php">✉ Email Customers</a>
                <a class="tab" href="/admin/orders.php">📃 Orders</a>
                <a class="tab" href="/admin/product_edit.php">➕ Add Product</a>
            </div>
        </div>
    </div>
</div>

<div class="admin-hero">
    <form class="admin-hero-card filter-form" method="get">
        <input type="text" name="q" placeholder="Search name/description…" value="<?= htmlspecialchars($q) ?>">
        <select name="category">
            <option value="">All categories</option>
            <?= category_options_html($category) ?>
        </select>
        <select name="featured">
            <option value="">Featured: any</option>
            <option value="yes" <?= $featured==='yes'?'selected':'' ?>>Featured only</option>
            <option value="no"  <?= $featured==='no'?'selected':'' ?>>Not featured</option>
        </select>
        <select name="stock">
            <option value="all"  <?= $stockf==='all'?'selected':'' ?>>Stock: any</option>
            <option value="gt0"  <?= $stockf==='gt0'?'selected':'' ?>>In stock</option>
            <option value="low"  <?= $stockf==='low'?'selected':'' ?>>Low (≤ <?= $low_threshold ?>)</option>
            <option value="zero" <?= $stockf==='zero'?'selected':'' ?>>Out of stock</option>
        </select>
        <select name="sort">
            <option value="created_at" <?= $sort==='created_at'?'selected':'' ?>>Sort: Newest</option>
            <option value="name"       <?= $sort==='name'?'selected':'' ?>>Name A–Z</option>
            <option value="price"      <?= $sort==='price'?'selected':'' ?>>Price</option>
            <option value="stock"      <?= $sort==='stock'?'selected':'' ?>>Stock</option>
        </select>
        <button class="btn" type="submit">Apply</button>
    </form>
</div>

<div class="section">
    <?php foreach ($flash as $f): ?>
        <div class="flash flash-<?= h($f['type']) ?>"><?= h($f['msg']) ?></div>
    <?php endforeach; ?>

    <!-- KPI card ABOVE the table -->
    <div class="card" style="margin-top:18px">
        <div class="kpis">
            <div class="kpi">
                <div>Total</div>
                <div class="n"><?= (int)$total ?></div>
            </div>
            <div class="kpi">
                <div>Featured</div>
                <div class="n"><?= (int)$featCt ?></div>
            </div>
            <div class="kpi">
                <div>Low stock (≤<?= $low_threshold ?>)</div>
                <div class="n"><?= (int)$lowCt ?></div>
            </div>
            <div class="kpi">
                <div>Out of stock</div>
                <div class="n"><?= (int)$outCt ?></div>
            </div>
        </div>
    </div>

    <!-- Table card -->
    <div class="card" style="margin-top:18px">
        <form method="post">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                <div style="display:flex;gap:8px;align-items:center">
                    <label style="display:flex;gap:6px;align-items:center">
                        <input type="checkbox" id="check-all"> <span>Select all</span>
                    </label>
                    <select name="bulk_action">
                        <option value="">Bulk actions…</option>
                        <option value="feature">Mark as featured</option>
                        <option value="unfeature">Remove featured</option>
                        <option value="stock_zero">Set stock to 0</option>
                        <option value="style_clay">Set bracelet style → Clay beads</option>
                        <option value="style_sets">Set bracelet style → Sets</option>
                        <option value="delete">Delete selected</option>
                    </select>
                    <button class="btn-outline" type="submit" onclick="return confirmBulk()">Apply</button>
                </div>
                <div style="color:#6b7280;font-size:12px">
                    Tip: filter first, then “Select all” to affect only visible rows.
                </div>
            </div>

            <!-- Scroll wrapper so only the table scrolls horizontally -->
            <div id="topScroll" class="table-scroll" style="overflow-x:auto; overflow-y:hidden; margin-bottom:8px;">
                <div style="height:1px; min-width:1100px;"></div>
            </div>
            <div id="bottomScroll" class="table-scroll" style="overflow-x:auto;">
                <table class="orders-table">
                    <thead>
                    <tr>
                        <th class="col-check"></th>
                        <th class="col-id"><?= sortLink('ID','id') ?></th>
                        <th class="col-name"><?= sortLink('Name','name') ?></th>
                        <th class="col-cat"><?= sortLink('Category','category') ?></th>
                        <th class="col-style"><?= sortLink('Style','bracelet_type') ?></th>
                        <th class="col-price"><?= sortLink('Price','price') ?></th>
                        <th class="col-stock"><?= sortLink('Stock','stock') ?></th>
                        <th class="col-feat"><?= sortLink('Featured','featured') ?></th>
                        <th class="col-created"><?= sortLink('Created','created_at') ?></th>
                        <th class="col-actions">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="10" style="padding:18px;color:#6b7280">No products found.</td></tr>
                    <?php else: foreach ($rows as $p): ?>
                        <tr class="prod-row">
                            <td><input type="checkbox" name="ids[]" value="<?= (int)$p['id'] ?>"></td>
                            <td>#<?= (int)$p['id'] ?></td>
                            <td class="prod-name-cell" title="<?= h($p['name']) ?>">
                                <div class="prod-name-inner">
                                    <?php if (!empty($p['image_url'])): ?>
                                        <img src="<?= h($p['image_url']) ?>" alt="" class="prod-thumb">
                                    <?php endif; ?>
                                    <div class="prod-name-text">
                                        <div class="prod-name-main"><?= h($p['name']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><?= h($p['category']) ?></td>
                            <td>
                                <?php
                                if (($p['category'] ?? '') === 'bracelet' && !empty($p['bracelet_type'])) {
                                    echo h($p['bracelet_type'] === 'clay' ? 'Clay beads' : 'Sets');
                                } else {
                                    echo '–';
                                }
                                ?>
                            </td>
                            <td>$<?= money((float)$p['price']) ?></td>
                            <td><?= stock_badge((int)$p['stock'], $low_threshold) ?></td>
                            <td><?= $p['featured'] ? pill('Yes','#e9ffe6','#065f46') : pill('No','#f8fafc','#334155') ?></td>
                            <td><?= h(date('Y-m-d', strtotime($p['created_at']))) ?></td>
                            <td class="col-actions-cell">
                                <a class="btn-outline" href="/product.php?id=<?= (int)$p['id'] ?>" target="_blank">View</a>
                                <a class="btn-outline" href="/admin/product_edit.php?id=<?= (int)$p['id'] ?>">Edit</a>
                                <a class="btn-outline"
                                   href="/admin/product_delete.php?id=<?= (int)$p['id'] ?>&csrf=<?= urlencode(csrf_token()) ?>"
                                   onclick="return confirm('Delete product & images?')">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </form>

        <?php
        $pages = max(1, (int)ceil($total / $perPage));
        if ($pages > 1):
            echo '<div style="display:flex;justify-content:center;margin-top:16px;gap:6px">';
            for ($i=1; $i<=$pages; $i++) {
                $q = $_GET; $q['page']=$i;
                $is = $i===$page ? 'active' : '';
                echo '<a class="tab '.$is.'" href="?'.htmlspecialchars(http_build_query($q)).'">'.$i.'</a>';
            }
            echo '</div>';
        endif;
        ?>
    </div>

    <style>
        /* Table with fixed widths so the name column doesn't get squished */
        .orders-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            min-width: 1100px; /* if screen is narrower, the .table-scroll div will scroll */
        }

        .orders-table th,
        .orders-table td {
            padding: 10px 12px;
            vertical-align: top;
        }

        /* Column widths (sum ~ 1100px) */
        .orders-table .col-check { width: 32px; }
        .orders-table .col-id { width: 70px; }
        .orders-table .col-name,
        .orders-table td.prod-name-cell { width: 260px; }
        .orders-table .col-cat { width: 120px; }
        .orders-table .col-style { width: 110px; }
        .orders-table .col-price { width: 80px; }
        .orders-table .col-stock { width: 120px; }
        .orders-table .col-feat { width: 90px; }
        .orders-table .col-created { width: 120px; }
        .orders-table .col-actions { width: 150px; }

        /* Pretty row styling */
        .orders-table .prod-row {
            background:#fff;
            box-shadow:0 8px 18px rgba(20,16,37,.06);
            border-radius:14px;
        }

        /* Name cell – allow wrapping and show thumbnail + text */
        .prod-name-cell {
            white-space: normal !important;
        }
        .prod-name-inner {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            width: 100%;
        }
        .prod-thumb {
            width: 40px;
            height: 40px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #eee;
            flex-shrink: 0;
        }
        .prod-name-text {
            min-width: 0;
            width: 100%;
        }
        .prod-name-main {
            display: block;
            font-weight: 600;
            line-height: 1.3;
            word-break: break-word;
            white-space: normal !important;
        }

        .col-actions-cell {
            text-align:right;
            white-space:nowrap;
        }
    </style>
    <script>
        const topScroll = document.getElementById('topScroll');
        const bottomScroll = document.getElementById('bottomScroll');

        function syncWidths(){
            const table = bottomScroll.querySelector('table');
            if (table) {
                topScroll.firstElementChild.style.width = table.scrollWidth + 'px';
            }
        }
        topScroll.addEventListener('scroll', () => {
            bottomScroll.scrollLeft = topScroll.scrollLeft;
        });
        bottomScroll.addEventListener('scroll', () => {
            topScroll.scrollLeft = bottomScroll.scrollLeft;
        });

        window.addEventListener('load', syncWidths);
        window.addEventListener('resize', syncWidths);
        setTimeout(syncWidths, 100);
    </script>

    <script>
        const checkAll = document.getElementById('check-all');
        checkAll?.addEventListener('change', e=>{
            document.querySelectorAll('input[name="ids[]"]').forEach(cb=>cb.checked = e.target.checked);
        });
        function confirmBulk(){
            const sel = document.querySelector('select[name="bulk_action"]').value;
            if (!sel) { alert('Choose a bulk action first.'); return false; }
            if (sel === 'delete') return confirm('Delete selected products? This cannot be undone.');
            return true;
        }
    </script>
</div>
