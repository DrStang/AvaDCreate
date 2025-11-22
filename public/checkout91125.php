<?php
// public/checkout.php
session_start();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';   // STRIPE_* + APP_URL
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/util.php';

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

// 1) Read cart from session: [ product_id => qty ]
$cart = $_SESSION['cart'] ?? [];
if (!$cart) { header('Location: /cart.php'); exit; }

$ids = array_keys($cart);
$in  = implode(',', array_fill(0, count($ids), '?'));

// 2) Load product rows
$stmt = db()->prepare("SELECT id, name, price, stock FROM products WHERE id IN ($in)");
$stmt->execute($ids);
$products = $stmt->fetchAll();
if (!$products) { header('Location: /cart.php'); exit; }

// 3) Validate and create orders as 'pending'
$orderIds  = [];
$lineItems = [];
$total     = 0.0;
$customerId = null;   // guest checkout; webhook will backfill

db()->beginTransaction();
try {
    foreach ($products as $p) {
        $pid = (int)$p['id'];
        $qty = max(1, (int)$cart[$pid]);

        if ($p['stock'] !== null && $p['stock'] < $qty) {
            throw new Exception("Insufficient stock for ".$p['name']);
        }

        $unit  = (float)$p['price'];
        $total += $unit * $qty;

        // Insert one row per product line (matches your schema)
        $ins = db()->prepare("INSERT INTO orders
            (customer_id, product_id, quantity, unit_price, total_amount, status)
            VALUES (?,?,?,?,?,'pending')");
        $ins->execute([$customerId, $pid, $qty, $unit, $unit * $qty]);
        $oid = (int)db()->lastInsertId();
        $orderIds[] = $oid;

        // Build Stripe line item
        // Use your main image URL if available
        $imgRel = $p['image_url'] ?? null; // e.g. "/uploads/abc.webp"
        $imgAbs = null;
        if ($imgRel) {
            $abs = (stripos($imgRel, 'http') === 0) ? $imgRel : rtrim(APP_URL, '/') . $imgRel;
            if (preg_match('/\.webp($|\?)/i', $abs)) {
                // Try jpg fallback at same path (upload a .jpg copy once)
                $absJpg = preg_replace('/\.webp($|\?)/i', '.jpg$1', $abs);
                $imgAbs = $absJpg;
            } else {
                $imgAbs = $abs;
            }
            @file_put_contents('/tmp/stripe_checkout_images.log', date('c')." IMG ".$p['name']." -> ".$imgAbs."\n", FILE_APPEND);

        }
        $lineItems[] = [
            'price_data' => [
                'currency' => 'usd',
                'product_data' => array_filter([
                    'name'   => $p['name'],
                    'images' => $imgAbs ? [$imgAbs] : null, // Stripe will show this image on Checkout
                ]),
                'unit_amount'  => (int)round(((float)$p['price']) * 100),
            ],
            'quantity' => $qty,
        ];
    }
    db()->commit();
} catch (Throwable $e) {
    db()->rollBack();
    die('Checkout error: ' . h($e->getMessage()));
}

// 4) Create Stripe Checkout Session
$session = \Stripe\Checkout\Session::create([
    'mode' => 'payment',
    'line_items' => $lineItems,
    'success_url' => APP_URL . '/success.php?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url'  => APP_URL . '/cancel.php',
    'billing_address_collection' => 'auto',
    'shipping_address_collection' => ['allowed_countries' => ['US']],
    'automatic_tax' => ['enabled' => false], // flip to true if using Stripe Tax
    // Keep a pointer back to our newly created order rows
    'client_reference_id' => implode(',', $orderIds),
    'customer_creation' => 'always',
    'payment_intent_data' => [
        'metadata' => [
            'order_ids' => implode(',', $orderIds),
        ],
    ],
]);

// Optional: clear the session cart now or in success.php
unset($_SESSION['cart']);

header('Location: ' . $session->url);
exit;
