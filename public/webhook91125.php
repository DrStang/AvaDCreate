<?php
@file_put_contents('/tmp/stripe_webhook_trace.log',
    date('c')." HIT from ".$_SERVER['REMOTE_ADDR']."\n", FILE_APPEND);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/mail.php';  // <= our email helper

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

$endpoint_secret = STRIPE_WEBHOOK_SECRET;

$payload    = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

try {
    $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
    // log event type so we know we passed signature check
    @file_put_contents('/tmp/stripe_webhook_trace.log',
        date('c')." OK ".$event->type."\n", FILE_APPEND);
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    @file_put_contents('/tmp/stripe_webhook_trace.log',
        date('c')." BAD_SIG: ".$e->getMessage()."\n", FILE_APPEND);
    http_response_code(400); echo 'Bad signature'; exit;
} catch (\UnexpectedValueException $e) {
    @file_put_contents('/tmp/stripe_webhook_trace.log',
        date('c')." BAD_PAYLOAD: ".$e->getMessage()."\n", FILE_APPEND);
    http_response_code(400); echo 'Invalid payload'; exit;
}
file_put_contents('/var/www/avadcreates/webhook.log',
    date('c').' '.$event->type."\n", FILE_APPEND);

// … after $event is verified …

// After $event is constructed and verified:

// AFTER signature verification:
$type = $event->type;
$data = $event->data->object;

$session = null;
$piId    = null;
$charge  = null;

// Normalize & re-fetch an expanded Session
if ($type === 'checkout.session.completed') {
    $sessionId = $data->id;
    try {
        $session = \Stripe\Checkout\Session::retrieve($sessionId, [
            'expand' => [
                'customer_details',
                'collected_information',           // <-- include this
                'payment_intent',
                'payment_intent.latest_charge',
                'line_items',
            ],
        ]);
        $piId = $session->payment_intent?->id ?? $session->payment_intent ?? null;
    } catch (\Throwable $e) {
        @file_put_contents('/tmp/stripe_webhook_trace.log', date('c')." RETRIEVE_SESSION_FAIL: ".$e->getMessage()."\n", FILE_APPEND);
    }
} else {
    if (str_starts_with($type, 'payment_intent.')) {
        $piId = $data->id ?? null;
    } elseif (str_starts_with($type, 'charge.')) {
        $charge = $data;
        $piId   = $charge->payment_intent ?? null;
    }
    if ($piId && !$session) {
        try {
            $list = \Stripe\Checkout\Session::all(['payment_intent' => $piId, 'limit' => 1]);
            if (!empty($list->data[0])) {
                $session = \Stripe\Checkout\Session::retrieve($list->data[0]->id, [
                    'expand' => [
                        'customer_details',
                        'collected_information',
                        'payment_intent',
                        'payment_intent.latest_charge',
                        'line_items',
                    ],
                ]);
            }
        } catch (\Throwable $e) {
            @file_put_contents('/tmp/stripe_webhook_trace.log', date('c')." LOOKUP_SESSION_FAIL: ".$e->getMessage()."\n", FILE_APPEND);
        }
    }
}

// Extract identity/address
$email = null; $name = null; $addr = null; $clientRef = null;
if ($session) {
    $clientRef = $session->client_reference_id ?? null;

    // A) customer_details
    $email = $session->customer_details->email ?? null;
    $name  = $session->customer_details->name  ?? null;
    $addr  = $session->customer_details->address ?? null;

    // B) collected_information.shipping_details
    if (!$addr && !empty($session->collected_information?->shipping_details?->address)) {
        $name = $name ?: ($session->collected_information->shipping_details->name ?? null);
        $addr = $session->collected_information->shipping_details->address ?? null;
    }

    // C) PI latest charge
    $charge = $session->payment_intent?->latest_charge ?? $charge;
}
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

// Format multi-line address
$shippingText = (function($addr) {
    if (!$addr) return '';
    $a = is_object($addr) ? (array)$addr : (array)$addr;
    $lineA = implode(', ', array_filter([$a['line1'] ?? '', $a['line2'] ?? '']));
    $lineB = trim(implode(', ', array_filter([($a['city'] ?? ''), ($a['state'] ?? '')])));
    $lineC = trim(implode(' ',  array_filter([($a['postal_code'] ?? ''), ($a['country'] ?? '')])));
    return trim(implode("\n", array_filter([$lineA, $lineB, $lineC])));
})($addr);

