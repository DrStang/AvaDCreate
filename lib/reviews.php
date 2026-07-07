<?php
require_once __DIR__.'/db.php';

function reviews_ensure_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    db()->exec("CREATE TABLE IF NOT EXISTS reviews (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id INT NULL,
        customer_id INT NULL,
        reviewer_name VARCHAR(150) NOT NULL,
        rating TINYINT UNSIGNED NOT NULL DEFAULT 5,
        review_text TEXT NOT NULL,
        source VARCHAR(30) NOT NULL DEFAULT 'site',
        verified_purchase TINYINT(1) NOT NULL DEFAULT 0,
        is_published TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL,
        UNIQUE KEY uniq_review_order (order_id),
        KEY idx_reviews_published (is_published, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS review_tokens (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL DEFAULT NULL,
        UNIQUE KEY uniq_review_token_hash (token_hash),
        UNIQUE KEY uniq_review_token_order (order_id),
        KEY idx_review_token_expiry (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function review_token_for_order(int $orderId, int $daysValid = 180): string {
    reviews_ensure_schema();
    $raw = bin2hex(random_bytes(32));
    $hash = hash('sha256', $raw);
    $expires = (new DateTimeImmutable('+'.$daysValid.' days'))->format('Y-m-d H:i:s');
    $sql = "INSERT INTO review_tokens (order_id, token_hash, expires_at)
            VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE token_hash=VALUES(token_hash), expires_at=VALUES(expires_at), used_at=NULL";
    db()->prepare($sql)->execute([$orderId, $hash, $expires]);
    return $raw;
}

function review_token_lookup(string $rawToken): ?array {
    reviews_ensure_schema();
    if (!preg_match('/^[a-f0-9]{64}$/i', $rawToken)) return null;
    $q = db()->prepare("SELECT rt.*, o.customer_id, c.name AS customer_name, c.email,
                               p.name AS product_name
                        FROM review_tokens rt
                        JOIN orders o ON o.id=rt.order_id
                        LEFT JOIN customers c ON c.id=o.customer_id
                        LEFT JOIN products p ON p.id=o.product_id
                        WHERE rt.token_hash=? AND rt.used_at IS NULL AND rt.expires_at >= NOW()
                        LIMIT 1");
    $q->execute([hash('sha256', $rawToken)]);
    $row = $q->fetch();
    return $row ?: null;
}
