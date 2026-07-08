<?php
require_once __DIR__.'/../lib/auth.php'; admin_required();
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/util.php';
require_once __DIR__.'/../lib/reviews.php';

$nav_active = 'reviews';

/** ---------- POST actions ---------- */
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { http_response_code(400); die('Bad CSRF'); }
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'update') {
        $id     = (int)($_POST['id'] ?? 0);
        $name   = trim($_POST['author_name'] ?? '');
        $rating = max(1, min(5, (int)($_POST['rating'] ?? 5)));
        $title  = trim($_POST['title'] ?? '');
        $body   = trim($_POST['body'] ?? '');
        $status = in_array($_POST['status'] ?? '', ['pending','approved','hidden'], true) ? $_POST['status'] : 'approved';
        $source = in_array($_POST['source'] ?? '', ['site','etsy','manual'], true) ? $_POST['source'] : 'manual';
        $pid    = ($_POST['product_id'] ?? '') !== '' ? (int)$_POST['product_id'] : null;

        if ($name === '' || $body === '') {
            $flash = ['type'=>'error','msg'=>'Name and review text are required.'];
        } elseif ($action === 'add') {
            db()->prepare(
                "INSERT INTO reviews (product_id, author_name, rating, title, body, source, status, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,NOW(),NOW())"
            )->execute([$pid, $name, $rating, ($title ?: null), $body, $source, $status]);
            $flash = ['type'=>'ok','msg'=>'Review added.'];
        } else {
            db()->prepare(
                "UPDATE reviews SET product_id=?, author_name=?, rating=?, title=?, body=?, source=?, status=?, updated_at=NOW()
                 WHERE id=?"
            )->execute([$pid, $name, $rating, ($title ?: null), $body, $source, $status, $id]);
            $flash = ['type'=>'ok','msg'=>'Review updated.'];
        }
    } elseif ($action === 'set_status') {
        $id = (int)($_POST['id'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['pending','approved','hidden'], true) ? $_POST['status'] : 'pending';
        db()->prepare("UPDATE reviews SET status=?, updated_at=NOW() WHERE id=?")->execute([$status, $id]);
        $flash = ['type'=>'ok','msg'=>'Status updated.'];
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare("DELETE FROM reviews WHERE id=?")->execute([$id]);
        $flash = ['type'=>'ok','msg'=>'Review deleted.'];
    }
}

/** ---------- Data ---------- */
$filter = $_GET['status'] ?? 'all';
$editId = (int)($_GET['edit'] ?? 0);

$sql = "SELECT r.*, p.name AS product_name FROM reviews r LEFT JOIN products p ON p.id=r.product_id";
$args = [];
if (in_array($filter, ['pending','approved','hidden'], true)) {
    $sql .= " WHERE r.status=?"; $args[] = $filter;
}
$sql .= " ORDER BY (r.status='pending') DESC, r.created_at DESC";
$stmt = db()->prepare($sql); $stmt->execute($args);
$rows = $stmt->fetchAll();

$editRow = null;
if ($editId) {
    $e = db()->prepare("SELECT * FROM reviews WHERE id=?"); $e->execute([$editId]);
    $editRow = $e->fetch() ?: null;
}

$products = db()->query("SELECT id, name FROM products ORDER BY name")->fetchAll();

$counts = ['pending'=>0,'approved'=>0,'hidden'=>0];
foreach (db()->query("SELECT status, COUNT(*) c FROM reviews GROUP BY status")->fetchAll() as $c) {
    if (isset($counts[$c['status']])) $counts[$c['status']] = (int)$c['c'];
}

include __DIR__.'/_style.php';

