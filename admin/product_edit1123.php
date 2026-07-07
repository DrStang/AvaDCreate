<?php
require_once __DIR__.'/../lib/auth.php'; admin_required();
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/util.php';
require_once __DIR__.'/../config.php';

$nav_active = 'products';
include __DIR__.'/_style.php';

function get_categories(): array {
    // prefer categories table
    try {
        $st = db()->query("SELECT slug AS val, name AS label FROM categories ORDER BY sort_order, name");
        $rows = $st->fetchAll();
        if ($rows) return array_map(fn($r)=>['val'=>$r['val'], 'label'=>$r['label']], $rows);
    } catch (\Throwable $e) {}

    // fallback to enum
    try {
        $sql = "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'products'
                  AND COLUMN_NAME = 'category'";
        $st = db()->query($sql);
        $enum = $st->fetchColumn();
        if ($enum && preg_match("/^enum\\('(.*)'\\)$/i", $enum, $m)) {
            $vals = explode("','", $m[1]);
            return array_map(fn($v)=>['val'=>$v,'label'=>ucfirst($v)], $vals);
        }
    } catch (\Throwable $e) {}
    return [
        ['val'=>'bracelets','label'=>'Bracelets'],
        ['val'=>'necklaces','label'=>'Necklaces'],
        ['val'=>'earrings','label'=>'Earrings'],
    ];
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$p = null;
$images = [];

if ($id) {
    $stmt = db()->prepare("SELECT * FROM products WHERE id=?");
    $stmt->execute([$id]);
    $p = $stmt->fetch();
    if (!$p) {
        http_response_code(404);
        echo "Product not found";
        exit;
    }
    // images
    try {
        $iq = db()->prepare("SELECT id, image_url, is_primary, sort_order 
                             FROM product_images 
                             WHERE product_id=? 
                             ORDER BY is_primary DESC, sort_order ASC, id ASC");
        $iq->execute([$id]);
        $images = $iq->fetchAll();
    } catch (\Throwable $e) {
        $images = [];
    }
}
?>

<div class="admin-header">
    <div class="admin-wrap">
        <div class="admin-brand">✨ Ava D Creates · Admin</div>
        <a class="btn-outline" href="/admin/logout.php">Logout</a>
    </div>
</div>

<div class="admin-hero">
    <div class="admin-hero-inner">
        <div class="admin-hero-card">
            <div class="admin-title">
                <?= $id ? '✏️ Edit Product' : '➕ Add Product' ?>
            </div>
            <div class="admin-actions">
                <a class="tab" href="/admin/dashboard.php">📊 Stats</a>
                <a class="tab" href="/admin/products.php">🧾 Manage Products</a>
                <a class="tab" href="/admin/analytics.php">Analytics</a>
                <a class="tab" href="/admin/orders.php">📃 Orders</a>
                <a class="tab active"
                   href="<?= $id ? '/admin/product_edit.php?id='.(int)$id : '/admin/product_edit.php' ?>">
                    <?= $id ? '✏️ Edit Product' : '➕ Add Product' ?>
                </a>
            </div>
        </div>
    </div>
</div>

<div class="admin-wrap">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
        <div class="admin-title" style="margin:0;font-size:1.2rem;">
            <?= $id ? 'Editing product #'.(int)$id : 'Add a new product' ?>
        </div>
        <a class="btn-outline" href="/admin/products.php">← Back to products</a>
    </div>

    <form method="post" action="/admin/product_save.php" enctype="multipart/form-data">
        <input type="hidden" name="id"   value="<?= (int)($p['id'] ?? 0) ?>">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

        <div class="section" style="display:grid;grid-template-columns: 1.1fr .9fr;gap:18px">
            <!-- left: fields -->
            <div class="card">
                <h2 style="margin-top:0;margin-bottom:12px;font-size:18px">Basic Details</h2>

                <div class="row" style="display:grid;grid-template-columns: 2.2fr 1fr;gap:12px">
                    <label>Name<br>
                        <input name="name" type="text" required
                               value="<?= h($p['name'] ?? '') ?>">
                    </label>
                    <label>SKU<br>
                        <input name="sku" type="text"
                               value="<?= h($p['sku'] ?? '') ?>">
                    </label>
                </div>

                <div class="row" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
                    <label>Category<br>
                        <select name="category" required>
                            <?php foreach (get_categories() as $c): ?>
                                <option value="<?= h($c['val']) ?>" <?= (($p['category']??'')===$c['val'])?'selected':'' ?>>
                                    <?= h($c['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Stock<br>
                        <input name="stock" type="number" step="1" min="0"
                               value="<?= (int)($p['stock'] ?? 0) ?>">
                    </label>
                    <label>Featured?<br>
                        <select name="is_featured">
                            <?php $f = (int)($p['is_featured'] ?? 0); ?>
                            <option value="0" <?= $f?'' :'selected' ?>>No</option>
                            <option value="1" <?= $f?'selected':'' ?>>Yes</option>
                        </select>
                    </label>
                </div>
                <div id="bracelet-style-row"
                     style="display:<?= (($p['category'] ?? '') === 'bracelet') ? 'grid' : 'none' ?>;
                             grid-template-columns:1fr;gap:12px;margin-top:8px">
                    <label>Bracelet Style<br>
                        <select name="bracelet_type">
                            <?php $bt = $p['bracelet_type'] ?? ''; ?>
                            <option value="">(Not set)</option>
                            <option value="clay"   <?= $bt === 'clay'   ? 'selected' : '' ?>>Clay beads</option>
                            <option value="themed" <?= $bt === 'themed' ? 'selected' : '' ?>>Themed</option>
                        </select>
                        <small style="color:#6b7280">Only used for bracelets – leave blank for necklaces or other products.</small>
                    </label>
                </div>


                <div class="row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:8px">
                    <label>Price (USD)<br>
                        <input name="price" required type="number" step="0.01" min="0"
                               value="<?= h(number_format((float)($p['price'] ?? 0),2,'.','')) ?>">
                    </label>
                    <label>Compare-at Price<br>
                        <input name="compare_at_price" type="number" step="0.01" min="0"
                               value="<?= h($p['compare_at_price'] ?? '') ?>">
                        <small style="color:#6b7280">Optional “original” price for sale badge.</small>
                    </label>
                </div>

                <label style="margin-top:12px">Short Description<br>
                    <textarea name="short_description" rows="2"
                              placeholder="One or two lines for product cards."><?= h($p['short_description'] ?? '') ?></textarea>
                </label>

                <label style="margin-top:12px">Full Description<br>
                    <textarea name="description" rows="5"
                              placeholder="Detailed description shown on product page."><?= h($p['description'] ?? '') ?></textarea>
                </label>

                <div class="row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px">
                    <label>Active?<br>
                        <select name="is_active">
                            <?php $a = (int)($p['is_active'] ?? 1); ?>
                            <option value="1" <?= $a?'selected':'' ?>>Active (visible)</option>
                            <option value="0" <?= $a?'':'selected' ?>>Inactive (hidden)</option>
                        </select>
                    </label>
                    <label>Sort Order<br>
                        <input name="sort_order" type="number" step="1" min="0"
                               value="<?= (int)($p['sort_order'] ?? 0) ?>">
                    </label>
                </div>
            </div>

            <!-- right: images + metadata -->
            <div class="card">
                <h2 style="margin-top:0;margin-bottom:12px;font-size:18px">Images</h2>

                <?php if ($images): ?>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px;margin-bottom:10px">
                        <?php foreach ($images as $img): ?>
                            <div style="border:1px solid #eee;border-radius:10px;padding:6px;text-align:center">
                                <img src="<?= h($img['image_url']) ?>" alt="" style="width:120px;height:120px;object-fit:cover;border-radius:8px">
                                <div style="margin-top:6px;font-size:12px;display:flex;justify-content:space-between;align-items:center;gap:4px">
                                    <label style="display:flex;align-items:center;gap:4px;font-size:11px">
                                        <input type="radio" name="primary_image_id"
                                               value="<?= (int)$img['id'] ?>"
                                            <?= (int)$img['is_primary'] ? 'checked' : '' ?>>
                                        Primary
                                    </label>
                                    <input type="number" name="image_sort[<?= (int)$img['id'] ?>]" value="<?= (int)$img['sort_order'] ?>" style="width:50px;font-size:11px">
                                </div>
                                <div style="margin-top:4px">
                                    <label style="font-size:11px;color:#b91c1c;display:flex;align-items:center;gap:4px">
                                        <input type="checkbox" name="delete_image_ids[]" value="<?= (int)$img['id'] ?>">
                                        Remove
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p style="color:#6b7280;font-size:13px;margin-top:0">No images yet.</p>
                <?php endif; ?>

                <label style="margin-top:8px;display:block">
                    Add New Images<br>
                    <input type="file" name="images[]" accept="image/*" multiple>
                    <small style="color:#6b7280;font-size:12px">
                        You can upload multiple images. First one (or explicitly selected radio) will be primary.
                    </small>
                </label>

                <div id="preview" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px;margin-top:10px"></div>

                <hr style="margin:16px 0">

                <h2 style="margin-top:0;margin-bottom:12px;font-size:18px">Metadata</h2>
                <label>Tags (comma separated)<br>
                    <input name="tags" type="text" placeholder="e.g. gold, birthstone, custom"
                           value="<?= h($p['tags'] ?? '') ?>">
                </label>
                <label style="margin-top:10px">SEO Title<br>
                    <input name="seo_title" type="text"
                           value="<?= h($p['seo_title'] ?? '') ?>">
                </label>
                <label style="margin-top:10px">SEO Description<br>
                    <textarea name="seo_description" rows="3"><?= h($p['seo_description'] ?? '') ?></textarea>
                </label>
            </div>
        </div>

        <div class="section" style="margin-top:16px;display:flex;justify-content:flex-end;gap:10px">
            <?php if ($id): ?>
                <a class="btn-outline" href="/product/<?= (int)$id ?>" target="_blank">View Live</a>
            <?php endif; ?>
            <button class="btn" type="submit">💾 Save Product</button>
        </div>
    </form>
</div>

<script>
    document.querySelector('input[name="images[]"]')?.addEventListener('change', (e)=>{
        const box = document.getElementById('preview');
        box.innerHTML='';
        const files = Array.from(e.target.files || []);
        files.forEach(f=>{
            if (!/^image\//.test(f.type)) return;
            const r = new FileReader();
            r.onload = ev=>{
                const wrap = document.createElement('div');
                wrap.style.cssText = 'border:1px solid #eee;border-radius:10px;padding:6px';
                wrap.innerHTML = `<img src="${ev.target.result}" style="width:120px;height:120px;object-fit:cover;border-radius:8px">`;
                box.appendChild(wrap);
            };
            r.readAsDataURL(f);
        });
    });
        (function() {
        const cat = document.querySelector('select[name="category"]');
        const row = document.getElementById('bracelet-style-row');
        if (!cat || !row) return;

        function updateBraceletStyleVisibility() {
        row.style.display = (cat.value === 'bracelet') ? 'grid' : 'none';
    }

        cat.addEventListener('change', updateBraceletStyleVisibility);
        updateBraceletStyleVisibility();
    })();
</script>
