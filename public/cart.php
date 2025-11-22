<?php require_once __DIR__.'/../lib/db.php'; require_once __DIR__.'/../lib/util.php';
$nav_active='cart'; include __DIR__.'/../partials/header.php';
$_SESSION['cart'] = $_SESSION['cart'] ?? []; // [ product_id => qty ]


// Helper to put a cart row with a composite key
function cart_key(int $pid, array $variant): string {
    ksort($variant);
    $vk = '';
    foreach ($variant as $k=>$v) { if ($v!=='') $vk .= $k.':'.$v.'|'; }
    return $pid.'|'.$vk;
}
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='add') {
    $pid = (int)($_POST['product_id'] ?? 0);
    $req = max(1, (int)($_POST['qty'] ?? 1));
    $variant = $_POST['variant'] ?? [];       // array like ['Primary color' => 'Pink']

    // check stock
    $st = db()->prepare("SELECT name, stock FROM products WHERE id=?");
    $st->execute([$pid]);
    $p = $st->fetch();

    if (!$p) {
        echo '<div class="pill error">Product not found.</div>';
    } else {
        $have = (int)$p['stock'];
        if ($have <= 0) {
            echo '<div class="pill warn">Sorry, this item is out of stock.</div>';
        } else {
            $want = min($req, $have);

            // Use composite key so different variants become separate lines
            $key = cart_key($pid, $variant);

            if (!isset($_SESSION['cart'][$key])) {
                $_SESSION['cart'][$key] = ['product_id'=>$pid, 'qty'=>$want, 'variant'=>$variant];
            } else {
                $_SESSION['cart'][$key]['qty'] = min($_SESSION['cart'][$key]['qty'] + $want, $have);
            }

            require_once __DIR__ . '/../lib/analytics.php';
            analytics_log('add_to_cart', $pid, $_SESSION['user_id'] ?? null, ['qty'=>$want, 'variant'=>$variant]);
            header('Location: /cart.php');
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='update') {
    // Expect inputs named qty[cartKey]
    foreach (($_POST['qty'] ?? []) as $key => $want) {
        $want = max(0, (int)$want);
        if (!isset($_SESSION['cart'][$key])) continue;

        $row = $_SESSION['cart'][$key];
        $pid = (int)$row['product_id'];

        // re-check stock
        $st = db()->prepare("SELECT stock FROM products WHERE id=?");
        $st->execute([$pid]);
        $have = (int)($st->fetchColumn() ?: 0);

        if ($want === 0) {
            unset($_SESSION['cart'][$key]);
        } else {
            $_SESSION['cart'][$key]['qty'] = min($want, max(0,$have));

            require_once __DIR__ . '/../lib/analytics.php';
            analytics_log('cart_update', (int)$pid, $_SESSION['user_id'] ?? null, ['qty' => (int)$_SESSION['cart'][$pid] ?? 0]);

        }
    }
}


$items = $_SESSION['cart'];
// migrate legacy numeric map (product_id=>qty) into structured rows
$legacy = false;
foreach ($items as $k=>$v) { if (is_int($v)) { $legacy = true; break; } }
if ($legacy) {
    $new = [];
    foreach ($items as $pid=>$qty) {
        $key = cart_key((int)$pid, []);
        $new[$key] = ['product_id'=>(int)$pid, 'qty'=>(int)$qty, 'variant'=>[]];
    }
    $_SESSION['cart'] = $items = $new;
}

$ids = [];
foreach ($items as $row) $ids[] = (int)$row['product_id'];
$ids = array_values(array_unique($ids));

$productsById = [];
$total = 0.0;

if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT * FROM products WHERE id IN ($in)");
    $stmt->execute($ids);
    while ($row = $stmt->fetch()) $productsById[(int)$row['id']] = $row;
}

?>
<h1>Your Cart</h1>

<?php if (!$items): ?>
    <p>Your cart is empty.</p>
<?php else: ?>
    <form method="post">
        <!-- Honeypot anti-bot field -->
        <input type="text" name="website" value="" style="display:none !important;" autocomplete="off" tabindex="-1">
        <input type="hidden" name="action" value="update">
        <table class="table">
            <tr>
                <th>Item</th>
                <th style="text-align:center">Price</th>
                <th style="text-align:center">Qty</th>
                <th style="text-align:right">Total</th>
            </tr>

            <?php foreach ($items as $key => $row):
                $p = $productsById[(int)$row['product_id']] ?? null;
                if (!$p) continue;
                $lineTotal = (float)$p['price'] * (int)$row['qty'];
                $total += $lineTotal;
                ?>
                <tr>
                    <td>
                        <div><?= h($p['name']) ?></div>
                        <?php if (!empty($row['variant'])): ?>
                            <div class="text-sm muted">
                                <?php foreach ($row['variant'] as $k=>$v): if ($v==='') continue; ?>
                                    <span><?= h($k) ?>: <?= h($v) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td align="center">$<?= money((float)$p['price']) ?></td>
                    <td align="center">
                        <input type="number" name="qty[<?= h($key) ?>]" value="<?= (int)$row['qty'] ?>" min="0" style="width:60px">
                    </td>
                    <td align="right">$<?= money($lineTotal) ?></td>
                </tr>
            <?php endforeach; ?>

            <tr>
                <td colspan="3" align="right"><strong>Grand Total</strong></td>
                <td align="right"><strong>$<?= money($total) ?></strong></td>
            </tr>
        </table>
        <p>
            <button class="btn secondary" type="submit">Update</button>
            <a class="btn" href="/checkout.php">Checkout</a>
        </p>
    </form>
<?php endif; ?>

<?php include __DIR__.'/../partials/footer.php'; ?>
