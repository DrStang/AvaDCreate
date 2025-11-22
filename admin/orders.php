<?php
// admin/orders.php
require_once __DIR__.'/../lib/auth.php'; admin_required();
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/util.php';
include __DIR__.'/_style.php';

// ---- Handle inline status update ----
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='update_status') {
    if (!csrf_ok()) { http_response_code(400); die('Bad CSRF'); }
    $oid = (int)($_POST['order_id'] ?? 0);
    $new = $_POST['new_status'] ?? 'pending';
    // Note: 'abandoned' NOT included here; admins can't set it manually
    $allowed = ['pending','processing','shipped','delivered','cancelled'];
    if ($oid && in_array($new, $allowed, true)) {
        $stmt = db()->prepare("UPDATE orders SET status=?, updated_at=NOW() WHERE id=?");
        $stmt->execute([$new, $oid]);
    }
    header('Location: /admin/orders.php?'.http_build_query([
            'status'=>$_GET['status'] ?? null,
            'sort'=>$_GET['sort'] ?? null,
            'dir'=>$_GET['dir'] ?? null,
            'page'=>$_GET['page'] ?? null
        ]));
    exit;
}

// ---- Filters / sorting / pagination ----
$status = $_GET['status'] ?? 'all';
$validStatuses = ['all','pending','processing','shipped','delivered','cancelled']; // no 'abandoned'
if (!in_array($status, $validStatuses, true)) $status = 'all';

$sort = $_GET['sort'] ?? 'created_at';
$dir  = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
$sortWhitelist = ['id','created_at','total_amount','status'];
if (!in_array($sort, $sortWhitelist, true)) $sort = 'created_at';

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page-1)*$perPage;

// ---- Build query (always hide abandoned)
$whereParts = ["o.status <> 'abandoned'"];
$params = [];

if ($status !== 'all') {
    $whereParts[] = "o.status = ?";
    $params[] = $status;
}

$where = 'WHERE '.implode(' AND ', $whereParts);

// Count for pagination (fixed)
$countSql  = "SELECT COUNT(*) AS c FROM orders o $where";
$countStmt = db()->prepare($countSql);
$countStmt->execute($params);
$total = (int)($countStmt->fetchColumn() ?: 0);

$sql = "
SELECT 
  o.id, o.customer_id, o.product_id, o.quantity, o.unit_price, o.total_amount,
  o.status, o.created_at,
  o.variant_json,
  p.name AS product_name,
  c.name AS customer_name, c.email AS customer_email
FROM orders o
LEFT JOIN products p ON p.id = o.product_id
LEFT JOIN customers c ON c.id = o.customer_id
$where
ORDER BY o.$sort $dir
LIMIT $perPage OFFSET $offset
";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

function badge(string $s): string {
    $map = [
        'pending'    => 'background:#fff7e6;border:2px solid #ffd699;color:#915700',
        'processing' => 'background:#eef2ff;border:2px solid #c7d2fe;color:#3730a3',
        'shipped'    => 'background:#ecfeff;border:2px solid #a5f3fc;color:#065f46',
        'delivered'  => 'background:#ecfdf5;border:2px solid #a7f3d0;color:#065f46',
        'cancelled'  => 'background:#fef2f2;border:2px solid #fecaca;color:#991b1b',
    ];
    $style = $map[$s] ?? 'background:#eee;border:2px solid #ddd;color:#333';
    return '<span style="display:inline-block;padding:6px 10px;border-radius:999px;font-weight:700;'.$style.'">'.htmlspecialchars(ucfirst($s)).'</span>';
}

function sortLink($label,$col){
    $cur = $_GET['sort'] ?? 'created_at';
    $dir = strtolower($_GET['dir'] ?? 'desc')==='asc' ? 'asc' : 'desc';
    $nextDir = ($cur===$col && $dir==='asc') ? 'desc' : 'asc';
    $q = $_GET; $q['sort']=$col; $q['dir']=$nextDir;
    return '<a href="?'.http_build_query($q).'" style="color:#6c3eb6;font-weight:700;text-decoration:none">'.$label.'</a>';
}

// ---- Header / Tabs
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
            <div class="admin-title">📃 Orders</div>
            <div class="admin-actions">
                <a class="tab" href="/admin/dashboard.php">📊 Stats </a>
                <a class="tab" href="/admin/products.php">🧾 Manage Products</a>
                <a class="tab " href="/admin/analytics.php"> Analytics</a>
                <a class="tab active" href="/admin/orders.php">📃 Orders</a>
                <a class="tab" href="/admin/product_edit.php">➕ Add Product</a>
            </div>
        </div>
    </div>
