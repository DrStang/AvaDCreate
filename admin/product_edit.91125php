<?php require_once __DIR__.'/../lib/auth.php'; admin_required(); require_once __DIR__.'/../lib/db.php'; require_once __DIR__.'/../lib/util.php'; require_once __DIR__.'/../config.php';
$id = (int)($_GET['id'] ?? 0);
if ($id) { $stmt = db()->prepare("SELECT * FROM products WHERE id=?"); $stmt->execute([$id]); $p = $stmt->fetch(); if (!$p) die('Not found'); }
?>
<form method="post" action="/admin/product_save.php" enctype="multipart/form-data" style="display:grid;gap:16px;max-width:760px">
    <input type="hidden" name="id" value="<?= (int)($p['id'] ?? 0) ?>">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

    <label>Name<br>
        <input name="name" value="<?= h($p['name'] ?? '') ?>" required style="width:100%">
    </label>

    <label>Category<br>
        <select name="category" required>
            <?php foreach (['bracelet','necklace'] as $c): ?>
                <option value="<?= $c ?>" <?= (($p['category']??'')===$c)?'selected':'' ?>><?= ucfirst($c) ?></option>
            <?php endforeach; ?>
        </select>
    </label>

    <label>Price (USD)<br>
        <input type="number" step="0.01" name="price" value="<?= h($p['price'] ?? '') ?>" required>
    </label>

    <label>Stock<br>
        <input type="number" name="stock" value="<?= h($p['stock'] ?? 0) ?>">
    </label>

    <label><input type="checkbox" name="featured" value="1" <?= !empty($p['featured'])?'checked':'' ?>> Featured</label>

    <label>Description<br>
        <textarea name="description" rows="5" style="width:100%"><?= h($p['description'] ?? '') ?></textarea>
    </label>

    <!-- Main hero image (single) -->
    <div style="border-top:1px solid #eee;padding-top:12px">
        <strong>Main Image</strong>
        <?php if (!empty($p['image_url'])): ?>
            <img id="mainPrev" src="<?= h($p['image_url']) ?>" alt="" style="max-width:240px;border-radius:10px;display:block;margin:8px 0">
        <?php else: ?>
            <img id="mainPrev" src="" alt="" style="max-width:240px;border-radius:10px;display:none;margin:8px 0">
        <?php endif; ?>
        <input type="file" name="image" id="mainInput" accept="image/*">

        <input type="file" name="image">
        <div style="color:#6b7280;font-size:12px;margin-top:6px">JPEG/PNG/WebP</div>
    </div>



        <?php
        if (!empty($p['id'])) {
            $g = db()->prepare("SELECT * FROM product_images WHERE product_id=? ORDER BY is_primary DESC, sort_order ASC, id ASC");
            $g->execute([$p['id']]); $imgs = $g->fetchAll();
            if ($imgs) {
                echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:12px">';
                foreach ($imgs as $img) {
                    echo '<div style="border:1px solid #eee;border-radius:10px;padding:8px;text-align:center">';
                    echo '<img src="'.h($img['image_url']).'" style="width:120px;height:120px;object-fit:cover;border-radius:8px;display:block;margin-bottom:6px">';
                    if ($img['is_primary']) echo '<div class="pill">Primary</div>';
                    echo '<div style="margin-top:6px;display:flex;gap:6px;justify-content:center">';
                    echo '<a class="btn" href="/admin/product_image_primary.php?id='.(int)$img['id'].'&pid='.(int)$p['id'].'">Set Primary</a>';
                    echo '<a class="btn secondary" href="/admin/product_image_delete.php?id='.(int)$img['id'].'&pid='.(int)$p['id'].'" onclick="return confirm(\'Delete this image?\')">Delete</a>';
                    echo '</div></div>';
                }
                echo '</div>';
            }
        }
        ?>
    <!-- Gallery (multiple) -->
    <div style="border-top:1px solid #eee;padding-top:12px">
        <strong>Gallery Images</strong>
        <div style="margin:8px 0">
            <input type="file" name="gallery[]" id="galInput" accept="image/*" multiple>
        </div>
        <div id="galPreview" style="display:flex;gap:12px;flex-wrap:wrap;margin-top:10px"></div>
        <label style="display:inline-flex;align-items:center;gap:8px;margin-top:6px">
            <input type="checkbox" name="make_primary_from_gallery" value="1">
            Make the first newly uploaded image the primary image
        </label>
    </div>

    <div style="margin-top:6px">
        <button class="btn" type="submit">Save</button>
        <a href="/admin/products.php" class="btn secondary" style="margin-left:8px">Back</a>
    </div>
</form>
<script>
    // main image live preview
    document.getElementById('mainInput')?.addEventListener('change', (e)=>{
        const f = e.target.files?.[0];
        const img = document.getElementById('mainPrev');
        if (!f || !img) return;
        const r = new FileReader();
        r.onload = ev => { img.src = ev.target.result; img.style.display='block'; };
        r.readAsDataURL(f);
    });

    // gallery live previews
    document.getElementById('galInput')?.addEventListener('change', (e)=>{
        const box = document.getElementById('galPreview');
        if (!box) return;
        box.innerHTML = '';
        const files = Array.from(e.target.files || []);
        files.forEach(f=>{
            if (!/^image\//.test(f.type)) return;
            const r = new FileReader();
            r.onload = ev=>{
                const wrap = document.createElement('div');
                wrap.style.cssText = 'border:1px solid #eee;border-radius:10px;padding:6px';
                wrap.innerHTML = `<img src="${ev.target.result}" style="width:100px;height:100px;object-fit:cover;border-radius:8px">`;
                box.appendChild(wrap);
            };
            r.readAsDataURL(f);
        });
    });
</script>
