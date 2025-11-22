<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/util.php';
require_once __DIR__ . '/../lib/analytics.php';
analytics_log('page_view', null, $_SESSION['user_id'] ?? null);

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= h(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root{
            --bg:#faf9f6;
            --text:#1f2330;
            --muted:#6b7280;
            --brand:#7a3d91;     /* purple */
            --brand-2:#9f6bd3;   /* lighter purple for gradients */
            --pill-bg:#ffffff;
            --pill-br:#8a46cc33;
            --pill-hover:#f3e9ff;
            --pill-active:#7a3d91;
            --pill-active-text:#fff;
        }
        *{box-sizing:border-box}
        body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;background:var(--bg);color:var(--text)}
        header{
            position:sticky; top:0; z-index:50; background:#fff;
            border-bottom:1px solid #eee; padding:14px 18px;
        }
        .wrap{max-width:1100px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;gap:16px}
        a{color:var(--brand);text-decoration:none}
        .brand{display:flex;align-items:center;gap:10px;font-weight:800;font-size:22px;color:var(--brand)}
        .brand small{display:block;font-weight:500;color:var(--muted);font-size:12px;margin-top:-6px}
        nav{display:flex;gap:18px}
        .pill-link{
            display:inline-flex; align-items:center; justify-content:center; gap:8px;
            padding:12px 22px; border-radius:999px;
            background:var(--pill-bg);
            border:2px solid var(--pill-br);
            color:var(--brand);
            box-shadow:0 10px 20px rgba(122,61,145,.10);
            transition:all .18s ease;
            font-weight:700;
        }
        .pill-link:hover{ background:var(--pill-hover) }
        .pill-link.active{
            color:var(--pill-active-text);
            border-color:transparent;
            background:linear-gradient(135deg,var(--brand),var(--brand-2));
            box-shadow:0 12px 24px rgba(122,61,145,.25);
        }

        .container{max-width:1100px;margin:0 auto;padding:18px}
        .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px}
        .card{border:1px solid #eee;border-radius:16px;overflow:hidden;background:#fff;box-shadow:0 12px 28px rgba(20,16,37,.06)}
        .card img{display:block;width:100%;height:220px;object-fit:cover}
        .card .p{padding:12px}
        .btn{display:inline-block;padding:10px 14px;border-radius:10px;background:var(--brand);color:#fff}
        .btn.secondary{background:#444}
        .pill{display:inline-block;background:#d8b4e2;color:#3b1c46;padding:2px 8px;border-radius:999px;font-size:12px}
        footer{border-top:1px solid #eee;padding:24px;font-size:14px;color:var(--muted)}
    </style>


</head>
<body>
<header>
    <div class="wrap">
        <a class="brand" href="/index.php">✨ Ava D Creates</a>
        <nav>
            <a class="pill-link <?= !empty($nav_active)&&$nav_active==='home'?'active':'' ?>" href="/index.php">Home</a>
            <a class="pill-link <?= !empty($nav_active)&&$nav_active==='bracelets'?'active':'' ?>" href="/category.php?c=bracelet">Bracelets</a>
            <a class="pill-link <?= !empty($nav_active)&&$nav_active==='necklaces'?'active':'' ?>" href="/category.php?c=necklace">Necklaces</a>
            <a class="pill-link <?= !empty($nav_active)&&$nav_active==='cart'?'active':'' ?>" href="/cart.php">Cart</a>
        </nav>
    </div>
</header>
<div class="container">
