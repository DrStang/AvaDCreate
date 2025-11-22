<?php
require_once __DIR__.'/../lib/auth.php'; admin_required();
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/util.php';
include __DIR__.'/_style.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: /admin/orders.php'); exit; }

$sql = "SELECT o.*, p.name AS product_name, c.name AS customer_name, c.email AS customer_email
        FROM orders o
        LEFT JOIN products p ON p.id=o.product_id
        LEFT JOIN customers c ON c.id=o.customer_id
        WHERE o.id=? LIMIT 1";
$stmt = db()->prepare($sql); $stmt->execute([$id]);
$o = $stmt->fetch();
if (!$o) { die('Order not found'); }

$stmt = db()->prepare($sql); $stmt->execute([$id]);
$o = $stmt->fetch();
// Variant pretty string (e.g., "Primary color: Pink")
$variantPairs = [];
if (!empty($o['variant_json'])) {
    $vj = json_decode($o['variant_json'], true);
    if (is_array($vj)) {
        foreach ($vj as $k => $v) {
            if ($v === '' || $v === null) continue;
            $variantPairs[] = h($k) . ': ' . h($v);
        }
    }
}
$variantHtml = $variantPairs
    ? '<div style="color:#6b7280;font-size:12px;margin-top:2px">'
    . implode(', ', $variantPairs)
    . '</div>'
    : '';

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
?>
<div class="admin-header">
    <div class="admin-wrap">
        <div class="admin-brand">✨ Ava D Creates · Admin</div>
        <div style="display:flex;gap:10px">
            <a class="btn-outline" href="/admin/orders.php">Back to Orders</a>
            <a class="btn-outline" href="/admin/logout.php">Logout</a>
        </div>
    </div>
</div>

<div class="admin-hero">
    <div class="admin-hero-inner">
        <div class="admin-hero-card">
            <div class="admin-title">Order #<?= (int)$o['id'] ?> <?= badge($o['status']) ?></div>
            <div class="admin-actions">
                <a class="tab" href="/admin/dashboard.php">📊 Analytics</a>
                <a class="tab" href="/admin/products.php">🧾 Manage Products</a>
                <a class="tab active" href="/admin/orders.php">📃 Orders</a>
            </div>
        </div>
    </div>
</div>

<div class="section" style="display:grid;grid-template-columns:1.2fr .8fr;gap:16px">
    <div class="card">
        <h3 style="margin-top:0">Items</h3>
        <table width="100%" cellpadding="8" cellspacing="0" style="border-collapse:collapse">
            <tr style="color:#6b7280"><th align="left">Product</th><th align="center">Qty</th><th align="right">Unit</th><th align="right">Line Total</th></tr>
            <tr>
                <?= h($o['product_name'] ?: ('Product #'.$o['product_id'])) ?>
                <?= $variantHtml ?>
                <td align="center"><?= (int)$o['quantity'] ?></td>
                <td align="right">$<?= money((float)$o['unit_price']) ?></td>
                <td align="right"><strong>$<?= money((float)$o['total_amount']) ?></strong></td>
            </tr>
        </table>
        <div style="text-align:right;margin-top:10px"><strong>Order Total: $<?= money((float)$o['total_amount']) ?></strong></div>
    </div>

    <div style="display:flex;flex-direction:column;gap:16px">
        <div class="card">
            <h3 style="margin-top:0">Buyer</h3>
            <div><strong><?= h($o['customer_name'] ?? 'Guest') ?></strong></div>
            <?php if (!empty($o['customer_email'])): ?>
                <div style="color:#6b7280"><?= h($o['customer_email']) ?></div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h3 style="margin-top:0">Ship To</h3>
            <pre style="white-space:pre-wrap;font-family:inherit;margin:0"><?= h($o['shipping_address'] ?? '—') ?></pre>
        </div>

        <div class="card">
            <h3 style="margin-top:0">Fulfillment</h3>
            <form method="post" action="/admin/order_ship.php" style="display:flex;flex-direction:column;gap:10px">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                <label>Tracking Number
                    <input type="text" name="tracking" value="<?= h($o['tracking_number'] ?? '') ?>" style="padding:10px;border-radius:10px;border:2px solid #8a46cc33;width:100%">
                </label>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <button class="btn-outline" type="submit">Mark Shipped & Notify</button>
                    <a class="btn-outline" href="/admin/orders.php">Back</a>
                </div>
            </form>
        </div>
    </div>
</div>
