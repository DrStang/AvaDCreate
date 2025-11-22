<?php require_once __DIR__.'/../lib/db.php'; require_once __DIR__.'/../lib/util.php'; include __DIR__.'/../partials/header.php';
$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare("SELECT * FROM products WHERE id=? LIMIT 1"); $stmt->execute([$id]); $p = $stmt->fetch();

$g = db()->prepare("SELECT image_url, is_primary FROM product_images WHERE product_id=? ORDER BY is_primary DESC, sort_order ASC, id ASC");
$g->execute([$p['id']]);
$gallery = $g->fetchAll();

$mainUrl  = $p['image_url'] ?: ($gallery[0]['image_url'] ?? '/uploads/placeholder.jpg');
$images   = array_values(array_unique(array_merge([$mainUrl], array_map(fn($r)=>$r['image_url'], $gallery))));
$activeIx = 0; // start on first

$stock = isset($p['stock']) ? (int)$p['stock'] : 0;
$low_threshold = 3;
$out_of_stock  = ($stock <= 0);
$low_stock     = (!$out_of_stock && $stock <= $low_threshold);
require_once __DIR__ . '/../lib/analytics.php';
analytics_log('product_view', (int)$p['id'], $_SESSION['user_id'] ?? null);

// Load variants (group by option_name)
$vs = db()->prepare("SELECT option_name, value FROM product_variants WHERE product_id=? ORDER BY sort_order, value");
$vs->execute([$p['id']]);
$variantRows = $vs->fetchAll(PDO::FETCH_ASSOC);
$variantGroups = [];
foreach ($variantRows as $vr) {
    $variantGroups[$vr['option_name']][] = $vr['value'];
}

if (!$p) { echo "<p>Product not found.</p>"; include __DIR__.'/../partials/footer.php'; exit; }

