<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/mail.php';

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

// --- Helpers ---
function addr_get($obj, string $k): string {
    if (is_object($obj) && isset($obj->$k)) return (string)$obj->$k;
    if ($obj instanceof ArrayAccess && isset($obj[$k])) return (string)$obj[$k];
    if (is_array($obj) && isset($obj[$k])) return (string)$obj[$k];
    return '';
}
function format_address_text($addr): string {
    if (!$addr) return '';
    $line1 = addr_get($addr,'line1');
    $line2 = addr_get($addr,'line2');
    $city  = addr_get($addr,'city');
    $state = addr_get($addr,'state');
    $zip   = addr_get($addr,'postal_code');
    $ctry  = addr_get($addr,'country');
    $lineA = implode(', ', array_filter([$line1, $line2]));
    $lineB = trim(implode(', ', array_filter([$city, $state])));
    $lineC = trim(implode(' ',  array_filter([$zip, $ctry])));
    return trim(implode("\n", array_filter([$lineA, $lineB, $lineC])));
}

// --- Verify event ---
$payload    = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
try {
    $event = \Stripe\Webhook::constructEvent($payload, $sig_header, STRIPE_WEBHOOK_SECRET);
} catch (\Throwable $e) {
    http_response_code(400);
    echo 'Invalid webhook'; exit;
}

// --- We primarily handle checkout.session.completed ---
$type = $event->type;
$data = $event->data->object;

$session = null;
$piId = null;

if ($type === 'checkout.session.completed') {
    $sessionId = $data->id;
    // Re-fetch expanded so address fields are present
    $session = \Stripe\Checkout\Session::retrieve($sessionId, [
        'expand' => [
            'customer_details',
            'collected_information',
            'payment_intent',
            'payment_intent.latest_charge',
        ],
    ]);
    $piId = $session->payment_intent instanceof \Stripe\PaymentIntent
        ? $session->payment_intent->id
        : ($session->payment_intent ?? null);
} elseif (str_starts_with($type, 'payment_intent.')) {
    // Minimal fallback: try to resolve the Session from the PI
    $piId = $data->id ?? null;
    if ($piId) {
        $list = \Stripe\Checkout\Session::all(['payment_intent' => $piId, 'limit' => 1]);
        if (!empty($list->data[0])) {
            $session = \Stripe\Checkout\Session::retrieve($list->data[0]->id, [
                'expand' => [
                    'customer_details',
                    'collected_information',
                    'payment_intent',
                    'payment_intent.latest_charge',
                ],
            ]);
        }
    }
} else {
    // Ignore other event types
    http_response_code(200);
    echo json_encode(['ok' => true]); exit;
}

// If we still have no Session, nothing to do
if (!$session) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'note' => 'no session']); exit;
}

// --- Extract identity + address ---
$clientRef = $session->client_reference_id ?? null;

$email = $session->customer_details->email ?? null;
$name  = $session->customer_details->name  ?? null;
$addr  = $session->customer_details->address ?? null;

if (!$addr && !empty($session->collected_information?->shipping_details?->address)) {
    $addr = $session->collected_information->shipping_details->address;
    $name = $name ?: ($session->collected_information->shipping_details->name ?? null);
}

$charge = $session->payment_intent?->latest_charge ?? null;
if ((!$email || !$name || !$addr) && $charge) {
    $email = $email ?: ($charge->billing_details->email ?? null);
    $name  = $name  ?: ($charge->billing_details->name ?? null);
    if (!empty($charge->shipping?->address)) {
        $addr = $addr ?: $charge->shipping->address;
        $name = $name ?: ($charge->shipping->name ?? null);
    } else {
        $addr = $addr ?: ($charge->billing_details->address ?? null);
    }
}

$shippingText = format_address_text($addr);

// --- Resolve our order IDs: client_reference_id first, then PI metadata ---
$orderIds = [];
if (!empty($clientRef)) {
    $orderIds = array_filter(array_map('intval', explode(',', $clientRef)));
}
if (!$orderIds && !empty($session?->payment_intent?->metadata?->order_ids)) {
    $orderIds = array_filter(array_map('intval', explode(',', (string)$session->payment_intent->metadata->order_ids)));
}
if (!$orderIds) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'note' => 'no order ids']); exit;
}

// --- Upsert customer ---
$customerId = null;
if ($email) {
    $q = db()->prepare("SELECT id FROM customers WHERE email=? LIMIT 1");
    $q->execute([$email]);
    if ($row = $q->fetch()) {
        $customerId = (int)$row['id'];
        if ($name) {
            db()->prepare("UPDATE customers SET name=? WHERE id=?")->execute([$name, $customerId]);
        }
    } else {
        $ins = db()->prepare("INSERT INTO customers (name,email) VALUES (?,?)");
        $ins->execute([$name ?: 'Guest', $email]);
        $customerId = (int)db()->lastInsertId();
    }
}