</div>

<div class="section">
    <div class="card" style="overflow:auto">
        <!-- Filter pills -->
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px">
            <?php
            foreach (['all'=>'All','pending'=>'Pending','processing'=>'Processing','shipped'=>'Shipped','delivered'=>'Delivered','cancelled'=>'Cancelled'] as $k=>$label){
                $is = $status===$k ? 'active' : '';
                $q = $_GET; $q['status']=$k; $q['page']=1;
                echo '<a class="tab '.$is.'" href="?'.htmlspecialchars(http_build_query($q)).'">'.htmlspecialchars($label).'</a>';
            }
            ?>
        </div>

        <table border="0" cellspacing="0" cellpadding="10" style="width:100%;border-collapse:separate;border-spacing:0 10px">
            <thead>
            <tr style="text-align:left;color:#6b7280">
                <th><?= sortLink('Order #','id') ?></th>
                <th>Customer</th>
                <th>Product</th>
                <th>Qty</th>
                <th><?= sortLink('Amount','total_amount') ?></th>
                <th><?= sortLink('Status','status') ?></th>
                <th><?= sortLink('Created','created_at') ?></th>
                <th style="text-align:right">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="8" style="padding:18px;color:#6b7280">No orders found.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr style="background:#fff;box-shadow:0 8px 18px rgba(20,16,37,.06);border-radius:14px">
                    <td>#<?= (int)$r['id'] ?></td>
                    <td>
                        <?= h($r['customer_name'] ?? 'Guest') ?>
                        <?php if (!empty($r['customer_email'])): ?>
                            <div style="color:#6b7280;font-size:12px"><?= h($r['customer_email']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div><?= h($r['product_name'] ?: ('Product #'.$r['product_id'])) ?></div>
                        <?php
                        // Show variant (e.g., "Primary color: Pink")
                        if (!empty($r['variant_json'])) {
                            $vj = json_decode($r['variant_json'], true);
                            if (is_array($vj) && $vj) {
                                $pairs = [];
                                foreach ($vj as $k => $v) {
                                    if ($v === '' || $v === null) continue;
                                    $pairs[] = h($k).': '.h($v);
                                }
                                if ($pairs) {
                                    echo '<div style="color:#6b7280;font-size:12px;margin-top:2px">'
                                        . implode(', ', $pairs)
                                        . '</div>';
                                }
                            }
                        }
                        ?>
                    </td>

                    <td><?= (int)$r['quantity'] ?></td>
                    <td><strong>$<?= money((float)$r['total_amount']) ?></strong></td>
                    <td><?= badge($r['status']) ?></td>
                    <td><?= h(date('Y-m-d H:i', strtotime($r['created_at']))) ?></td>
                    <td style="text-align:right">
                        <form method="post" style="display:inline-flex;gap:6px;align-items:center">
                            <a class="btn-outline" href="/admin/order_view.php?id=<?= (int)$r['id'] ?>" style="margin-left:6px">View</a>
                            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="order_id" value="<?= (int)$r['id'] ?>">
                            <select name="new_status" style="padding:8px;border-radius:10px;border:2px solid #8a46cc33">
                                <?php foreach (['pending','processing','shipped','delivered','cancelled'] as $opt): ?>
                                    <option value="<?= $opt ?>" <?= $opt===$r['status']?'selected':'' ?>><?= ucfirst($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn-outline" type="submit">Update</button>
                        </form>
                        <form method="post" action="/admin/order_ship.php" style="display:inline-flex;gap:6px;align-items:center;margin-left:6px">
                            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                            <input type="hidden" name="order_id" value="<?= (int)$r['id'] ?>">
                            <input type="text" name="tracking" placeholder="Tracking #" style="padding:8px;border:2px solid #8a46cc33;border-radius:10px;width:160px">
                            <button class="btn-outline" type="submit">Mark Shipped & Notify</button>
                        </form>
                    </td>

                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php
        $pages = max(1, (int)ceil($total / $perPage));
        if ($pages > 1):
            echo '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">';
            for ($i=1; $i<=$pages; $i++){
                $q = $_GET; $q['page']=$i;
                $is = $i===$page ? 'active' : '';
                echo '<a class="tab '.$is.'" href="?'.htmlspecialchars(http_build_query($q)).'">'.$i.'</a>';
            }
            echo '</div>';
        endif;
        ?>
    </div>
</div>
