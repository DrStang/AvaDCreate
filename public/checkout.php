<?php
// public/checkout.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';   // defines STRIPE_SECRET_KEY, APP_URL
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/util.php';

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

// --- Honeypot bot check ---
if (!empty($_POST['website'] ?? '')) {
    // Very likely a bot.
    error_log("[BOT DETECTED] Honeypot triggered on checkout from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    http_response_code(200);
    exit; // Do NOT create an order.
}


// 1) Read cart from session
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$cart = $_SESSION['cart'] ?? [];
if (!$cart) { header('Location: /cart.php'); exit; }

// Normalize legacy cart (numeric map -> structured rows)
$hasLegacy = false;
foreach ($cart as $k=>$v) { if (is_int($v)) { $hasLegacy = true; break; } }
if ($hasLegacy) {
    $new = [];
    foreach ($cart as $pid=>$qty) {
        $new[$pid.'|'] = ['product_id'=>(int)$pid, 'qty'=>(int)$qty, 'variant'=>[]];
    }
    $_SESSION['cart'] = $cart = $new;
}

// Build unique product id list
$ids = [];
foreach ($cart as $row) {
    if (!empty($row['product_id'])) $ids[] = (int)$row['product_id'];
}
$ids = array_values(array_unique($ids));

// If nothing left, bounce back to cart
if (!$ids) { header('Location: /cart.php'); exit; }

// Fetch product rows
$productsById = [];
$in = implode(',', array_fill(0, count($ids), '?'));
$stmt = db()->prepare("SELECT * FROM products WHERE id IN ($in)");
$stmt->execute($ids);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $productsById[(int)$row['id']] = $row;
}

$in  = implode(',', array_fill(0, count($ids), '?'));

// 2) Load product rows
$stmt = db()->prepare("SELECT id, name, price, stock, image_url FROM products WHERE id IN ($in)");
$stmt->execute($ids);
$products = $stmt->fetchAll();
if (!$products) { header('Location: /cart.php'); exit; }

// 3) Create pending orders + build Stripe line items
$orderIds  = [];
$lineItems = [];
$total     = 0.0;
$customerId = null; // guest; webhook will upsert customer and backfill

db()->beginTransaction();
try {
    foreach ($cart as $key => $item) {
        $pid = (int)$item['product_id'];
        $qty = (int)$item['qty'];
        $variant = $item['variant'] ?? [];

        $p = $productsById[$pid] ?? null;
        if (!$p) continue;

        // Stock check (same as your current)
        if ((int)$p['stock'] < $qty) {
            throw new Exception("Insufficient stock for ".$p['name']);
        }

        $unit = (float)$p['price'];
        $total += $unit * $qty;

        // Build a human label with variant (e.g., "Bracelet — Primary color: Pink")
        $name = $p['name'];
        if (!empty($variant)) {
            $pairs = [];
            foreach ($variant as $k=>$v) { if ($v!=='') $pairs[] = "$k: $v"; }
            if ($pairs) $name .= ' — ' . implode(', ', $pairs);
        }

        // Insert local order row (adds variant_json if you created that column)
        $created_ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ins = db()->prepare("INSERT INTO orders
        (customer_id, product_id, quantity, unit_price, total_amount, status, variant_json, created_ip, user_agent)
        VALUES (?,?,?,?,?,'pending',?,?,?)");
        $ins->execute([$customerId, $pid, $qty, $unit, $unit*$qty, json_encode($variant, JSON_UNESCAPED_UNICODE), $created_ip, $user_agent]);
        $oid = (int)db()->lastInsertId();
        $orderIds[] = $oid;

        // Image URL logic (keep yours; only change product name)
        $imgAbs = null;
        $imgRel = $p['image_url'] ?? null;
        if ($imgRel) {
            $abs = (stripos($imgRel, 'http') === 0) ? $imgRel : APP_URL . $imgRel;
            $imgAbs = $abs;
        }

        $lineItems[] = [
            'price_data' => [
                'currency' => 'usd',
                'product_data' => array_filter([
                    'name'   => $name,               // 👈 include variant text
                    'images' => $imgAbs ? [$imgAbs] : null,
                ]),
                'unit_amount'  => (int)round($unit * 100),
            ],
            'quantity' => $qty,
        ];
    }
    db()->commit();
} catch (Throwable $e) {
    db()->rollBack();
    die('Checkout error: ' . h($e->getMessage()));
}
require_once __DIR__ . '/../lib/analytics.php';
analytics_log('checkout_start', null, $_SESSION['user_id'] ?? null, ['cart' => $cart]);

// 4) Create Checkout Session
$session = \Stripe\Checkout\Session::create([
    'mode'       => 'payment',
    'line_items' => $lineItems,
    'success_url'=> APP_URL . '/success.php?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url' => APP_URL . '/cancel.php',

    // collect addresses
    'billing_address_collection'  => 'auto',
    'shipping_address_collection' => ['allowed_countries' => ['US']],
    'shipping_options' => [
        [
            'shipping_rate_data' => [
                'type' => 'fixed_amount',
                'fixed_amount' => ['amount' => 100, 'currency' => 'usd'],
                'display_name' => 'Standard shipping',

            ],
        ],
    ],

    // create a Stripe Customer to associate future orders
    'customer_creation' => 'always',

    // copy our order ids into PI metadata for fallback correlation
    'payment_intent_data' => [
        'metadata' => ['order_ids' => implode(',', $orderIds)],
    //    'last_variant' => json_encode(end($cart)['variant'] ?? [], JSON_UNESCAPED_UNICODE),
    ],

    // pointer back to our order rows
    'client_reference_id' => implode(',', $orderIds),
]);

// Clear cart (we’ll rely on webhook for finalization)
unset($_SESSION['cart']);

header('Location: ' . $session->url);
exit;
