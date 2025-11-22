<?php
// admin/analytics.php
require_once __DIR__.'/../lib/auth.php'; admin_required();
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/util.php';

$nav_active = 'analytics';
include __DIR__.'/_style.php';
include __DIR__.'/../partials/header.php';
?>
<div class = "analytics-page">
    <style>
        /* === Isolate analytics page from global admin theme === */
        .analytics-page { position: relative; isolation: isolate; overflow-x: hidden; }
        /* Remove any decorative ::before/::after leaking in from global CSS */
        .analytics-page *::before,
        .analytics-page *::after {
            content: none !important;
            display: none !important;
        }

        /* KILL all decorative pseudo-elements that other admin pages add */
        .analytics-page .admin-wrap::before,
        .analytics-page .admin-wrap::after,
        .analytics-page .admin-header::before,
        .analytics-page .admin-header::after,
        .analytics-page .admin-hero::before,
        .analytics-page .admin-hero::after,
        .analytics-page .section::before,
        .analytics-page .section::after,
        .analytics-page .card::before,
        .analytics-page .card::after,
        .analytics-page .admin-title::before,
        .analytics-page .admin-title::after { content: none !important; display: none !important; }
        /* Kill global drop-cap/first-letter styles ONLY on analytics */
        .analytics-page .admin-title {
            display: block !important;
            position: static !important;
            transform: none !important;
            writing-mode: horizontal-tb !important;
            float: none !important;
            clear: both !important;
            margin: 0 0 10px !important;
            line-height: 1.25 !important;
        }

        /* This is the key fix: neutralize any drop-cap styling */
        .analytics-page .admin-title::first-letter {
            float: none !important;
            position: static !important;
            display: inline !important;
            font-size: inherit !important;
            line-height: inherit !important;
            margin: 0 !important;
            padding: 0 !important;
            background: none !important;
            color: inherit !important;
            content: unset !important;
        }

        /* Just in case the theme uses caps on cards/sections too */
        .analytics-page .card::first-letter,
        .analytics-page .section::first-letter {
            float: none !important;
            position: static !important;
            display: inline !important;
            font-size: inherit !important;
            line-height: inherit !important;
            margin: 0 !important;
            padding: 0 !important;
            background: none !important;
            color: inherit !important;
            content: unset !important;
        }

        /* Normalize header/brand */
        .analytics-page .admin-header{
            position: static !important;
            transform: none !important;
            left: auto !important; right: auto !important;
            margin: 0 0 16px !important;
            display: flex; align-items: center; justify-content: space-between;
        }
        .analytics-page .admin-brand{
            position: static !important; transform: none !important; left: auto !important; margin: 0 !important;
            font-size: 28px; line-height: 1.2;
        }
        /* Local heading style (no theme decorations) */
        .analytics-page .analytics-title{
            font-size:20px;
            font-weight:800;
            color:#6c3eb6;
            margin:0 0 10px;
            line-height:1.25;
        }

        /* Just in case any global “drop-cap/first-letter” rules exist */
        .analytics-page .analytics-title::before,
        .analytics-page .analytics-title::after,
        .analytics-page .analytics-title::first-letter{
            content:unset !important;
            float:none !important;
            position:static !important;
            display:inline !important;
            margin:0 !important;
            padding:0 !important;
            background:none !important;
            color:inherit !important;
        }

        /* Main container */
        .analytics-page .admin-wrap{ max-width:1200px; margin:0 auto; padding:24px 16px; }

        /* Hero */
        .analytics-page .admin-hero{
            position: static !important; transform:none !important; left:auto !important; margin-left:0 !important;
            background: linear-gradient(135deg,#7647ff,#a34bff);
            border-radius:24px; padding:18px; margin-bottom:22px;
            box-shadow:0 10px 30px rgba(20,16,37,.10);
            overflow:hidden;
            z-index:1;
        }
        .analytics-page * { background-image: none !important; }

        .analytics-page .admin-hero-card{
            background:#fff; border-radius:16px; padding:10px;
            display:grid; grid-template-columns:repeat(6,minmax(0,1fr)); gap:10px;
        }
        .analytics-page .admin-hero-card input,
        .analytics-page .admin-hero-card select{ width:100% }

        /* KPI cards */
        .analytics-page .admin-hero-inner{
            display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-top:14px;
        }
        .analytics-page .admin-hero-inner .admin-hero-card{
            background:#fff; border-radius:16px; padding:16px; box-shadow:0 8px 18px rgba(20,16,37,.06);
        }

        /* Sections / cards */
        .analytics-page .section{ margin-top:22px; position: relative; }
        .analytics-page .card{
            background:#fff; border-radius:18px; padding:16px;
            box-shadow:0 8px 18px rgba(20,16,37,.06);
            position: static !important; transform:none !important;
        }

        /* Table */
        .analytics-page .orders-table{ width:100%; border-collapse:collapse; table-layout:fixed }
        .analytics-page .orders-table th,.analytics-page .orders-table td{
            padding:10px 12px; border-bottom:1px solid #eee; vertical-align:top;
        }
        .analytics-page .orders-table th:nth-child(6),
        .analytics-page .orders-table th:nth-child(7),
        .analytics-page .orders-table td:nth-child(6),
        .analytics-page .orders-table td:nth-child(7){ max-width:320px; }
        .analytics-page .truncate{ white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

        /* Responsive */
        @media (max-width:1100px){
            .analytics-page .admin-hero-card{ grid-template-columns:1fr 1fr 1fr; }
        }
        @media (max-width:700px){
            .analytics-page .admin-hero-card{ grid-template-columns:1fr 1fr; }
            .analytics-page .admin-hero-inner{ grid-template-columns:1fr; }
        }
        /* ===== Analytics-local classes — zero coupling to global admin styles ===== */
        .analytics-page .ana-section { margin-top: 22px; position: relative; overflow: hidden; }
        .analytics-page .ana-grid    { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }

        /* Card */
        .analytics-page .ana-card{
            background: #fff;
            border-radius: 18px;
            padding: 16px;
            box-shadow: 0 8px 18px rgba(20,16,37,.06);
            position: relative;
            overflow: hidden;
        }
        /* Container for page content (not the sticky header) */
        .analytics-page .ana-container{ max-width:1200px; margin:0 auto; padding:24px 16px; }
        /* Guard rails: clip any leftover pseudo decorations */
        .analytics-page .ana-container, .analytics-page .ana-container * { background-image:none !important; }
        .analytics-page .ana-container *::before, .analytics-page .ana-container *::after { content:none !important; display:none !important; }



        /* Headings */
        .analytics-page .analytics-title{
            font-size: 20px; font-weight: 800; color: #6c3eb6;
            margin: 0 0 10px; line-height: 1.25;
        }
        /* List */
        .analytics-page .ana-list{ list-style:none; margin:0; padding:0; }
        .analytics-page .ana-list li{
            display:flex; align-items:center; justify-content:space-between; gap:10px;
            padding:8px 0; border-bottom:1px dashed #eee;
        }
        .analytics-page .ana-list li:last-child{ border-bottom:0; }
        .analytics-page .ana-list .grow{ flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .analytics-page .ana-list .count{ font-weight:700; color:#374151; }
        /* Table */
        .analytics-page .ana-table{
            width: 100%; border-collapse: collapse; table-layout: fixed;
        }
        .analytics-page .ana-table th,
        .analytics-page .ana-table td{
            padding: 10px 12px; border-bottom: 1px solid #eee; vertical-align: top;
        }
        .analytics-page .ana-table th:nth-child(6),
        .analytics-page .ana-table th:nth-child(7),
        .analytics-page .ana-table td:nth-child(6),
        .analytics-page .ana-table td:nth-child(7){ max-width: 320px; }
        .analytics-page .truncate{ white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        /* Nuclear guards — if anything still leaks in, it gets clipped here */
        .analytics-page .ana-card *::before,
        .analytics-page .ana-card *::after,
        .analytics-page .ana-section *::before,
        .analytics-page .ana-section *::after{ content: none !important; display: none !important; }
        .analytics-page .ana-card, .analytics-page .ana-section { background-image: none !important; }

        /* KPI layout override just for analytics */
        .analytics-page .kpis-analytics{
            display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin:18px 0;
        }
        .analytics-page .kpis-analytics .kpi{
            background:#fff; border-radius:18px; padding:18px;
            box-shadow:0 12px 28px rgba(20,16,37,.10);
        }
        .analytics-page .kpi-label{ font-size:18px; font-weight:800; color:#6c3eb6; }
        .analytics-page .kpi-n{ font-size:34px; font-weight:800; margin-top:8px; line-height:1; }

        /* ensure numbers can’t be collapsed/ellipsized by any upstream rule */
        .analytics-page .kpi-n, .analytics-page .kpi-label{
            white-space:normal !important; overflow:visible !important; text-overflow:clip !important;
        }
        /* Horizontal scroll for long URLs */
        .analytics-page .ana-table-wrap{
            overflow-x:auto; -webkit-overflow-scrolling:touch;
            border-radius:12px;
        }
        .analytics-page .ana-table{ min-width: 1100px; } /* force scrollbar on small viewports */

        /* Let URL/referrer show more content but still trim if too long */
        .analytics-page .ana-table td.url{
            white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
            max-width: 520px; /* adjust if you want even wider */
        }

        /* Optional: fixed widths for the narrow columns so URLs get the space */
        .analytics-page .ana-table th:nth-child(1), .analytics-page .ana-table td:nth-child(1){ width:140px; }
        .analytics-page .ana-table th:nth-child(2), .analytics-page .ana-table td:nth-child(2){ width:110px; }
        .analytics-page .ana-table th:nth-child(3), .analytics-page .ana-table td:nth-child(3){ width:90px; }
        .analytics-page .ana-table th:nth-child(4), .analytics-page .ana-table td:nth-child(4){ width:140px; }
        .analytics-page .ana-table th:nth-child(5), .analytics-page .ana-table td:nth-child(5){ width:140px; }

        /* Responsive */
        @media (max-width:1100px){ .analytics-page .ana-grid{ grid-template-columns: 1fr 1fr; } }
        @media (max-width:700px) { .analytics-page .ana-grid{ grid-template-columns: 1fr; } }

    </style>

    <?php

    // Filters
$event   = trim($_GET['event'] ?? '');
$pid     = (int)($_GET['product_id'] ?? 0);
$q       = trim($_GET['q'] ?? '');
$from    = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$to      = $_GET['to']   ?? date('Y-m-d');

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$off     = ($page-1)*$perPage;

// Build WHERE
$where = ["created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)"];
$args  = [$from, $to];

if ($event !== '') { $where[] = "event_type = ?"; $args[] = $event; }
if ($pid > 0)      { $where[] = "product_id = ?";  $args[] = $pid;   }
if ($q !== '')     { $where[] = "(page_url LIKE ? OR referrer LIKE ? OR ip_address LIKE ? OR user_agent LIKE ?)"; $args[]="%$q%"; $args[]="%$q%"; $args[]="%$q%"; $args[]="%$q%"; }

$sqlWhere = 'WHERE '.implode(' AND ', $where);

// --- Summaries (use a stmt, because execute() returns bool) ---
$stmt = db()->prepare("SELECT COUNT(*) FROM analytics $sqlWhere");
$stmt->execute($args);
$events = (int)$stmt->fetchColumn();

$stmt = db()->prepare("SELECT COUNT(DISTINCT session_id) FROM analytics $sqlWhere");
$stmt->execute($args);
$sessions = (int)$stmt->fetchColumn();

$stmt = db()->prepare("SELECT COUNT(DISTINCT ip_address) FROM analytics $sqlWhere");
$stmt->execute($args);
$visitors = (int)$stmt->fetchColumn();

$summary = [
    'events'   => $events,
    'sessions' => $sessions,
    'visitors' => $visitors,
];

// --- “Top” lists ---
// When no filters, $sqlWhere is empty; safely add extra conditions
$cond = $where ? ($sqlWhere.' AND ') : 'WHERE ';

// Top pages
$topPagesStmt = db()->prepare(
    "SELECT page_url, COUNT(*) c
     FROM analytics $sqlWhere
 GROUP BY page_url
 ORDER BY c DESC
    LIMIT 10"
);
$topPagesStmt->execute($args);
$topPages = $topPagesStmt->fetchAll();

// Top referrers (non-empty)
$topRefStmt = db()->prepare(
    "SELECT referrer, COUNT(*) c
     FROM analytics {$cond} referrer <> ''
 GROUP BY referrer
 ORDER BY c DESC
    LIMIT 10"
);
$topRefStmt->execute($args);
$topRef = $topRefStmt->fetchAll();

// Top products (non-null)
$topProdStmt = db()->prepare(
    "SELECT product_id, COUNT(*) c
     FROM analytics {$cond} product_id IS NOT NULL
 GROUP BY product_id
 ORDER BY c DESC
    LIMIT 10"
);
$topProdStmt->execute($args);
$topProd = $topProdStmt->fetchAll();

// Paged events
$count = $summary['events'];

$listStmt = db()->prepare(
    "SELECT *
     FROM analytics $sqlWhere
 ORDER BY created_at DESC
    LIMIT $perPage OFFSET $off"
);
$listStmt->execute($args);
$rows = $listStmt->fetchAll();


function hjson($s) {
    if (!$s) return '';
    $arr = json_decode($s, true);
    if (!is_array($arr)) return htmlspecialchars($s);
    return '<pre style="white-space:pre-wrap;margin:0">'.htmlspecialchars(json_encode($arr, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)).'</pre>';
}
?>
<div class="ana-container">
        <h1 class="analytics-title">Analytics</h1>

    <div class="admin-hero">
        <div class="admin-hero-inner">
        <form class="admin-hero-card" method="get" style="display:grid;grid-template-columns:repeat(6,1fr);gap:10px">
            <input type="date" name="from" value="<?= htmlspecialchars($from) ?>">
            <input type="date" name="to"   value="<?= htmlspecialchars($to) ?>">
            <input type="text" name="event" placeholder="event_type" value="<?= htmlspecialchars($event) ?>">
            <input type="number" name="product_id" placeholder="product_id" value="<?= $pid ?: '' ?>">
            <input type="text" name="q" placeholder="ip / agent / url / referrer" value="<?= htmlspecialchars($q) ?>">
            <button class="btn">Filter</button>
        </form>

        <div class="kpis kpis-analytics">
            <div class="kpi"><div class="kpi-label">Events</div><div class="kpi-n"><?= (int)($summary['events'] ?? 0) ?></div></div>
            <div class="kpi"><div class="kpi-label">Sessions</div><div class="kpi-n"><?= (int)($summary['sessions'] ?? 0) ?></div></div>
            <div class="kpi"><div class="kpi-label">Unique IPs</div><div class="kpi-n""><?= (int)($summary['visitors'] ?? 0) ?></div></div>
        </div>
        </div>
    </div>

    <!-- Top lists -->
    <div class="ana-section ana-grid">
        <div class="ana-card">
            <div class="analytics-title">Top Pages</div>
            <?php if ($topPages): ?>
                <ul class="ana-list">
                    <?php foreach ($topPages as $t): ?>
                        <li>
                            <span class="grow" title="<?= htmlspecialchars($t['page_url']) ?>"><?= htmlspecialchars($t['page_url']) ?></span>
                            <span class="count"><?= (int)$t['c'] ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div class="ana-empty">No page views in this range.</div>
            <?php endif; ?>
        </div>

        <div class="ana-card">
            <div class="analytics-title">Top Referrers</div>
            <?php if ($topRef): ?>
                <ul class="ana-list">
                    <?php foreach ($topRef as $t): ?>
                        <li>
                            <span class="grow" title="<?= htmlspecialchars($t['referrer']) ?>"><?= htmlspecialchars($t['referrer']) ?></span>
                            <span class="count"><?= (int)$t['c'] ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div class="ana-empty">No referrers in this range.</div>
            <?php endif; ?>
        </div>

        <div class="ana-card">
            <div class="analytics-title">Top Products</div>
            <?php if ($topProd): ?>
                <ul class="ana-list">
                    <?php foreach ($topProd as $t): ?>
                        <li>
                            <span class="grow">#<?= (int)$t['product_id'] ?></span>
                            <span class="count"><?= (int)$t['c'] ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div class="ana-empty">No product events in this range.</div>
            <?php endif; ?>
        </div>
    </div>


    <div class="ana-section ana-card">
        <div class="analytics-title">Recent Events</div>\
        <div class="ana-table-wrap">
        <table class="ana-table" style="width:100%;border-collapse:collapse">
            <thead><tr><th>Time</th><th>Event</th><th>Product</th><th>Session</th><th>IP</th><th>Page</th><th>Referrer</th><th>Data</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['created_at']) ?></td>
                    <td><?= htmlspecialchars($r['event_type']) ?></td>
                    <td><?= $r['product_id'] ? (int)$r['product_id'] : '—' ?></td>
                    <td class="truncate" title="<?= htmlspecialchars($r['session_id']) ?>"><?= htmlspecialchars($r['session_id']) ?></td>
                    <td><?= htmlspecialchars($r['ip_address']) ?></td>
                    <td class="truncate" title="<?= htmlspecialchars($r['page_url']) ?>"><?= htmlspecialchars($r['page_url']) ?></td>
                    <td class="truncate" title="<?= htmlspecialchars($r['referrer']) ?>"><?= htmlspecialchars($r['referrer']) ?></td>
                    <td><?= hjson($r['event_data']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <?php
        $pages = max(1, (int)ceil($count / $perPage));
        if ($pages > 1):
            echo '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">';
            for ($i=1;$i<=$pages;$i++){
                $q = $_GET; $q['page']=$i;
                $is = $i===$page ? 'active' : '';
                echo '<a class="tab '.$is.'" href="?'.htmlspecialchars(http_build_query($q)).'">'.$i.'</a>';
            }
            echo '</div>';
        endif;
        ?>
    </div>
</div>

<?php include __DIR__.'/../partials/footer.php'; ?>