// Log what we captured (keep while debugging)
@file_put_contents('/tmp/stripe_webhook_trace.log',
    date('c')." CAPTURE email=".($email?:'-')." name=".($name?:'-').
    " addr=".($shippingText?str_replace("\n",' | ',$shippingText):'-').
    " clientRef=".(($session?->client_reference_id) ?: '-')."\n",
    FILE_APPEND
);

// Order IDs from Session or PI metadata
$orderIds = [];
if (!empty($session?->client_reference_id)) {
    $orderIds = array_filter(array_map('intval', explode(',', $session->client_reference_id)));
}
if (!$orderIds && !empty($session?->payment_intent?->metadata?->order_ids)) {
    $orderIds = array_filter(array_map('intval', explode(',', (string)$session->payment_intent->metadata->order_ids)));
}
if (!$orderIds && $piId) {
    try {
        $pi = \Stripe\PaymentIntent::retrieve($piId);
        if (!empty($pi->metadata->order_ids)) {
            $orderIds = array_filter(array_map('intval', explode(',', (string)$pi->metadata->order_ids)));
        }
    } catch (\Throwable $e) {
        @file_put_contents('/tmp/stripe_webhook_trace.log', date('c')." PI_METADATA_FAIL: ".$e->getMessage()."\n", FILE_APPEND);
    }
}
if (!$orderIds) { http_response_code(200); echo json_encode(['ok'=>true,'note'=>'no order ids']); exit; }

// Upsert customer
$customerId = null;
if ($email) {
    $q = db()->prepare("SELECT id FROM customers WHERE email=? LIMIT 1");
    $q->execute([$email]);
    if ($row = $q->fetch()) {
        $customerId = (int)$row['id'];
        if ($name) db()->prepare("UPDATE customers SET name=? WHERE id=?")->execute([$name, $customerId]);
    } else {
        $ins = db()->prepare("INSERT INTO customers (name,email) VALUES (?,?)");
        $ins->execute([$name ?: 'Guest', $email]);
        $customerId = (int)db()->lastInsertId();
    }
}

// Update orders + decrement stock
$in = implode(',', array_fill(0, count($orderIds), '?'));
db()->beginTransaction();
try {
    $base = "UPDATE orders SET status='processing', stripe_payment_intent_id=?";
    $args = [$piId];
    if ($shippingText !== '') { $base .= ", shipping_address=?"; $args[] = $shippingText; }
    if ($customerId)          { $base .= ", customer_id=?";      $args[] = $customerId;   }
    $sql = $base . " WHERE id IN ($in)";
    $args = array_merge($args, $orderIds);
    db()->prepare($sql)->execute($args);

    $q = db()->prepare("SELECT product_id, quantity FROM orders WHERE id IN ($in)");
    $q->execute($orderIds);
    $by = [];
    while ($r = $q->fetch()) { $by[$r['product_id']] = ($by[$r['product_id']] ?? 0) + (int)$r['quantity']; }
    foreach ($by as $pid=>$qty) {
        db()->prepare("UPDATE products SET stock=GREATEST(stock-?,0) WHERE id=?")->execute([$qty, $pid]);
    }
    db()->commit();
} catch (\Throwable $e) {
    db()->rollBack();
    @file_put_contents('/tmp/stripe_webhook_trace.log', date('c')." DB_FAIL: ".$e->getMessage()."\n", FILE_APPEND);
}

// ===== Email receipt (re-enable) =====
if (!empty($email)) {
    // For the email body, we can fetch rows to show items/total
    $q = db()->prepare("SELECT o.id, o.product_id, o.quantity, o.unit_price, o.total_amount, p.name AS product_name
                        FROM orders o LEFT JOIN products p ON p.id=o.product_id
                        WHERE o.id IN ($in)");
    $q->execute($orderIds);
    $rows = $q->fetchAll();

    $total = 0.0; $lines = '';
    foreach ($rows as $r) {
        $total += (float)$r['total_amount'];
        $lines .= '<tr><td style="padding:6px 8px;border-bottom:1px solid #eee">'
            . htmlspecialchars($r['product_name'] ?: ('Product #'.$r['product_id']))
            . '</td><td align="center" style="padding:6px 8px;border-bottom:1px solid #eee">'
            . (int)$r['quantity'].'</td><td align="right" style="padding:6px 8px;border-bottom:1px solid #eee">$'
            . number_format((float)$r['unit_price'], 2).'</td></tr>';
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
echo json_encode(['ok'=>true]);
