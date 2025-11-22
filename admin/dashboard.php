<?php
require_once __DIR__.'/../lib/auth.php'; admin_required();
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/util.php';

$pdo = db(); // unify on db() -> PDO

// Counts
$cntP   = (int)($pdo->query("SELECT COUNT(*) FROM products")->fetchColumn() ?: 0);
$cntO   = (int)($pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn() ?: 0);
$cntLow = (int)($pdo->query("SELECT COUNT(*) FROM products WHERE stock <= 2")->fetchColumn() ?: 0);

include __DIR__.'/_style.php';

// ------ Revenue (paid/fulfilled) ------
$PAID_STATUSES = ['shipped', 'delivered'];
$ph = implode(',', array_fill(0, count($PAID_STATUSES), '?'));

// try total_amount first, fallback to total if needed
$revenueCol = 'total_amount';
$revenue = 0.0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM($revenueCol),0) FROM orders WHERE status IN ($ph)");
    $stmt->execute($PAID_STATUSES);
    $revenue = (float)$stmt->fetchColumn();
} catch (Throwable $e) {
    $revenueCol = 'total';
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM($revenueCol),0) FROM orders WHERE status IN ($ph)");
        $stmt->execute($PAID_STATUSES);
        $revenue = (float)$stmt->fetchColumn();
    } catch (Throwable $e2) {
        $revenue = 0.0;
    }
}
$revenueFormatted = number_format($revenue, 2);

// Pending orders (simple badge)
$cntPending = (int)($pdo->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn() ?: 0);

// ------ Revenue By Month (last 12 months) ------
$labels = [];
$values = [];
try {
    // first day of current month, step back 11 months
    $startMonth = (new DateTime('first day of this month'))->modify('-11 months');
    $startStr = $startMonth->format('Y-m-01');

    // group by month using created_at (adjust to your column name if different)
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym,
               COALESCE(SUM($revenueCol),0) AS rev
        FROM orders
        WHERE status IN ($ph)
          AND created_at >= ?
        GROUP BY ym
        ORDER BY ym ASC
    ");
    $stmt->execute([...$PAID_STATUSES, $startStr]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $map = [];
    foreach ($rows as $r) {
        $map[$r['ym']] = (float)$r['rev'];
    }

    // build 12 buckets, format labels like "Sep 2025"
    $cursor = clone $startMonth;
    for ($i=0; $i<12; $i++) {
        $key = $cursor->format('Y-m');      // lookup key
        $labels[] = $cursor->format('M Y'); // pretty label
        $values[] = $map[$key] ?? 0.0;
        $cursor->modify('+1 month');
    }
} catch (Throwable $e) {
    // leave arrays empty if something goes wrong
}
?>
<div class="admin-header">
    <div class="admin-wrap">
        <div class="admin-brand">✨ Ava D Creates · Admin</div>
        <a class="btn-outline" href="/admin/logout.php">Logout</a>
    </div>
</div>

<div class="admin-hero">
    <div class="admin-hero-inner">
        <div class="admin-hero-card">
            <div class="admin-title">🧑‍💼 Admin Dashboard</div>
            <div class="admin-actions">
                <a class="tab active" href="/admin/dashboard.php">📊 Stats</a>
                <a class="tab" href="/admin/products.php">🧾 Manage Products</a>
                <a class="tab" href="/admin/analytics.php">Analytics</a>
                <a class="tab" href="/admin/orders.php">📃 Orders</a>
                <a class="tab" href="/admin/product_edit.php">➕ Add Product</a>
            </div>
        </div>
    </div>
</div>

<div class="kpis">
    <div class="kpi"><div>Total Products</div><div class="n"><?= $cntP ?></div></div>
    <div class="kpi"><div>Total Orders</div><div class="n"><?= $cntO ?></div></div>
    <div class="kpi"><div>Total Revenue</div><div class="n">$<?= $revenueFormatted ?></div></div>
    <div class="kpi"><div>Pending Orders</div><div class="n"><?= $cntPending ?></div></div>
</div>

<div class="section">
    <?php if ($cntLow > 0): ?>
        <div class="card" style="background:#fff7e6;border:2px solid #ffd699">
            <strong>⚠ Low Stock Alert</strong><br>
            <small>Some items have 2 or fewer left.</small>
        </div>
    <?php endif; ?>
</div>

<!-- Revenue by Month mini chart -->
<div class="section">
    <div class="card">
        <div class="admin-title" style="margin-bottom:8px;">Revenue by Month</div>
        <canvas id="revChart" style="width:100%;height:320px;"></canvas>
    </div>
</div>

<!-- Keep your other sections below -->

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    (() => {
        const labels = <?= json_encode($labels) ?>;
        const dataVals = <?= json_encode(array_map(fn($v)=>round((float)$v,2), $values)) ?>;

        const ctx = document.getElementById('revChart');
        if (!ctx) return;

        new Chart(ctx.getContext('2d'), {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    label: 'Revenue',
                    data: dataVals,
                    tension: 0.35,
                    fill: true
                }]
            },
            options: {
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: { label: (c) => '$' + Number(c.parsed.y).toFixed(2) }
                    }
                },
                scales: {
                    y: {
                        ticks: { callback: (v) => '$' + Number(v).toFixed(0) }
                    }
                }
            }
        });
    })();
</script>
