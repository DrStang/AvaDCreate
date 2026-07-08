<?php
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/util.php';
require_once __DIR__.'/../lib/reviews.php';

$token = trim($_GET['t'] ?? ($_POST['t'] ?? ''));

$invite = null;
if ($token !== '') {
    $st = db()->prepare("SELECT * FROM review_invites WHERE token=? LIMIT 1");
    $st->execute([$token]);
    $invite = $st->fetch();
}

$done  = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $invite) {
    if (!csrf_ok()) {
        $error = 'Your session expired — please try submitting again.';
    } elseif (!empty($invite['used_at'])) {
        $error = 'This review link has already been used. Thank you!';
    } else {
        $name   = trim($_POST['author_name'] ?? '');
        $rating = (int)($_POST['rating'] ?? 5);
        $title  = trim($_POST['title'] ?? '');
        $body   = trim($_POST['body'] ?? '');
        $rating = max(1, min(5, $rating));

        if ($name === '' || $body === '') {
            $error = 'Please add your name and a few words about your piece.';
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $ins = $pdo->prepare(
                    "INSERT INTO reviews
                        (product_id, order_id, author_name, rating, title, body, source, status, created_at, updated_at)
                     VALUES (?,?,?,?,?,?, 'site', 'pending', NOW(), NOW())"
                );
                $ins->execute([
                    $invite['product_id'], $invite['order_id'],
                    $name, $rating, ($title !== '' ? $title : null), $body
                ]);
                $rid = (int)$pdo->lastInsertId();
                $pdo->prepare("UPDATE review_invites SET used_at=NOW(), review_id=? WHERE id=?")
                    ->execute([$rid, $invite['id']]);
                $pdo->commit();
                $done = true;
            } catch (\Throwable $e) {
                $pdo->rollBack();
                $error = 'Something went wrong saving your review. Please try again.';
            }
        }
    }
}

$nav_active = 'reviews';
include __DIR__.'/../partials/header.php';
?>
<style>
    .rvf-card{background:#fff;border:1px solid #eee;border-radius:18px;padding:24px;
        box-shadow:0 14px 34px rgba(20,16,37,.08);max-width:640px;margin:8px auto}
    .rvf-card h1{margin:0 0 6px;color:#3b1c46;font-size:24px}
    .rvf-card p.lede{color:var(--muted);margin:0 0 18px}
    .rvf-card label{display:block;font-weight:700;margin:14px 0 6px;color:#3b1c46}
    .rvf-card input[type=text],
    .rvf-card textarea{width:100%;padding:11px 12px;border:2px solid var(--pill-br);
        border-radius:12px;font:inherit;background:#fff}
    .rvf-card textarea{min-height:120px;resize:vertical}
    .rvf-note{color:var(--muted);font-size:13px;margin-top:6px}

    /* star picker */
    .rvf-stars{display:inline-flex;flex-direction:row-reverse;gap:4px;font-size:34px;line-height:1}
    .rvf-stars input{position:absolute;opacity:0;pointer-events:none}
    .rvf-stars label{margin:0;cursor:pointer;color:#e5cbb0;transition:color .12s}
    .rvf-stars label:hover,
    .rvf-stars label:hover ~ label,
    .rvf-stars input:checked ~ label{color:#e8a52a}

    .rvf-btn{display:inline-block;margin-top:20px;padding:12px 22px;border:0;border-radius:12px;
        background:linear-gradient(135deg,var(--brand),var(--brand-2));color:#fff;font-weight:800;
        font-size:16px;cursor:pointer;box-shadow:0 12px 24px rgba(122,61,145,.25)}
    .rvf-msg{border-radius:12px;padding:12px 14px;margin-bottom:14px;font-weight:600}
    .rvf-msg.err{background:#fee2e2;color:#991b1b}
    .rvf-msg.ok{background:#e8f8ee;color:#166534}
    .rvf-done{text-align:center;padding:10px 0}
    .rvf-done .big{font-size:44px}
</style>

<div class="rvf-card">
<?php if (!$invite): ?>
    <h1>Review link not found</h1>
    <p class="lede">This link isn’t valid. Review links are personal and are sent by email after a purchase.
        If you bought something and want to leave a review, reply to your order email and Ava will help. 💜</p>
    <a class="btn" href="/reviews.php">See customer reviews</a>

<?php elseif ($done): ?>
    <div class="rvf-done">
        <div class="big">💜</div>
        <h1>Thank you so much!</h1>
        <p class="lede">Your review was submitted and will appear on the site once Ava approves it.</p>
        <a class="btn" href="/reviews.php">Read other reviews</a>
    </div>

<?php elseif (!empty($invite['used_at'])): ?>
    <h1>You’ve already reviewed this order</h1>
    <p class="lede">Thanks again for your support! Each review link can only be used once.</p>
    <a class="btn" href="/reviews.php">See customer reviews</a>

<?php else: ?>
    <h1>Leave a review</h1>
    <p class="lede">Thank you for supporting handmade! Tell others what you thought. 💜</p>

    <?php if ($error): ?><div class="rvf-msg err"><?= h($error) ?></div><?php endif; ?>

    <form method="post" action="/review.php">
        <input type="hidden" name="t" value="<?= h($token) ?>">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

        <label>Your rating</label>
        <div class="rvf-stars">
            <?php foreach ([5,4,3,2,1] as $s): ?>
                <input type="radio" id="star<?= $s ?>" name="rating" value="<?= $s ?>" <?= $s===5?'checked':'' ?>>
                <label for="star<?= $s ?>" title="<?= $s ?> star<?= $s===1?'':'s' ?>">★</label>
            <?php endforeach; ?>
        </div>

        <label for="author_name">Your name</label>
        <input type="text" id="author_name" name="author_name"
               value="<?= h($_POST['author_name'] ?? ($invite['customer_name'] ?? '')) ?>"
               maxlength="120" required>

        <label for="title">Title <span style="font-weight:500;color:var(--muted)">(optional)</span></label>
        <input type="text" id="title" name="title" value="<?= h($_POST['title'] ?? '') ?>" maxlength="160">

        <label for="body">Your review</label>
        <textarea id="body" name="body" maxlength="2000" required><?= h($_POST['body'] ?? '') ?></textarea>
        <div class="rvf-note">Reviews are checked before they go live.</div>

        <button class="rvf-btn" type="submit">Submit review</button>
    </form>
<?php endif; ?>
</div>

<?php include __DIR__.'/../partials/footer.php'; ?>
