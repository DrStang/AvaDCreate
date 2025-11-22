<?php
declare(strict_types=1);

// Nightly job to mark stale pending orders as abandoned
// Run via: php cleanup_abandoned_orders.php

// Adjust these if your paths differ:
require __DIR__ . '/db.php';

if (php_sapi_name() !== 'cli') {
    // Prevent someone from hitting this via browser
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

// How many days before an order is considered stale?
$DAYS_OLD = 7;

// If you ever want to make this configurable via env:
// $DAYS_OLD = (int)($_ENV['ORDER_ABANDON_DAYS'] ?? 7);

$cutoff = (new DateTimeImmutable(sprintf('-%d days', $DAYS_OLD)))->format('Y-m-d H:i:s');

try {
    $pdo = db();

    // OPTIONAL: wrap in a transaction for safety
    $pdo->beginTransaction();

    // NOTE:
    // - Assumes `status` ENUM includes 'abandoned'
    //   If not, either:
    //   1) ALTER TABLE orders MODIFY COLUMN status ENUM('pending','paid','processing','shipped','cancelled','abandoned') NOT NULL DEFAULT 'pending';
    //   2) Or change 'abandoned' below to a valid status like 'cancelled'.
    //
    // - Assumes `stripe_payment_intent_id` exists.
    //   If your column is named differently, update the WHERE clause.

    $sql = "
        UPDATE orders
        SET status = 'abandoned'
        WHERE status = 'pending'
          AND created_at < :cutoff
          AND (stripe_payment_intent_id IS NULL OR stripe_payment_intent_id = '')
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':cutoff' => $cutoff]);
    $affected = $stmt->rowCount();

    $pdo->commit();

    $msg = sprintf(
        "[%s] cleanup_abandoned_orders: cutoff=%s, days=%d, updated_rows=%d\n",
        (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        $cutoff,
        $DAYS_OLD,
        $affected
    );

    echo $msg;
    error_log($msg); // goes to PHP error log or cron log
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $msg = sprintf(
        "[%s] cleanup_abandoned_orders ERROR: %s\n",
        (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        $e->getMessage()
    );
    echo $msg;
    error_log($msg);
    exit(1);
}
