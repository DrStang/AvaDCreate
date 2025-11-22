<?php
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}
function csrf_ok(): bool {
    return isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf']);
}
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function money(float $d): string { return number_format($d, 2); }

function get_cart_items_from_db(): array {
    $cart = $_SESSION['cart'] ?? [];
    if (!$cart) return [];
    $ids = array_keys($cart);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT id, name, price, stock FROM products WHERE id IN ($in)");
    $stmt->execute($ids);
    $out = [];
    while ($p = $stmt->fetch()) {
        $p['qty'] = (int)$cart[$p['id']];
        $out[] = $p;
    }
    return $out;
}