// --- Update orders + decrement stock ---
$in = implode(',', array_fill(0, count($orderIds), '?'));
db()->beginTransaction();
try {
    $base = "UPDATE orders SET status='processing', stripe_payment_intent_id=?";
    $args = [$piId];

    if ($shippingText !== '') { $base .= ", shipping_address=?"; $args[] = $shippingText; }
    if ($customerId)          { $base .= ", customer_id=?";      $args[] = $customerId;   }

    $sql  = $base . " WHERE id IN ($in)";
    $args = array_merge($args, $orderIds);
    db()->prepare($sql)->execute($args);

    // decrement stock
// decrement stock atomically; fail if insufficient
    $q = db()->prepare("SELECT product_id, quantity FROM orders WHERE id IN ($in)");
    $q->execute($orderIds);
    $by = [];
    while ($r = $q->fetch()) {
        $by[(int)$r['product_id']] = ($by[(int)$r['product_id']] ?? 0) + (int)$r['quantity'];
    }

    foreach ($by as $pid => $qty) {
        $upd = db()->prepare("UPDATE products SET stock = stock - :q WHERE id = :id AND stock >= :q");
        $upd->execute([':q' => $qty, ':id' => $pid]);
        if ($upd->rowCount() !== 1) {
            throw new RuntimeException("Insufficient stock during post-payment decrement for product #$pid (qty $qty).");
        }
    }


    db()->commit();
} catch (\Throwable $e) {
    db()->rollBack();
    // Optional: log to syslog or error_log in production
    http_response_code(200);
    echo json_encode(['ok' => true, 'note' => 'db error']); exit;
}
require_once __DIR__ . '/../lib/analytics.php';
analytics_log('checkout_completed', null, $customerId ?: null, [
    'order_ids'   => $orderIds,
    'amount_total'=> $session?->amount_total,
    'currency'    => $session?->currency
]);

// --- Email receipt (simple, reliable) ---
if (!empty($email)) {
    $q = db()->prepare("SELECT o.id, o.product_id, o.quantity, o.unit_price, o.total_amount,
                           o.variant_json,
                           p.name AS product_name
                    FROM orders o LEFT JOIN products p ON p.id=o.product_id
                    WHERE o.id IN ($in)");
    $q->execute($orderIds);
    $rows = $q->fetchAll();

    $total = 0.0; $lines = '';
    foreach ($rows as $r) {
        $total += (float)$r['total_amount'];

        $name = $r['product_name'] ?: ('Product #'.$r['product_id']);

        // Decode chosen variant (e.g., {"Primary color":"Pink"})
        $vtext = '';
        if (!empty($r['variant_json'])) {
            $vj = json_decode($r['variant_json'], true);
            if (is_array($vj) && $vj) {
                $pairs = [];
                foreach ($vj as $k=>$v) {
                    if ($v === '' || $v === null) continue;
                    $pairs[] = htmlspecialchars($k).': '.htmlspecialchars($v);
                }
                if ($pairs) {
                    $vtext = '<div style="color:#6b7280;font-size:12px;margin-top:2px">'
                        . implode(', ', $pairs)
                        . '</div>';
                }
            }
        }

        $lines .= '<tr>'
            . '<td style="padding:6px 8px;border-bottom:1px solid #eee">'
            . htmlspecialchars($name) . $vtext
            . '</td>'
            . '<td align="center" style="padding:6px 8px;border-bottom:1px solid #eee">'
            . (int)$r['quantity']
            . '</td>'
            . '<td align="right" style="padding:6px 8px;border-bottom:1px solid #eee">$'
            . number_format((float)$r['unit_price'], 2)
            . '</td></tr>';
    }

    $addrHtml = $shippingText ? '<p style="margin:10px 0 0;color:#555;white-space:pre-wrap">Ship to:<br>'.nl2br(htmlspecialchars($shippingText)).'</p>' : '';
    $html = '
    <div style="font-family:Arial,Helvetica,sans-serif;color:#111">
      <h2 style="color:#7a3d91;margin:0 0 10px">Thank you '.htmlspecialchars($name ?: '').'!</h2>
      <p>Your order has been received and is now processing.</p>'
        .$addrHtml.
        '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-top:10px">
        <tr><th align="left">Item</th><th align="center">Qty</th><th align="right">Price</th></tr>'
        .$lines.
        '</table>
      <p style="text-align:right;margin-top:12px"><strong>Total: $'.number_format($total,2).'</strong></p>
      <p style="margin-top:14px;color:#555">We’ll email you when your order ships.</p>
      <p style="margin-top:14px;color:#555">— Ava D Creates</p>
    </div>';
    send_email($email, $name ?: $email, "Your order with Ava D Creates", $html);
}

http_response_code(200);
echo json_encode(['ok' => true]);
