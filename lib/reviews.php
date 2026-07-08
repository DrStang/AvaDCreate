<?php
// lib/reviews.php — helpers for the reviews feature
require_once __DIR__ . '/db.php';      // db() -> PDO (also pulls in config.php -> APP_URL)

/** Random, URL-safe, 64-char token. */
function review_generate_token(): string {
    return bin2hex(random_bytes(32));
}

/**
 * Return an existing invite token for an order, or create one.
 * One token per order row (which in this schema is one product line).
 */
function review_get_or_create_invite(int $orderId, ?int $productId, ?string $email, ?string $name): string {
    $pdo = db();

    $sel = $pdo->prepare("SELECT token FROM review_invites WHERE order_id=? ORDER BY id ASC LIMIT 1");
    $sel->execute([$orderId]);
    $existing = $sel->fetchColumn();
    if ($existing) return (string)$existing;

    $token = review_generate_token();
    $ins = $pdo->prepare(
        "INSERT INTO review_invites (token, order_id, product_id, email, customer_name, created_at)
         VALUES (?,?,?,?,?,NOW())"
    );
    $ins->execute([$token, $orderId, $productId, $email, $name]);
    return $token;
}

/** Full public URL for a review invite. */
function review_invite_url(string $token): string {
    return rtrim(APP_URL, '/') . '/review.php?t=' . urlencode($token);
}

/** ★★★★☆ markup for a 0..5 rating. */
function review_stars_html(int $rating): string {
    $rating = max(0, min(5, $rating));
    $full  = str_repeat('★', $rating);
    $empty = str_repeat('☆', 5 - $rating);
    return '<span class="stars" aria-label="' . $rating . ' out of 5 stars">'
         . $full . '<span class="stars-empty">' . $empty . '</span></span>';
}
