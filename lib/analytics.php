<?php
// lib/analytics.php
require_once __DIR__ . '/db.php';

function analytics_log(string $eventType, ?int $productId=null, ?int $customerId=null, array $eventData=[]): void {
    try {
        // Session id (start if needed)
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
        $sid = session_id() ?: null;

        // IP (respect proxies if you’re behind one)
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? null;
        if ($ip && strpos($ip, ',') !== false) { $ip = trim(explode(',', $ip)[0]); }

        // UA / URLs
        $ua   = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $url  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ? 'https://' : 'http://')
            . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
        $ref  = $_SERVER['HTTP_REFERER'] ?? '';

        // Encode event data JSON (utf8mb4 safe)
        $json = $eventData ? json_encode($eventData, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null;

        $sql = "INSERT INTO analytics
                (event_type, product_id, customer_id, session_id, ip_address, user_agent, page_url, referrer, event_data)
                VALUES (?,?,?,?,?,?,?,?,?)";
        db()->prepare($sql)->execute([
            $eventType,
            $productId,
            $customerId,
            $sid,
            $ip,
            $ua,
            $url,
            $ref,
            $json
        ]);
    } catch (\Throwable $e) {
        // Don’t break the page if analytics fails
        error_log('[analytics_log] '.$e->getMessage());
    }
}
