<?php /* admin/_style.php */ ?>
<style>
    :root{
        --admin-grad-a:#6c3eb6;   /* deep purple */
        --admin-grad-b:#4f84f3;   /* blue */
        --admin-card:#ffffff;
        --admin-text:#1b2030;
        --admin-muted:#6b7280;
        --admin-ring:#8a46cc33;
    }
    body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;background:#f6f5fb;color:var(--admin-text)}
    .admin-header{position:sticky;top:0;z-index:40;background:#fff;border-bottom:1px solid #eee}
    .admin-wrap{max-width:1200px;margin:0 auto;padding:14px 18px;display:flex;align-items:center;justify-content:space-between}
    .admin-brand{font-weight:800;color:#7a3d91;font-size:22px}
    .admin-hero{
        background:linear-gradient(135deg,var(--admin-grad-a),var(--admin-grad-b));
        padding:38px 0; margin-bottom:22px;
        box-shadow:inset 0 -1px 0 rgba(255,255,255,.15);
    }
    .admin-hero-inner{max-width:1200px;margin:0 auto;padding:0 18px}
    .admin-hero-card{
        background:var(--admin-card); border-radius:20px; padding:18px 20px;
        box-shadow:0 20px 40px rgba(30,10,80,.18);
        display:flex; align-items:center; justify-content:space-between; gap:12px;
    }
    .admin-title{font-size:26px;font-weight:800;color:#6c3eb6;display:flex;gap:10px;align-items:center}
    .admin-actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:18px}
    .tab{
        display:inline-flex;align-items:center;gap:10px;padding:12px 18px;border-radius:14px;
        background:#fff;border:2px solid var(--admin-ring);color:#6c3eb6;font-weight:700;
        box-shadow:0 10px 20px rgba(50,15,120,.08); transition:all .18s;
    }
    .tab:hover{background:#f3e9ff}
    .tab.active{
        color:#fff; border-color:transparent;
        background:linear-gradient(135deg,var(--admin-grad-a),var(--admin-grad-b));
        box-shadow:0 14px 28px rgba(60,20,140,.25);
    }

    .kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin:18px auto;max-width:1200px;padding:0 18px}
    .kpi{
        background:#fff;border-radius:18px;padding:18px;
        box-shadow:0 12px 28px rgba(30,10,80,.10);
        background-image:linear-gradient(135deg,#7a3d911a,#4f84f31a);
    }
    .kpi .n{font-size:34px;font-weight:800;margin-top:8px}
    .section{max-width:1200px;margin:18px auto;padding:0 18px}
    .card{background:#fff;border-radius:18px;box-shadow:0 12px 28px rgba(20,16,37,.08);padding:18px}
    .btn-outline{
        padding:10px 14px;border-radius:12px;background:#fff;border:2px solid var(--admin-ring);color:#2b2f42;font-weight:700;
    }
    .btn-outline:hover{background:#f3e9ff}
</style>
