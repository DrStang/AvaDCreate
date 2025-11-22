<?php
require_once __DIR__.'/../lib/auth.php'; admin_required();
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/util.php';
require_once __DIR__.'/../lib/mail.php';

if ($_SERVER['REQUEST_METHOD']!=='POST' || !csrf_ok()) die('Bad request');

$oid = (int)($_POST['order_id'] ?? 0);
$trk = trim($_POST['tracking'] ?? '');

if ($oid) {
    // Get order + customer email
    $q = db()->prepare("SELECT o.id, o.customer_id, o.total_amount, o.status, o.tracking_number,
                             c.email, c.name,
                             p.name AS product_name, o.quantity, o.unit_price
                      FROM orders o
                      LEFT JOIN customers c ON c.id=o.customer_id
                      LEFT JOIN products p ON p.id=o.product_id
                      WHERE o.id=? LIMIT 1");
    $q->execute([$oid]);
    $o = $q->fetch();

    if ($o) {
        // Update order
        db()->prepare("UPDATE orders SET status='shipped', tracking_number=?, updated_at=NOW() WHERE id=?")
            ->execute([$trk ?: $o['tracking_number'], $oid]);

        // Send email if we have customer email
        if (!empty($o['email'])) {
            $subject = "Your order has shipped!";
            $html = '<div style="font-family:Arial;color:#111">
        <h2 style="color:#7a3d91;margin:0 0 10px">Good news — your order is on its way!</h2>
        <p>Item: '.h($o['product_name']).' &times; '.(int)$o['quantity'].'</p>
        <p>Total: $'.number_format((float)$o['unit_price']*(int)$o['quantity'],2).'</p>'.
                ($trk ? '<p>Tracking Number: <strong>'.h($trk).'</strong></p>' : '').
                '<p style="margin-top:12px;color:#555">Thank you for supporting handmade.</p>
        <p style="margin-top:12px;color:#555">— Ava D Creates</p>
      </div>';
            send_email($o['email'], $o['name'] ?: $o['email'], $subject, $html);
        }
    }
}

header('Location: /admin/orders.php');
exit;

