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
        if ($rows) return array_map(fn($r)=>['val'=>$r['val'], 'label'=>$r['label']], $rows);
    } catch (\Throwable $e) { /* table may not exist */ }

    // 2) fallback to enum values from INFORMATION_SCHEMA
    try {
        $sql = "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='products' AND COLUMN_NAME='category' LIMIT 1";
        $col = db()->query($sql)->fetchColumn();
        if ($col && str_starts_with(strtolower($col), 'enum(')) {
            // COLUMN_TYPE looks like: enum('bracelet','necklace')
            $inside = trim(substr($col, 5, -1)); // remove enum( … )
            $vals = array_map(fn($s)=>trim($s, " '\""), explode(',', $inside));
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
    return '<span style="display:inline-block;padding:4px 10px;border-radius:999px;font-weight:600;font-size:12px;background:'.$bg.';color:'.$fg.';border:1px solid rgba(0,0,0,.06)">'.$text.'</span>';
}
function stock_badge($n, $low=3) {
    $n = (int)$n;
    if ($n <= 0)  return pill('Out of stock', '#fee2e2', '#991b1b');
    if ($n <= $low) return pill("Only $n left", '#f3e8ff', '#6b21a8');
    return pill("$n in stock", '#e5f6ff', '#075985');
}
function sortLink($label, $key) {
    $q = $_GET; $q['sort']=$key; $q['dir'] = (($_GET['sort']??'')===$key && strtolower($_GET['dir']??'desc')==='asc')?'desc':'asc';
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
    if (!hash_equals(csrf_token(), $_POST['csrf'] ?? '')) {
        $flash[] = ['type'=>'error','msg'=>'Invalid CSRF. Please retry.'];
    } else {
        $ids = array_map('intval', $_POST['ids'] ?? []);
        $action = $_POST['bulk_action'];
        if (!$ids) {
            $flash[] = ['type'=>'warn','msg'=>'No products selected.'];
        } else {
            $in = implode(',', array_fill(0, count($ids), '?'));
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
        }
    }
}

/** ------- filters / sorting / paging ------- **/
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
$sortMap = ['id','name','category','price','stock','featured','created_at'];
if (!in_array($sort, $sortMap, true)) $sort = 'created_at';

// totals + rows
$stmt = db()->prepare("SELECT COUNT(*) FROM products $sqlWhere");
$stmt->execute($args);
$total = (int)$stmt->fetchColumn();

$sql = "SELECT id,name,category,price,stock,featured,created_at,image_url
        FROM products $sqlWhere ORDER BY $sort $dir LIMIT $perPage OFFSET $off";
$st  = db()->prepare($sql); $st->execute($args);
$rows = $st->fetchAll();

// kpis
$lowCt  = (int)db()->query("SELECT COUNT(*) FROM products WHERE stock BETWEEN 1 AND $low_threshold")->fetchColumn();
$outCt  = (int)db()->query("SELECT COUNT(*) FROM products WHERE stock <= 0")->fetchColumn();

// flash renderer
foreach ($flash as $f) {
    $bg = $f['type']==='ok'?'#e9ffe6':($f['type']==='warn'?'#fff7ed':($f['type']==='error'?'#fee2e2':'#eef2ff'));
    $fg = $f['type']==='ok'?'#065f46':($f['type']==='warn'?'#9a3412':($f['type']==='error'?'#991b1b':'#3730a3'));
    echo '<div class="pill" style="background:'.$bg.';color:'.$fg.'">'.$f['msg'].'</div>';
}
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
                <a class="tab" href="/admin/orders.php">📃 Orders</a>
                <a class="tab" href="/admin/product_edit.php">➕ Add Product</a>
            </div>
        </div>
    </div>
</div>

    <div class="admin-hero">
        <form class="admin-hero-card" method="get" style="display:grid;grid-template-columns:repeat(6,1fr);gap:10px">
            <input type="text" name="q" placeholder="Search name/description…" value="<?= htmlspecialchars($q) ?>">
            <select name="category">
                <option value="">All categories</option>
                <?= category_options_html($category) ?>
            </select>
            <select name="featured">
                <option value="">Featured: any</option>
                <option value="yes" <?= $featured==='yes'?'selected':'' ?>>Featured only</option>
                <option value="no"  <?= $featured==='no'?'selected':''  ?>>Not featured</option>
            </select>
            <select name="stock">
                <option value="all"  <?= $stockf==='all'?'selected':''  ?>>Stock: all</option>
                <option value="gt0"  <?= $stockf==='gt0'?'selected':''  ?>>In stock</option>
                <option value="low"  <?= $stockf==='low'?'selected':''  ?>>Low (&le; <?= $low_threshold ?>)</option>
                <option value="zero" <?= $stockf==='zero'?'selected':'' ?>>Out of stock</option>
            </select>
            <select name="sort">
                <?php foreach (['created_at'=>'Created','name'=>'Name','category'=>'Category','price'=>'Price','stock'=>'Stock','featured'=>'Featured'] as $k=>$lbl): ?>
                    <option value="<?= $k ?>" <?= $sort===$k?'selected':'' ?>>Sort: <?= $lbl ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn">Apply</button>
        </form>

        <div class="admin-hero-inner" style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px">
            <div class="admin-hero-card"><div class="admin-title">Total Products</div><div class="admin-value"><?= number_format($total) ?></div></div>
            <div class="admin-hero-card"><div class="admin-title">Low Stock (&le; <?= $low_threshold ?>)</div><div class="admin-value"><?= number_format($lowCt) ?></div></div>
            <div class="admin-hero-card"><div class="admin-title">Out of Stock</div><div class="admin-value"><?= number_format($outCt) ?></div></div>
        </div>
    </div>

    <div class="section card">
        <!-- Bulk toolbar -->
        <form method="post" id="bulk-form">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <div style="display:flex;gap:8px;justify-content:space-between;align-items:center;margin-bottom:10px">
                <div style="display:flex;gap:8px;align-items:center">
                    <label style="display:flex;gap:6px;align-items:center">
                        <input type="checkbox" id="check-all"> <span>Select all</span>
                    </label>
                    <select name="bulk_action">
                        <option value="">Bulk actions…</option>
                        <option value="feature">Mark as featured</option>
                        <option value="unfeature">Remove featured</option>
                        <option value="stock_zero">Set stock to 0</option>
                        <option value="delete">Delete selected</option>
                    </select>
                    <button class="btn-outline" type="submit" onclick="return confirmBulk()">Apply</button>
                </div>
                <div style="color:#6b7280;font-size:12px">Tip: filter first, then “Select all” to affect only visible rows.</div>
            </div>

            <table class="orders-table" style="width:100%;border-collapse:collapse;table-layout:fixed">
                <thead>
                <tr>
                    <th style="width:36px"></th>
                    <th style="width:80px"><?= sortLink('ID','id') ?></th>
                    <th><?= sortLink('Name','name') ?></th>
                    <th style="width:170px"><?= sortLink('Category','category') ?></th>
                    <th style="width:120px"><?= sortLink('Price','price') ?></th>
                    <th style="width:160px"><?= sortLink('Stock','stock') ?></th>
                    <th style="width:120px"><?= sortLink('Featured','featured') ?></th>
                    <th style="width:160px"><?= sortLink('Created','created_at') ?></th>
                    <th style="width:170px;text-align:right">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="9" style="padding:18px;color:#6b7280">No products found.</td></tr>
                <?php else: foreach ($rows as $p): ?>
                    <tr style="background:#fff;box-shadow:0 8px 18px rgba(20,16,37,.06);border-radius:14px">
                        <td><input type="checkbox" name="ids[]" value="<?= (int)$p['id'] ?>"></td>
                        <td>#<?= (int)$p['id'] ?></td>
                        <td class="truncate" title="<?= h($p['name']) ?>">
                            <div style="display:flex;gap:10px;align-items:center">
                                <?php if (!empty($p['image_url'])): ?>
                                    <img src="<?= h($p['image_url']) ?>" alt="" style="width:36px;height:36px;object-fit:cover;border-radius:8px;border:1px solid #eee">
                                <?php endif; ?>
                                <span class="truncate"><?= h($p['name']) ?></span>
                            </div>
                        </td>
                        <td><?= h($p['category']) ?></td>
                        <td>$<?= money((float)$p['price']) ?></td>
                        <td><?= stock_badge((int)$p['stock'], $low_threshold) ?></td>
                        <td><?= $p['featured'] ? pill('Yes','#e9ffe6','#065f46') : pill('No','#f8fafc','#334155') ?></td>
                        <td><?= h(date('Y-m-d', strtotime($p['created_at']))) ?></td>
                        <td style="text-align:right;white-space:nowrap">
                            <a class="btn-outline" href="/product.php?id=<?= (int)$p['id'] ?>" target="_blank">View</a>
                            <a class="btn-outline" href="/admin/product_edit.php?id=<?= (int)$p['id'] ?>">Edit</a>
                            <a class="btn-outline" href="/admin/product_delete.php?id=<?= (int)$p['id'] ?>" onclick="return confirm('Delete product & images?')">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </form>

        <?php
        $pages = max(1, (int)ceil($total / $perPage));
        if ($pages > 1):
            echo '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">';
            for ($i=1;$i<=$pages;$i++){
                $q = $_GET; $q['page']=$i;
                $is = $i===$page ? 'active' : '';
                echo '<a class="tab '.$is.'" href="?'.htmlspecialchars(http_build_query($q)).'">'.$i.'</a>';
            }
            echo '</div>';
        endif;
        ?>
    </div>


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