function rv_status_badge(string $s): string {
    $map = [
        'approved' => ['#e8f8ee','#166534','Approved'],
        'pending'  => ['#fff4e0','#9a5b00','Pending'],
        'hidden'   => ['#eee','#555','Hidden'],
    ];
    [$bg,$fg,$label] = $map[$s] ?? ['#eee','#555',ucfirst($s)];
    return '<span style="display:inline-block;padding:4px 10px;border-radius:999px;font-weight:700;font-size:12px;background:'.$bg.';color:'.$fg.'">'.$label.'</span>';
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
            <div class="admin-title">⭐ Reviews</div>
            <div class="admin-actions">
                <a class="tab" href="/admin/dashboard.php">📊 Stats</a>
                <a class="tab" href="/admin/products.php">🧾 Manage Products</a>
                <a class="tab" href="/admin/analytics.php">📈 Analytics</a>
                <a class="tab" href="/admin/orders.php">📃 Orders</a>
                <a class="tab active" href="/admin/reviews.php">⭐ Reviews</a>
                <a class="tab" href="/admin/email_customers.php">✉ Email Customers</a>
                <a class="tab" href="/admin/product_edit.php">➕ Add Product</a>
            </div>
        </div>
    </div>
</div>

<style>
    .rv-flash{max-width:1200px;margin:0 auto 8px;padding:0 18px}
    .rv-flash .box{border-radius:12px;padding:12px 14px;font-weight:600}
    .rv-flash .ok{background:#e8f8ee;color:#166534}
    .rv-flash .error{background:#fee2e2;color:#991b1b}
    .rv-two{max-width:1200px;margin:0 auto;padding:0 18px;display:grid;grid-template-columns:1.4fr 1fr;gap:18px;align-items:start}
    @media (max-width:980px){ .rv-two{grid-template-columns:1fr} }
    .rv-list .row{border-bottom:1px solid #eee;padding:14px 0}
    .rv-list .row:last-child{border-bottom:0}
    .rv-stars{color:#e8a52a;letter-spacing:2px}
    .rv-stars .stars-empty{color:#e5cbb0}
    .rv-list .body{color:#333;margin:6px 0;line-height:1.45}
    .rv-list .meta{color:var(--admin-muted);font-size:13px}
    .rv-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
    .rv-mini{padding:7px 12px;border-radius:10px;border:2px solid var(--admin-ring);background:#fff;color:#6c3eb6;font-weight:700;cursor:pointer;font-size:13px}
    .rv-mini.danger{color:#991b1b;border-color:#f2c4c4}
    .rv-mini.solid{background:linear-gradient(135deg,var(--admin-grad-a),var(--admin-grad-b));color:#fff;border-color:transparent}
    .rv-form label{display:block;font-weight:700;margin:12px 0 5px;color:#3b1c46}
    .rv-form input[type=text],.rv-form textarea,.rv-form select{width:100%;padding:10px 12px;border:2px solid var(--admin-ring);border-radius:10px;font:inherit}
    .rv-form textarea{min-height:110px;resize:vertical}
    .filter-pills{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px}
</style>

<?php if ($flash): ?>
    <div class="rv-flash"><div class="box <?= $flash['type']==='ok'?'ok':'error' ?>"><?= h($flash['msg']) ?></div></div>
<?php endif; ?>

<div class="rv-two">
    <!-- LEFT: list -->
    <div class="section" style="margin-top:0;padding:0">
        <div class="card">
            <div class="filter-pills">
                <?php
                $fdefs = ['all'=>'All','pending'=>'Pending ('.$counts['pending'].')','approved'=>'Approved ('.$counts['approved'].')','hidden'=>'Hidden ('.$counts['hidden'].')'];
                foreach ($fdefs as $k=>$label):
                    $is = $filter===$k ? 'active' : '';
                ?>
                    <a class="tab <?= $is ?>" href="?status=<?= $k ?>" style="padding:8px 14px"><?= h($label) ?></a>
                <?php endforeach; ?>
            </div>

            <div class="rv-list">
                <?php if (!$rows): ?>
                    <p style="color:var(--admin-muted)">No reviews in this view yet.</p>
                <?php else: foreach ($rows as $r): ?>
                    <div class="row">
                        <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap">
                            <div>
                                <span class="rv-stars"><?= review_stars_html((int)$r['rating']) ?></span>
                                <strong style="margin-left:6px"><?= h($r['author_name']) ?></strong>
                                <?php if (!empty($r['title'])): ?> — <?= h($r['title']) ?><?php endif; ?>
                            </div>
                            <?= rv_status_badge($r['status']) ?>
                        </div>
                        <div class="body"><?= nl2br(h($r['body'])) ?></div>
                        <div class="meta">
                            <?= h(date('Y-m-d', strtotime($r['created_at']))) ?>
                            · <?= h(ucfirst($r['source'])) ?>
                            <?php if (!empty($r['product_name'])): ?> · <?= h($r['product_name']) ?><?php endif; ?>
                        </div>
                        <div class="rv-actions">
                            <form method="post" style="display:inline">
                                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                <input type="hidden" name="action" value="set_status">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <?php if ($r['status']!=='approved'): ?>
                                    <button class="rv-mini solid" name="status" value="approved">✓ Approve</button>
                                <?php endif; ?>
                                <?php if ($r['status']!=='hidden'): ?>
                                    <button class="rv-mini" name="status" value="hidden">Hide</button>
                                <?php endif; ?>
                                <?php if ($r['status']!=='pending'): ?>
                                    <button class="rv-mini" name="status" value="pending">Un-approve</button>
                                <?php endif; ?>
                            </form>
                            <a class="rv-mini" href="?edit=<?= (int)$r['id'] ?><?= $filter!=='all'?'&status='.h($filter):'' ?>">Edit</a>
                            <form method="post" style="display:inline" onsubmit="return confirm('Delete this review permanently?')">
                                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="rv-mini danger">Delete</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <!-- RIGHT: add / edit form -->
    <div class="section" style="margin-top:0;padding:0">
        <div class="card rv-form">
            <h3 style="margin:0 0 4px;color:#6c3eb6"><?= $editRow ? 'Edit review' : 'Add a review' ?></h3>
            <p style="color:var(--admin-muted);margin:0 0 6px;font-size:14px">
                <?= $editRow ? 'Update any field below.' : 'Use this to bring over your two Etsy reviews.' ?>
            </p>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'add' ?>">
                <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>"><?php endif; ?>

                <label>Reviewer name</label>
                <input type="text" name="author_name" maxlength="120" required
                       value="<?= h($editRow['author_name'] ?? '') ?>">

                <label>Rating</label>
                <select name="rating">
                    <?php for ($s=5;$s>=1;$s--): $sel = ((int)($editRow['rating'] ?? 5)===$s)?'selected':''; ?>
                        <option value="<?= $s ?>" <?= $sel ?>><?= str_repeat('★',$s).str_repeat('☆',5-$s) ?> (<?= $s ?>)</option>
                    <?php endfor; ?>
                </select>

                <label>Title <span style="font-weight:500;color:var(--admin-muted)">(optional)</span></label>
                <input type="text" name="title" maxlength="160" value="<?= h($editRow['title'] ?? '') ?>">

                <label>Review text</label>
                <textarea name="body" maxlength="2000" required><?= h($editRow['body'] ?? '') ?></textarea>

                <label>Product <span style="font-weight:500;color:var(--admin-muted)">(optional)</span></label>
                <select name="product_id">
                    <option value="">— not tied to a product —</option>
                    <?php foreach ($products as $p):
                        $sel = ((int)($editRow['product_id'] ?? 0)===(int)$p['id'])?'selected':''; ?>
                        <option value="<?= (int)$p['id'] ?>" <?= $sel ?>><?= h($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div>
                        <label>Source</label>
                        <select name="source">
                            <?php foreach (['manual'=>'Manual','etsy'=>'Etsy','site'=>'Site'] as $v=>$lbl):
                                $sel = (($editRow['source'] ?? 'manual')===$v)?'selected':''; ?>
                                <option value="<?= $v ?>" <?= $sel ?>><?= $lbl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Status</label>
                        <select name="status">
                            <?php foreach (['approved'=>'Approved','pending'=>'Pending','hidden'=>'Hidden'] as $v=>$lbl):
                                $sel = (($editRow['status'] ?? 'approved')===$v)?'selected':''; ?>
                                <option value="<?= $v ?>" <?= $sel ?>><?= $lbl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="margin-top:16px;display:flex;gap:10px">
                    <button class="rv-mini solid" style="padding:11px 18px;font-size:15px" type="submit">
                        <?= $editRow ? 'Save changes' : 'Add review' ?>
                    </button>
                    <?php if ($editRow): ?>
                        <a class="rv-mini" style="padding:11px 18px;font-size:15px" href="/admin/reviews.php">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>
