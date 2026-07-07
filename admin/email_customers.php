<?php
// admin/email_customers.php
require_once __DIR__ . '/../lib/auth.php'; admin_required();
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/util.php';
require_once __DIR__ . '/../lib/mail.php';
include __DIR__ . '/_style.php';

// ---------------------------------------------------------
// AJAX: delete template (returns JSON, no HTML rendered)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'delete_template'
    && (($_POST['ajax'] ?? '') === '1')) {

    header('Content-Type: application/json');

    if (!csrf_ok()) {
        echo json_encode(['ok' => false, 'error' => 'Security check failed.']);
        exit;
    }

    $tid = (int)($_POST['template_id'] ?? 0);
    if (!$tid) {
        echo json_encode(['ok' => false, 'error' => 'Missing template id.']);
        exit;
    }

    try {
        $stmt = db()->prepare("DELETE FROM email_templates WHERE id = ?");
        $stmt->execute([$tid]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => 'Failed to delete template.']);
    }
    exit;
}

// ---------------------------------------------------------
// Helpers
// ---------------------------------------------------------

function load_template_by_id(int $id): ?array {
    if (!$id) return null;
    try {
        $stmt = db()->prepare("SELECT id, name, subject, body_html FROM email_templates WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Get recipients based on segment & minimum spend.
 *
 * Returns rows with keys: email, name
 */
function get_broadcast_recipients(string $segment, float $spentMin): array {
    $db = db();

    switch ($segment) {
        case 'has_order':
            $sql = "
                SELECT MAX(c.name) AS name, c.email
                FROM customers c
                JOIN orders o ON o.customer_id = c.id
                WHERE c.email <> '' AND c.email IS NOT NULL
                GROUP BY c.email
                ORDER BY c.email
            ";
            $stmt = $db->query($sql);
            return $stmt->fetchAll();

        case 'last_30':
            $sql = "
                SELECT MAX(c.name) AS name, c.email
                FROM customers c
                JOIN orders o ON o.customer_id = c.id
                WHERE c.email <> '' AND c.email IS NOT NULL
                  AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY c.email
                ORDER BY c.email
            ";
            $stmt = $db->query($sql);
            return $stmt->fetchAll();

        case 'last_90':
            $sql = "
                SELECT MAX(c.name) AS name, c.email
                FROM customers c
                JOIN orders o ON o.customer_id = c.id
                WHERE c.email <> '' AND c.email IS NOT NULL
                  AND o.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                GROUP BY c.email
                ORDER BY c.email
            ";
            $stmt = $db->query($sql);
            return $stmt->fetchAll();

        case 'spent_min':
            // Lifetime spend >= $spentMin
            $sql = "
                SELECT MAX(c.name) AS name,
                       c.email,
                       COALESCE(SUM(o.total_amount), 0) AS total_spent
                FROM customers c
                JOIN orders o ON o.customer_id = c.id
                WHERE c.email <> '' AND c.email IS NOT NULL
                GROUP BY c.email
                HAVING total_spent >= :min_spent
                ORDER BY c.email
            ";
            $stmt = $db->prepare($sql);
            $min = max(0, $spentMin);
            $stmt->execute([':min_spent' => $min]);
            return $stmt->fetchAll();

        case 'marketing_opt_in':
            $sql = "
                SELECT MAX(c.name) AS name, c.email
                FROM customers c
                WHERE c.email <> '' AND c.email IS NOT NULL
                  AND c.marketing_opt_in = 1
                GROUP BY c.email
                ORDER BY c.email
            ";
            $stmt = $db->query($sql);
            return $stmt->fetchAll();

        case 'all':
        default:
            $sql = "
                SELECT MIN(id) AS id, MAX(name) AS name, email
                FROM customers
                WHERE email <> '' AND email IS NOT NULL
                GROUP BY email
                ORDER BY email
            ";
            $stmt = $db->query($sql);
            return $stmt->fetchAll();
    }
}

// ---------------------------------------------------------
// State / form vars
// ---------------------------------------------------------
$errors = [];
$flashSuccess = null;
$flashError = null;

$action = $_POST['action'] ?? '';

$currentSubject     = $_POST['subject']       ?? '';
$currentMessage     = $_POST['message']       ?? '';
$testEmail          = $_POST['test_email']    ?? '';
$templateNameField  = $_POST['template_name'] ?? '';
$selectedTemplateId = (int)($_POST['template_id'] ?? 0);

// Segmentation
$segment        = $_POST['segment']    ?? 'all';
$spentMinRaw    = $_POST['spent_min']  ?? '';
$spentMin       = $spentMinRaw !== '' ? (float)$spentMinRaw : 0.0;

// ---------------------------------------------------------
// Handle main POST actions (non-AJAX)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== 'delete_template') {
    if (!csrf_ok()) {
        $errors[] = 'Security check failed. Please try again.';
    }

    if (!$errors) {
        if ($action === 'send_broadcast') {
            // ---- Broadcast to selected segment ----
            $subject = trim($currentSubject);
            $message = trim($currentMessage);

            if ($subject === '') {
                $errors[] = 'Subject is required.';
            }
            if ($message === '') {
                $errors[] = 'Message is required.';
            }

            if (!$errors) {
                $recipients = get_broadcast_recipients($segment, $spentMin);
                $total = count($recipients);
                $ok = 0;
                $fail = 0;

                foreach ($recipients as $row) {
                    $toEmail = $row['email'];
                    if (!$toEmail) continue;

                    $toName  = $row['name'] ?: $toEmail;
                    $html = $message;
                    $text = strip_tags($message);

                    if (send_email($toEmail, $toName, $subject, $html, $text)) {
                        $ok++;
                    } else {
                        $fail++;
                    }
                }

                if ($total === 0) {
                    $flashError = 'No customers matched the selected segment.';
                } elseif ($fail === 0) {
                    $flashSuccess = "Successfully sent {$ok} emails.";
                } else {
                    $flashError = "Attempted to send {$total} emails. Success: {$ok}, Failed: {$fail}. Check error logs for details.";
                }
            }

        } elseif ($action === 'send_test') {
            // ---- Send a test email only ----
            $subject = trim($currentSubject);
            $message = trim($currentMessage);
            $test    = trim($testEmail);

            if ($subject === '') {
                $errors[] = 'Subject is required for a test email.';
            }
            if ($message === '') {
                $errors[] = 'Message is required for a test email.';
            }
            if ($test === '') {
                $errors[] = 'Please enter a test email address.';
            }

            if (!$errors) {
                $html = $message;
                $text = strip_tags($message);

                if (send_email($test, $test, $subject, $html, $text)) {
                    $flashSuccess = "Test email sent to {$test}.";
                } else {
                    $flashError = "Failed to send test email to {$test}. Check logs for details.";
                }
            }

        } elseif ($action === 'save_template') {
            // ---- Save current subject/message as a template ----
            $subject = trim($currentSubject);
            $message = trim($currentMessage);
            $tname   = trim($templateNameField);

            if ($tname === '') {
                $errors[] = 'Template name is required.';
            }
            if ($subject === '') {
                $errors[] = 'Subject is required to save a template.';
            }
            if ($message === '') {
                $errors[] = 'Message is required to save a template.';
            }

            if (!$errors) {
                try {
                    $stmt = db()->prepare("
                        INSERT INTO email_templates (name, subject, body_html, created_at, updated_at)
                        VALUES (?, ?, ?, NOW(), NOW())
                    ");
                    $stmt->execute([$tname, $subject, $message]);
                    $flashSuccess = "Template \"{$tname}\" saved.";
                    $templateNameField = '';
                } catch (Exception $e) {
                    $flashError = 'Failed to save template (is the email_templates table created?).';
                }
            }

        } elseif ($action === 'load_template') {
            // ---- Load an existing template into the editor ----
            if (!$selectedTemplateId) {
                $errors[] = 'Please select a template to load.';
            } else {
                $tpl = load_template_by_id($selectedTemplateId);
                if ($tpl) {
                    $currentSubject = $tpl['subject'];
                    $currentMessage = $tpl['body_html'];
                    $flashSuccess = 'Template loaded.';
                } else {
                    $errors[] = 'Could not load template. It may have been removed.';
                }
            }
        }
    }
}

// ---------------------------------------------------------
// Recipient count for current segment
// ---------------------------------------------------------
$recipientsForLabel = [];
try {
    $recipientsForLabel = get_broadcast_recipients($segment, $spentMin);
} catch (Exception $e) {
    // If something fails (e.g., table not ready), just show 0
    $recipientsForLabel = [];
}
$totalRecipients = count($recipientsForLabel);

// ---------------------------------------------------------
// Load templates for dropdown
// ---------------------------------------------------------
$templates = [];
try {
    $stmt = db()->query("SELECT id, name, subject FROM email_templates ORDER BY created_at DESC");
    $templates = $stmt->fetchAll();
} catch (Exception $e) {
    $templates = [];
}

// Segmentation label helper (for UI only)
function segment_label(string $segment): string {
    switch ($segment) {
        case 'has_order':        return 'Customers with ≥1 order';
        case 'last_30':          return 'Customers who ordered in last 30 days';
        case 'last_90':          return 'Customers who ordered in last 90 days';
        case 'spent_min':        return 'Customers who spent ≥ given amount';
        case 'marketing_opt_in': return 'Marketing opt-in only';
        case 'all':
        default:                 return 'All customers';
    }
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
            <div class="admin-title">✉ Email Customers</div>
            <div class="admin-actions">
                <a class="tab" href="/admin/dashboard.php">📊 Analytics</a>
                <a class="tab" href="/admin/products.php">🧾 Manage Products</a>
                <a class="tab" href="/admin/analytics.php">Analytics</a>
                <a class="tab" href="/admin/orders.php">📃 Orders</a>
                <a class="tab active" href="/admin/email_customers.php">✉ Email Customers</a>
                <a class="tab" href="/admin/product_edit.php">➕ Add Product</a>
            </div>
        </div>
    </div>
</div>

<div class="section">
    <div class="card">
        <h3 style="margin-top:0">Broadcast Email</h3>

        <p style="color:#4b5563;margin-top:4px">
            Segment: <strong><?= h(segment_label($segment)) ?></strong><br>
            This will send an email to
            <strong><?= (int)$totalRecipients ?></strong>
            customers matching the selected segment.
        </p>

        <?php if ($errors): ?>
            <div style="margin-top:10px;padding:10px;border-radius:6px;background:#fef2f2;color:#991b1b">
                <strong>There were some problems:</strong>
                <ul style="margin:6px 0 0 20px">
                    <?php foreach ($errors as $e): ?>
                        <li><?= h($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($flashSuccess): ?>
            <div style="margin-top:10px;padding:10px;border-radius:6px;background:#ecfdf3;color:#166534">
                <?= h($flashSuccess) ?>
            </div>
        <?php endif; ?>

        <?php if ($flashError): ?>
            <div style="margin-top:10px;padding:10px;border-radius:6px;background:#fef2f2;color:#b91c1c">
                <?= h($flashError) ?>
            </div>
        <?php endif; ?>

        <form method="post" id="broadcast-form" style="margin-top:16px;display:flex;flex-direction:column;gap:18px">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

            <!-- Segmentation -->
            <div>
                <span style="display:block;font-weight:600;margin-bottom:4px">Send to segment</span>
                <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
                    <select
                        name="segment"
                        style="flex:1;min-width:220px;padding:8px 10px;border-radius:6px;border:1px solid #d1d5db"
                    >
                        <option value="all" <?= $segment === 'all' ? 'selected' : '' ?>>All customers</option>
                        <option value="has_order" <?= $segment === 'has_order' ? 'selected' : '' ?>>Customers with ≥1 order</option>
                        <option value="last_30" <?= $segment === 'last_30' ? 'selected' : '' ?>>Ordered in last 30 days</option>
                        <option value="last_90" <?= $segment === 'last_90' ? 'selected' : '' ?>>Ordered in last 90 days</option>
                        <option value="spent_min" <?= $segment === 'spent_min' ? 'selected' : '' ?>>Lifetime spend ≥ amount</option>
                        <option value="marketing_opt_in" <?= $segment === 'marketing_opt_in' ? 'selected' : '' ?>>Marketing opt-in only</option>
                    </select>

                    <div id="spent-min-wrapper" style="display:<?= $segment === 'spent_min' ? 'flex' : 'none' ?>;align-items:center;gap:4px">
                        <span style="font-size:13px;color:#4b5563">$</span>
                        <input
                            type="number"
                            name="spent_min"
                            step="0.01"
                            min="0"
                            value="<?= h($spentMinRaw) ?>"
                            style="width:120px;padding:8px 10px;border-radius:6px;border:1px solid #d1d5db"
                            placeholder="50.00"
                        >
                    </div>
                </div>
                <p style="margin-top:6px;font-size:12px;color:#9ca3af">
                    Change the segment and click any button to refresh the estimated recipient count.
                </p>
            </div>

            <!-- Subject -->
            <label>
                <span style="display:block;font-weight:600;margin-bottom:4px">Subject</span>
                <input
                    type="text"
                    name="subject"
                    value="<?= h($currentSubject) ?>"
                    style="width:100%;padding:8px 10px;border-radius:6px;border:1px solid #d1d5db"
                    placeholder="New collection launch, holiday sale, thank you note, etc."
                >
            </label>

            <!-- Message body -->
            <label>
                <span style="display:block;font-weight:600;margin-bottom:4px">Message</span>
                <small style="display:block;color:#6b7280;margin-bottom:4px">
                    HTML is allowed. This will be sent as the email body.
                </small>
                <textarea
                    name="message"
                    rows="10"
                    style="width:100%;padding:8px 10px;border-radius:6px;border:1px solid #d1d5db;font-family:system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif"
                    placeholder="Hi there! &#10;&#10;Thanks so much for supporting Ava D Creates. Here’s what’s new..."
                ><?= h($currentMessage) ?></textarea>
            </label>

            <!-- Broadcast button -->
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                <button
                    type="submit"
                    name="action"
                    value="send_broadcast"
                    class="btn"
                    style="background:#4c1d95;color:white;padding:8px 16px;border-radius:999px;border:none;cursor:pointer"
                    onclick="return confirm('Send this email to all customers in the selected segment?')"
                >
                    ✉ Send to segment
                </button>
                <span style="font-size:12px;color:#6b7280">
                    Use the test send below before broadcasting if you’re unsure.
                </span>
            </div>

            <hr style="border:none;border-top:1px solid #e5e7eb;margin:4px 0 0 0">

            <!-- Test send -->
            <div>
                <div style="font-weight:600;margin-bottom:4px">Test email</div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                    <input
                        type="email"
                        name="test_email"
                        value="<?= h($testEmail) ?>"
                        style="flex:1;min-width:200px;padding:8px 10px;border-radius:6px;border:1px solid #d1d5db"
                        placeholder="your@email.com"
                    >
                    <button
                        type="submit"
                        name="action"
                        value="send_test"
                        class="btn"
                        style="background:#111827;color:white;padding:8px 16px;border-radius:999px;border:none;cursor:pointer"
                    >
                        🧪 Send test only
                    </button>
                </div>
                <p style="margin-top:6px;font-size:12px;color:#9ca3af">
                    Sends this email only to the address above. No customers will receive it.
                </p>
            </div>

            <hr style="border:none;border-top:1px solid #e5e7eb;margin:0">

            <!-- Templates -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;margin-top:4px">
                <!-- Load & delete template -->
                <div>
                    <div style="font-weight:600;margin-bottom:4px">Saved templates</div>
                    <div style="display:flex;gap:8px;align-items:center">
                        <select
                            id="template-select"
                            name="template_id"
                            style="flex:1;min-width:0;padding:8px 10px;border-radius:6px;border:1px solid #d1d5db"
                        >
                            <option value="0">— Select a template —</option>
                            <?php foreach ($templates as $tpl): ?>
                                <option value="<?= (int)$tpl['id'] ?>" <?= $selectedTemplateId === (int)$tpl['id'] ? 'selected' : '' ?>>
                                    <?= h($tpl['name']) ?> — <?= h($tpl['subject']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button
                            type="submit"
                            name="action"
                            value="load_template"
                            class="btn"
                            style="white-space:nowrap;background:#4b5563;color:white;padding:8px 10px;border-radius:999px;border:none;cursor:pointer"
                        >
                            ⤵ Load
                        </button>
                        <button
                            type="button"
                            id="delete-template-btn"
                            class="btn"
                            style="white-space:nowrap;background:#b91c1c;color:white;padding:8px 10px;border-radius:999px;border:none;cursor:pointer"
                        >
                            🗑
                        </button>
                    </div>
                    <p style="margin-top:6px;font-size:12px;color:#9ca3af">
                        Load will replace the subject and message above. Delete removes the selected template via AJAX.
                    </p>
                </div>

                <!-- Save template -->
                <div>
                    <div style="font-weight:600;margin-bottom:4px">Save current as template</div>
                    <div style="display:flex;gap:8px;align-items:center">
                        <input
                            type="text"
                            name="template_name"
                            value="<?= h($templateNameField) ?>"
                            style="flex:1;min-width:0;padding:8px 10px;border-radius:6px;border:1px solid #d1d5db"
                            placeholder="e.g. New collection launch"
                        >
                        <button
                            type="submit"
                            name="action"
                            value="save_template"
                            class="btn"
                            style="white-space:nowrap;background:#16a34a;color:white;padding:8px 12px;border-radius:999px;border:none;cursor:pointer"
                        >
                            💾 Save
                        </button>
                    </div>
                    <p style="margin-top:6px;font-size:12px;color:#9ca3af">
                        Saves the current subject + message so you can reuse it later.
                    </p>
                </div>
            </div>

            <p style="margin-top:12px;font-size:12px;color:#9ca3af">
                Tip: Use templates for seasonal drops, launch announcements, and common updates.
            </p>
        </form>
    </div>
</div>

<script>
    (function() {
        const segmentSelect = document.querySelector('select[name="segment"]');
        const spentWrapper  = document.getElementById('spent-min-wrapper');
        const deleteBtn     = document.getElementById('delete-template-btn');
        const templateSelect = document.getElementById('template-select');
        const csrfInput     = document.querySelector('#broadcast-form input[name="csrf"]');

        if (segmentSelect && spentWrapper) {
            segmentSelect.addEventListener('change', () => {
                if (segmentSelect.value === 'spent_min') {
                    spentWrapper.style.display = 'flex';
                } else {
                    spentWrapper.style.display = 'none';
                }
            });
        }

        if (deleteBtn && templateSelect && csrfInput) {
            deleteBtn.addEventListener('click', () => {
                const tid = templateSelect.value;
                if (!tid || tid === '0') {
                    alert('Please select a template to delete.');
                    return;
                }
                if (!confirm('Delete this template? This cannot be undone.')) {
                    return;
                }

                const formData = new URLSearchParams();
                formData.set('action', 'delete_template');
                formData.set('ajax', '1');
                formData.set('template_id', tid);
                formData.set('csrf', csrfInput.value);

                fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                    },
                    body: formData.toString()
                })
                    .then(r => r.json())
                    .then(data => {
                        if (data.ok) {
                            // Remove option from select
                            const opt = templateSelect.querySelector('option[value="' + tid + '"]');
                            if (opt) opt.remove();
                            templateSelect.value = '0';
                            alert('Template deleted.');
                        } else {
                            alert(data.error || 'Failed to delete template.');
                        }
                    })
                    .catch(() => {
                        alert('Error deleting template. Please try again.');
                    });
            });
        }
    })();
</script>