?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;max-width:900px">
    <style>
        /* widen the page container so we can use that right-side space */
        .pd-wrap{
            max-width: 1440px;    /* was 1100px */
            margin: 0 auto;
            padding: 24px;
        }

        /* force a healthy minimum width for the gallery, and a bigger right column */
        .pd-grid{
            display: grid;
            /* left column will NEVER go below 520px on desktop;
               right column gets more space so text isn't cramped */
            grid-template-columns: minmax(520px, 1fr) minmax(560px, 1.2fr);
            gap: 48px;
            align-items: start;
        }
        .gallery-card{
            background:#fff;
            border-radius:18px;
            padding:16px;
            box-shadow:0 14px 34px rgba(20,16,37,.08);
            min-width: 520px;        /* 👈 important: prevents the skinny rail */
        }

        /* keep height even while swapping; images contain within */
        /* keep the hero image generous; full width of the gallery column */
        .carousel{
            position: relative;
            border-radius: 14px;
            overflow: hidden;
            background:#faf9f6;
            border:1px solid #eee;
            width: 100%;             /* 👈 make sure it fills the column */
            height: 520px;           /* keep it tall enough to feel substantial */
        }
        .carousel img{
            display: block;
            width: 100%;
            height: 100%;
            object-fit: contain;     /* no cropping; maximizes usable area */
        }
        /* mobile: stack column and drop the hard min-width so it can shrink */
        @media (max-width: 980px){
            .pd-grid{
                grid-template-columns: 1fr;
                gap: 22px;
            }
            .gallery-card{ min-width: 0; }
            .carousel{ height: 420px; }
        }
        /* arrows */
        .nav-btn{
            position:absolute;top:50%;transform:translateY(-50%);
            width:44px;height:44px;border-radius:999px;border:2px solid #8a46cc33;
            background:#fff;display:flex;align-items:center;justify-content:center;
            box-shadow:0 10px 22px rgba(20,16,37,.12);cursor:pointer;
            user-select:none; font-size:20px; line-height:1;
        }
        .nav-btn:hover{background:#f3e9ff}
        .nav-btn.prev{left:10px} .nav-btn.next{right:10px}

        /* thumbs strip */
        .thumbs{
            display:flex;gap:12px;margin-top:14px;overflow-x:auto;padding-bottom:6px;
        }
        .thumb{flex:0 0 auto;width:90px;height:90px;border-radius:12px;border:2px solid #eee;
            background:#fff;box-shadow:0 8px 18px rgba(20,16,37,.05);cursor:pointer}
        .thumb img{width:100%;height:100%;object-fit:cover;border-radius:10px}
        .thumb.active{border-color:#7a3d91}
        .thumb:hover{transform:translateY(-2px)}

        /* right side card */
        .buy-card{
            background:#fff;
            border-radius:18px;
            padding:28px;
            box-shadow:0 14px 34px rgba(20,16,37,.08);
            max-width: 640px;        /* feels nice for text; adjust if you prefer */
        }
        .price-pill{display:inline-block;background:#d8b4e2;color:#3b1c46;padding:6px 12px;border-radius:999px;font-weight:800}
        /* Lightbox */
        .lb-backdrop{
            position:fixed; inset:0; background:rgba(0,0,0,.85);
            display:none; z-index:1000;
        }
        .lb-backdrop.show{ display:block; }
        .lb-wrap{ position:absolute; inset:0; display:flex; flex-direction:column; }
        .lb-main{ flex:1; display:flex; align-items:center; justify-content:center; padding:24px; }
        .lb-main img{ max-width:92vw; max-height:86vh; object-fit:contain; }
        .lb-close, .lb-prev, .lb-next{
            position:absolute; top:18px;
            background:#ffffff; color:#333; border:none; border-radius:999px; width:42px; height:42px;
            display:flex; align-items:center; justify-content:center; cursor:pointer;
            box-shadow:0 10px 22px rgba(0,0,0,.25);
        }
        .lb-close{ right:18px; font-size:18px }
        .lb-prev, .lb-next{ top:50%; transform:translateY(-50%); font-size:20px }
        .lb-prev{ left:18px } .lb-next{ right:18px }
        .lb-thumbs{ display:flex; gap:10px; overflow-x:auto; padding:12px 18px 18px; background:rgba(255,255,255,.06) }
        .lb-thumb{ flex:0 0 auto; width:86px; height:86px; border-radius:10px; border:2px solid transparent; background:#111 }
        .lb-thumb img{ width:100%; height:100%; object-fit:cover; border-radius:8px }
        .lb-thumb.active{ border-color:#a78bfa }
    </style>

    <div class="pd-wrap">
        <div class="pd-grid">

            <!-- Left: Gallery -->
            <div class="gallery-card">
                <div class="carousel">
                    <button class="nav-btn prev" type="button" aria-label="Previous">‹</button>
                    <img id="mainPhoto" src="<?= h($images[$activeIx]) ?>" alt="<?= h($p['name']) ?>">
                    <button class="nav-btn next" type="button" aria-label="Next">›</button>
                </div>

                <?php if (count($images) > 1): ?>
                    <div class="thumbs" id="thumbs">
                        <?php foreach ($images as $i=>$url): ?>
                            <div class="thumb <?= $i===$activeIx?'active':'' ?>" data-ix="<?= $i ?>"><img src="<?= h($url) ?>" alt="thumb"></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right: Buy panel (keep your existing content here) -->
            <div class="buy-card">
                <h1 style="margin:0 0 10px 0;line-height:1.1"><?= h($p['name']) ?></h1>
                <?php if ($out_of_stock): ?>
                    <div style="display:inline-block;background:#fee2e2;color:#991b1b;border:1px solid #fecaca;padding:6px 10px;border-radius:999px;font-weight:600">
                        Out of stock
                    </div>
                <?php elseif ($low_stock): ?>
                    <div style="display:inline-block;background:#f3e8ff;color:#6b21a8;border:1px solid #e9d5ff;padding:6px 10px;border-radius:999px;font-weight:600">
                        Only <?= $stock ?> left!
                    </div>
                <?php endif; ?>
                <div class="price-pill" style="margin:6px 0">$<?= money((float)$p['price']) ?></div>
                <p style="margin-top:12px"><?= nl2br(h($p['description'] ?? '')) ?></p>


                <!-- Qty + Add to Cart -->
                <form method="post" action="/cart.php" style="margin-top:18px;display:flex;flex-direction:column;gap:10px;max-width:360px">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">

                    <?php if (!empty($variantGroups)): ?>
                        <?php foreach ($variantGroups as $optName => $values): ?>
                            <label class="text-sm"><?= h($optName) ?></label>
                            <select name="variant[<?= h($optName) ?>]" class="border rounded-lg px-3 py-2">
                                <option value="">Select <?= h($optName) ?></option>
                                <?php foreach ($values as $val): ?>
                                    <option value="<?= h($val) ?>"><?= h($val) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <div style="display:flex;align-items:center;gap:8px">
                        <label for="qty">Qty:</label>
                        <input id="qty" name="qty" type="number" value="1" min="1"
                            <?= $out_of_stock ? 'disabled' : '' ?>
                            <?= !$out_of_stock ? 'max="'.(int)$stock.'"' : '' ?>
                               style="width:72px;padding:6px 8px;border:1px solid #ddd;border-radius:8px">
                        <button type="submit" class="btn" <?= $out_of_stock ? 'disabled aria-disabled="true" style="opacity:.6;cursor:not-allowed"' : '' ?>>
                            Add to Cart
                        </button>
                    </div>
                </form>

            </div>

        </div>
    </div>
    <div class="lb-backdrop" id="lightbox" aria-hidden="true">
        <div class="lb-wrap">
            <button class="lb-close" type="button" aria-label="Close">✕</button>
            <button class="lb-prev"  type="button" aria-label="Previous">‹</button>
            <div class="lb-main"><img id="lbImg" src="<?= h($images[0] ?? '') ?>" alt=""></div>
            <button class="lb-next"  type="button" aria-label="Next">›</button>
            <div class="lb-thumbs" id="lbThumbs">
                <?php foreach ($images as $i=>$u): ?>
                    <div class="lb-thumb <?= $i===0?'active':'' ?>" data-ix="<?= $i ?>"><img src="<?= h($u) ?>" alt=""></div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <script>
        (function(){
            const imgs = <?= json_encode($images, JSON_UNESCAPED_SLASHES) ?>;
            let ix = <?= (int)$activeIx ?>;
            const main = document.getElementById('mainPhoto');
            const thumbs = document.querySelectorAll('#thumbs .thumb');

            function set(ixNew){
                ix = (ixNew + imgs.length) % imgs.length;
                main.style.opacity = 0.35;
                const url = imgs[ix];
                // Preload then swap for nicer fade
                const tmp = new Image();
                tmp.onload = () => { main.src = url; main.style.opacity = 1; };
                tmp.src = url;

                thumbs.forEach(t => t.classList.remove('active'));
                const active = document.querySelector(`#thumbs .thumb[data-ix="${ix}"]`);
                if (active) active.classList.add('active');
            }

            const prev = document.querySelector('.nav-btn.prev');
            const next = document.querySelector('.nav-btn.next');
            if (prev) prev.addEventListener('click', () => set(ix-1));
            if (next) next.addEventListener('click', () => set(ix+1));

            thumbs.forEach(t => t.addEventListener('click', () => set(parseInt(t.dataset.ix,10))));

            // keyboard arrows
            window.addEventListener('keydown', (e)=>{
                if (e.key === 'ArrowLeft') set(ix-1);
                if (e.key === 'ArrowRight') set(ix+1);
            });

            // simple touch swipe
            let sx=0, sy=0;
            main.addEventListener('touchstart', e => { sx=e.touches[0].clientX; sy=e.touches[0].clientY; }, {passive:true});
            main.addEventListener('touchend', e => {
                const dx = e.changedTouches[0].clientX - sx;
                const dy = Math.abs(e.changedTouches[0].clientY - sy);
                if (Math.abs(dx) > 40 && dy < 50) set(ix + (dx < 0 ? 1 : -1));
            }, {passive:true});
        })();
    </script>

    <script>
        (function(){
            const imgs = <?= json_encode($images, JSON_UNESCAPED_SLASHES) ?>;
            if (!imgs.length) return;

            // open lightbox when clicking the main image
            const main = document.getElementById('mainPhoto');
            const lb   = document.getElementById('lightbox');
            const lbImg= document.getElementById('lbImg');
            const lbThumbs = document.querySelectorAll('#lbThumbs .lb-thumb');
            const btnClose = lb.querySelector('.lb-close');
            const btnPrev  = lb.querySelector('.lb-prev');
            const btnNext  = lb.querySelector('.lb-next');
            let lix = 0;

            function lbSet(i){
                lix = (i + imgs.length) % imgs.length;
                lbImg.src = imgs[lix];
                lbThumbs.forEach(t=>t.classList.remove('active'));
                const act = document.querySelector(`.lb-thumb[data-ix="${lix}"]`);
                if (act) act.classList.add('active');
            }
            function lbOpen(startIndex=0){
                lb.classList.add('show'); document.body.style.overflow='hidden';
                lbSet(startIndex);
            }
            function lbClose(){
                lb.classList.remove('show'); document.body.style.overflow='';
            }

            main.addEventListener('click', ()=>{
                // try to match current carousel image index
                const cur = imgs.indexOf(main.src.replace(location.origin,''));
                lbOpen(cur >= 0 ? cur : 0);
            });

            btnClose.addEventListener('click', lbClose);
            btnPrev.addEventListener('click', ()=>lbSet(lix-1));
            btnNext.addEventListener('click', ()=>lbSet(lix+1));
            lbThumbs.forEach(t=>t.addEventListener('click', ()=>lbSet(parseInt(t.dataset.ix,10))));

            // keyboard
            window.addEventListener('keydown', e=>{
                if (!lb.classList.contains('show')) return;
                if (e.key === 'Escape') lbClose();
                if (e.key === 'ArrowLeft') lbSet(lix-1);
                if (e.key === 'ArrowRight') lbSet(lix+1);
            });

            // swipe
            let sx=0, sy=0;
            lbImg.addEventListener('touchstart', e => { sx=e.touches[0].clientX; sy=e.touches[0].clientY; }, {passive:true});
            lbImg.addEventListener('touchend', e => {
                const dx = e.changedTouches[0].clientX - sx;
                const dy = Math.abs(e.changedTouches[0].clientY - sy);
                if (Math.abs(dx) > 40 && dy < 50) lbSet(lix + (dx < 0 ? 1 : -1));
            }, {passive:true});
        })();
    </script>


</div>
<?php include __DIR__.'/../partials/footer.php'; ?>
