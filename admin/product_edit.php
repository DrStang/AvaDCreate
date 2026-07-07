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
            return array_map(fn($v)=>['val'=>$v, 'label'=>ucfirst($v)], $vals);
        }
    } catch (\Throwable $e) {}
    return [
        ['val'=>'bracelet','label'=>'Bracelet'],
        ['val'=>'necklace','label'=>'Necklace'],
    ];
}

$id = (int)($_GET['id'] ?? 0);
$p  = null;
if ($id) {
    $st = db()->prepare("SELECT * FROM products WHERE id=?");
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) die('Not found');
}

// Load existing gallery images (if table exists)
$images = [];
if ($id) {
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
            <div class="admin-title"><?= $id ? '✏️ Edit Product' : '➕ Add Product' ?></div>
            <div class="admin-actions">
                <a class="tab" href="/admin/dashboard.php">📊 Stats</a>
                <a class="tab" href="/admin/products.php">🧾 Manage Products</a>
                <a class="tab" href="/admin/analytics.php">Analytics</a>
                <a class="tab" href="/admin/orders.php">📃 Orders</a>
                <a class="tab active" href="<?= $id ? '/admin/product_edit.php?id='.(int)$id : '/admin/product_edit.php' ?>">
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
                <div class="admin-title">Details</div>

                <div class="row" style="display:grid;grid-template-columns:1fr 180px;gap:12px;align-items:end">
                    <label>Name<br>
                        <input name="name" value="<?= h($p['name'] ?? '') ?>" required>
                    </label>
                    <label>Price ($)<br>
                        <input name="price" type="number" step="0.01" min="0"
                               value="<?= isset($p['price'])? h($p['price']) : '' ?>" required>
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
                        <input name="stock" type="number" step="1" min="0" value="<?= (int)($p['stock'] ?? 0) ?>">
                    </label>
                    <label>Featured<br>
                        <select name="featured">
                            <option value="0" <?= (($p['featured']??0)?'':'selected') ?>>No</option>
                            <option value="1" <?= (($p['featured']??0)?'selected':'') ?>>Yes</option>
                        </select>
                    </label>
                </div>

                <div id="bracelet-style-row"
                     style="display:<?= (($p['category'] ?? '') === 'bracelet') ? 'block' : 'none' ?>;margin-top:8px">
                    <label>Bracelet Style<br>
                        <?php $bt = $p['bracelet_type'] ?? ''; ?>
                        <select name="bracelet_type">
                            <option value="">(Not set)</option>
                            <option value="clay"   <?= $bt === 'clay'   ? 'selected' : '' ?>>Clay beads</option>
                            <option value="sets" <?= $bt === 'sets' ? 'selected' : '' ?>>Sets</option>
                        </select>
                        <small style="color:#6b7280">Only used for bracelets – leave blank for other categories.</small>
                    </label>
                </div>

                <label>Description<br>
                    <textarea name="description" rows="6" style="width:100%"><?= h($p['description'] ?? '') ?></textarea>
                </label>

                <label>Primary Image URL (optional)<br>
                    <input name="image_url" type="url" value="<?= h($p['image_url'] ?? '') ?>" placeholder="https://…">
                </label>
            </div>

            <!-- right: live preview -->
            <div class="card">
                <div class="admin-title">Preview</div>
                <div style="background:#fff;border-radius:18px;padding:18px;box-shadow:0 14px 34px rgba(20,16,37,.08)">
                    <div style="height:220px;border:1px solid #eee;border-radius:12px;display:flex;align-items:center;justify-content:center;background:#faf9f6;overflow:hidden">
                        <?php
                        $primary = $p['image_url'] ?? null;
                        if (!$primary && $images) {
                            foreach ($images as $im) if ($im['is_primary']) { $primary = $im['image_url']; break; }
                            if (!$primary) $primary = $images[0]['image_url'] ?? null;
                        }
                        ?>
                        <?php if ($primary): ?>
                            <img src="<?= h($primary) ?>" alt="" style="max-height:100%;max-width:100%;object-fit:contain">
                        <?php else: ?>
                            <div style="color:#999">No image yet</div>
                        <?php endif; ?>
                    </div>
                    <h3 style="margin:12px 0 4px 0"><?= h($p['name'] ?? 'New product') ?></h3>
                    <?php if (isset($p['price'])): ?>
                        <div style="display:inline-block;background:#e9d5ff;color:#4c1d95;border-radius:999px;padding:4px 10px;font-weight:600">$<?= money((float)$p['price']) ?></div>
                    <?php endif; ?>
                </div>

                <?php if ($images): ?>
                    <div style="margin-top:16px">
                        <div class="admin-title" style="font-size:14px;margin-bottom:6px">Existing Images</div>
                        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px">
                            <?php foreach ($images as $im): ?>
                                <div style="border:1px solid #eee;border-radius:12px;padding:8px">
                                    <div style="height:120px;border-radius:10px;overflow:hidden;margin-bottom:6px;background:#fafafa;display:flex;align-items:center;justify-content:center">
                                        <img src="<?= h($im['image_url']) ?>" alt="" style="max-width:100%;max-height:100%;object-fit:cover">
                                    </div>
                                    <div style="font-size:12px;margin-bottom:6px">
                                        ID #<?= (int)$im['id'] ?><?= $im['is_primary'] ? ' · Primary' : '' ?>
                                    </div>
                                    <div style="display:flex;gap:6px;flex-wrap:wrap">
                                        <?php if (!$im['is_primary']): ?>
                                            <a class="btn-outline" href="/admin/product_make_primary.php?id=<?= (int)$im['id'] ?>&product_id=<?= (int)($p['id'] ?? 0) ?>">Make Primary</a>
                                        <?php endif; ?>
                                        <a class="btn-outline" href="/admin/product_image_delete.php?id=<?= (int)$im['id'] ?>&product_id=<?= (int)($p['id'] ?? 0) ?>" onclick="return confirm('Delete this image?')">Delete</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div style="color:#6b7280;margin-top:8px">No images uploaded yet.</div>
                <?php endif; ?>

                <div style="margin-top:12px">
                    <label>Upload images<br>
                        <input type="file" name="images[]" accept="image/*" multiple>
                    </label>
                    <div id="preview" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px;margin-top:10px"></div>
                </div>
            </div>
        </div>

        <div class="section" style="margin-top:16px;display:flex;justify-content:flex-end;gap:10px">
            <?php if ($id): ?>
                <a class="btn-outline" href="/product.php?id=<?= (int)$id ?>" target="_blank">View Live</a>
            <?php endif; ?>
            <button class="btn" type="submit">💾 Save Product</button>
        </div>
    </form>
</div>

<script>
    document.querySelector('input[name="images[]"]')?.addEventListener('change', (e)=>{
        const box = document.getElementById('preview'); box.innerHTML='';
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
        function updateBraceletRow() {
            row.style.display = (cat.value === 'bracelet') ? 'block' : 'none';
        }
        cat.addEventListener('change', updateBraceletRow);
        updateBraceletRow();
    })();

</script>
