<?php
/**
 * Import Etsy CSV into your products + product_images tables.
 * Tailored for schema:
 *   products(id, name, category ENUM('bracelet','necklace'), price DEC(10,2),
 *            description TEXT NULL, stock INT, featured TINYINT(1) DEFAULT 0,
 *            image_url VARCHAR(500) NULL, created_at, updated_at)
 *   product_images(id, product_id, image_url, is_primary, sort_order, created_at)
 *
 * CSV headers supported:
 *  TITLE, DESCRIPTION, PRICE, CURRENCY_CODE, QUANTITY, TAGS, MATERIALS,
 *  IMAGE1..IMAGE10, SKU, VARIATION ... (ignored)
 *
 * Images:
 *  - Downloads IMAGE1..IMAGE10 to /uploads/products
 *  - First image is primary (also copied to products.image_url)
 *
 * Category:
 *  - Force via --category=bracelet|necklace or form select
 *  - Else guessed from TITLE/TAGS: contains 'necklace' => 'necklace', else 'bracelet'
 *
 * Flags:
 *  --commit (perform DB writes) | --dry-run (default)
 *  --csv=/path/to/file.csv
 *  --category=bracelet|necklace|auto
 *  --replace-images (delete existing product_images before inserting new)
 */

declare(strict_types=1);
@ini_set('memory_limit','1024M');
@ini_set('max_execution_time','0');

require_once __DIR__ . '/../lib/db.php';

function get_pdo(): PDO {
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
    if (function_exists('db')) { $x = db(); if ($x instanceof PDO) return $x; }
    if (function_exists('get_db')) { $x = get_db(); if ($x instanceof PDO) return $x; }
    throw new RuntimeException("Could not obtain PDO from db.php");
}

function ensure_dir(string $dir): void {
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Unable to create directory: $dir");
    }
}

function slugify(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('~[^\pL0-9]+~u', '-', $s);
    $s = trim($s, '-');
    $s = preg_replace('~[^-a-z0-9]+~', '', $s);
    return $s ?: 'n-a';
}

function read_csv_assoc(string $path): array {
    if (!file_exists($path)) throw new RuntimeException("CSV not found: $path");
    $fh = fopen($path, 'r');
    if (!$fh) throw new RuntimeException("Cannot open CSV: $path");
    $headers = fgetcsv($fh);
    if (!$headers) return ['headers'=>[], 'rows'=>[]];
    $headers = array_map(fn($h)=>trim((string)$h), $headers);

    $rows = [];
    while (($cols = fgetcsv($fh)) !== false) {
        $row = [];
        foreach ($headers as $i=>$h) $row[$h] = $cols[$i] ?? '';
        // skip totally blank rows
        if (!array_filter($row, fn($v)=>trim((string)$v) !== '')) continue;
        $rows[] = $row;
    }
    fclose($fh);
    return ['headers'=>$headers, 'rows'=>$rows];
}

function guess_category(string $title, string $tags): string {
    $hay = strtolower($title . ' ' . $tags);
    if (str_contains($hay, 'necklace')) return 'necklace';
    // If you sell more than bracelets/necklaces later, adjust here.
    return 'bracelet';
}

function clean_price($raw): float {
    $p = preg_replace('/[^0-9.]/', '', (string)$raw);
    return $p !== '' ? (float)$p : 0.0;
}

function fetch_image_to_local(string $url, string $baseName, string $uploadDir): ?string {
    $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp','gif'])) $ext = 'jpg';
    $abs = rtrim($uploadDir,'/').'/'.$baseName.'.'.$ext;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $bin = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($http >= 200 && $http < 300 && $bin) {
        if (file_put_contents($abs, $bin) !== false) return $abs;
    } else {
        error_log("IMG DL FAIL [$http] $url :: $err");
    }
    return null;
}

function to_public_path(string $abs): string {
    // e.g. /var/www/html/uploads/products/foo.jpg -> /uploads/products/foo.jpg
    $pos = strpos($abs, '/uploads/products/');
    if ($pos !== false) return substr($abs, $pos);
    // fallback
    return '/uploads/products/'.basename($abs);
}

function find_or_insert_product(PDO $pdo, array $p, bool $commit): int {
    // "Uniqueness" heuristic: by (name, category)
    $sel = $pdo->prepare("SELECT id FROM products WHERE name = :n AND category = :c LIMIT 1");
    $sel->execute([':n'=>$p['name'], ':c'=>$p['category']]);
    $id = $sel->fetchColumn();

    if ($id) {
        if ($commit) {
            $upd = $pdo->prepare("
                UPDATE products
                   SET price=:pr, description=:d, stock=:st, featured=:f,
                       image_url=:img, updated_at=NOW()
                 WHERE id=:id
            ");
            $upd->execute([
                ':pr'=>$p['price'], ':d'=>$p['description'], ':st'=>$p['stock'],
                ':f'=>$p['featured'], ':img'=>$p['image_url'], ':id'=>$id
            ]);
        }
        return (int)$id;
    }

    if ($commit) {
        $ins = $pdo->prepare("
            INSERT INTO products (name, category, price, description, stock, featured, image_url, created_at, updated_at)
            VALUES (:n, :c, :pr, :d, :st, :f, :img, NOW(), NOW())
        ");
        $ins->execute([
            ':n'=>$p['name'], ':c'=>$p['category'], ':pr'=>$p['price'],
            ':d'=>$p['description'], ':st'=>$p['stock'], ':f'=>$p['featured'],
            ':img'=>$p['image_url']
        ]);
        return (int)$pdo->lastInsertId();
    }

    return 0; // dry-run
}


function wipe_product_images(PDO $pdo, int $productId, bool $commit): void {
    if (!$commit || $productId <= 0) return;
    $pdo->prepare("DELETE FROM product_images WHERE product_id = :id")->execute([':id'=>$productId]);
}

function insert_images(PDO $pdo, int $productId, array $publicPaths, bool $commit): void {
    if (!$commit || $productId <= 0 || empty($publicPaths)) return;
    $sort = 1;
    foreach ($publicPaths as $i=>$p) {
        $pdo->prepare("
            INSERT INTO product_images (product_id, image_url, is_primary, sort_order, created_at)
            VALUES (:pid, :url, :pri, :ord, NOW())
        ")->execute([
            ':pid'=>$productId,
            ':url'=>$p,
            ':pri'=> ($i === 0) ? 1 : 0,
            ':ord'=> $sort++
        ]);
    }
}

// ------------ MAIN ------------
function main(): void {
    $isCli = (php_sapi_name() === 'cli');
    $commit = false;
    $csvPath = null;
    $forceCategory = 'auto';
    $replaceImages = false;

    if ($isCli) {
        foreach ($GLOBALS['argv'] as $arg) {
            if (str_starts_with($arg, '--csv=')) $csvPath = substr($arg, 6);
            if ($arg === '--commit') $commit = true;
            if ($arg === '--dry-run') $commit = false;
            if (str_starts_with($arg, '--category=')) $forceCategory = strtolower(substr($arg, 11));
            if ($arg === '--replace-images') $replaceImages = true;
        }
        if (!$csvPath) {
            fwrite(STDERR, "Usage: php import_etsy.php --csv=/path/to/etsy.csv [--commit|--dry-run] [--category=bracelet|necklace|auto] [--replace-images]\n");
            exit(2);
        }
        run_import($csvPath, $commit, $forceCategory, $replaceImages, false);
        return;
    }

    // Browser flow
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $commit = isset($_POST['commit']) && $_POST['commit'] === '1';
        $forceCategory = $_POST['category'] ?? 'auto';
        $replaceImages = isset($_POST['replace_images']) && $_POST['replace_images'] === '1';

        if (!empty($_POST['csv_path'])) {
            $csvPath = $_POST['csv_path'];
        } elseif (!empty($_FILES['csv']['tmp_name']) && $_FILES['csv']['error'] === UPLOAD_ERR_OK) {
            $csvPath = $_FILES['csv']['tmp_name'];
        }

        if (!$csvPath) {
            echo render_form("Please provide a CSV file.");
            return;
        }
        run_import($csvPath, $commit, $forceCategory, $replaceImages, true);
        return;
    }

    echo render_form();
}

function render_form(string $msg=''): string {
    $html = '<!doctype html><html><head><meta charset="utf-8"><title>Import Etsy</title>
    <style>
    body{font-family:system-ui,Segoe UI,Roboto,Helvetica,Arial,sans-serif;padding:24px;max-width:960px;margin:auto}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;box-shadow:0 1px 2px rgba(0,0,0,.04);margin-top:16px}
    label{display:block;margin:8px 0 4px}
    input[type=file],input[type=text],select{width:100%;padding:8px;border:1px solid #d1d5db;border-radius:8px}
    .row{display:flex;gap:12px;align-items:center;margin-top:12px}
    .muted{color:#6b7280}
    button{padding:10px 14px;border-radius:10px;border:0;background:#111827;color:#fff;font-weight:600;cursor:pointer}
    pre{background:#0b1020;color:#e5e7eb;padding:12px;border-radius:10px;overflow:auto;max-height:360px}
    </style></head><body>';

    $html .= '<h1>Import Etsy Listings</h1>';
    if ($msg) $html .= '<p class="muted">'.$msg.'</p>';

    $html .= '<div class="card">
      <form method="post" enctype="multipart/form-data">
        <label>Etsy CSV file</label>
        <input type="file" name="csv" accept=".csv">
        <div class="muted">Or server path:</div>
        <input type="text" name="csv_path" placeholder="/path/to/etsy.csv">

        <label>Category</label>
        <select name="category">
          <option value="auto" selected>Auto (guess from TITLE/TAGS)</option>
          <option value="bracelet">bracelet</option>
          <option value="necklace">necklace</option>
        </select>

        <div class="row">
          <label><input type="checkbox" name="replace_images" value="1"> Replace existing images</label>
          <label><input type="checkbox" name="commit" value="1"> Commit (uncheck for dry-run)</label>
        </div>

        <div class="row"><button type="submit">Run Import</button></div>
      </form>
    </div>';

    $html .= '<div class="card muted">
      <b>Notes</b>
      <ul>
        <li>Supported columns: TITLE, DESCRIPTION, PRICE, QUANTITY, TAGS, IMAGE1..IMAGE10</li>
        <li>Images will be saved into <code>/uploads/products</code> and recorded in <code>product_images</code>.</li>
        <li>Primary image also set on <code>products.image_url</code>.</li>
      </ul>
    </div>';

    return $html.'</body></html>';
}

function run_import(string $csvPath, bool $commit, string $forceCategory, bool $replaceImages, bool $echoHtml): void {
    $pdo = get_pdo();
    $uploadDir = __DIR__ . '/../uploads/products';
    ensure_dir($uploadDir);

    $data = read_csv_assoc($csvPath);
    $headers = $data['headers'];
    $rows    = $data['rows'];
    $total   = count($rows);

    // map image headers
    $imgCols = [];
    foreach ($headers as $h) {
        if (preg_match('/^IMAGE(\d+)$/i', $h)) $imgCols[] = $h;
    }
    sort($imgCols); // IMAGE1..IMAGE10 order

    $added=0; $updated=0; $skipped=0; $imgSaved=0;
    $lines = [];

    foreach ($rows as $i=>$r) {
        $title = trim((string)($r['TITLE'] ?? ''));
        if ($title === '') { $skipped++; $lines[]="[#".($i+1)."] SKIP (no TITLE)"; continue; }

        $desc  = (string)($r['DESCRIPTION'] ?? '');
        $price = clean_price($r['PRICE'] ?? '0');
        $qty   = (int)($r['QUANTITY'] ?? 0);
        $tags  = (string)($r['TAGS'] ?? '');

        $cat = $forceCategory;
        if ($cat === 'auto' || $cat === '' || $cat === null) {
            $cat = guess_category($title, $tags);
        } else {
            $cat = ($cat === 'necklace') ? 'necklace' : 'bracelet';
        }

        // Gather image URLs
        $urls = [];
        foreach ($imgCols as $col) {
            $u = trim((string)($r[$col] ?? ''));
            if ($u !== '' && filter_var($u, FILTER_VALIDATE_URL)) $urls[] = $u;
        }
        $urls = array_values(array_unique($urls));

        // prepare product payload
        $product = [
            'name'        => $title,
            'category'    => $cat,
            'price'       => $price,
            'description' => $desc,
            'stock'       => $qty,
            'featured'    => 0,
            'image_url'   => null, // to be set with first image, if any
        ];

        // If replacing images, we need an id; try find by (name, category)
        $sel = $pdo->prepare("SELECT id, image_url FROM products WHERE name=:n AND category=:c LIMIT 1");
        $sel->execute([':n'=>$title, ':c'=>$cat]);
        $existing = $sel->fetch(PDO::FETCH_ASSOC);
        $existingId = $existing ? (int)$existing['id'] : 0;

        // Download images first (so we know primary path)
        $downloadedPublic = [];
        if (!empty($urls)) {
            $base = slugify($title) . '-' . substr(md5($title.$cat),0,6);
            $n=1;
            foreach ($urls as $u) {
                $abs = fetch_image_to_local($u, $base.'-'.$n, $uploadDir);
                if ($abs) {
                    $downloadedPublic[] = to_public_path($abs);
                    $imgSaved++;
                    $n++;
                }
            }
            if (!empty($downloadedPublic)) {
                $product['image_url'] = $downloadedPublic[0]; // primary
            }
        } else {
            // keep existing image_url if present and we aren't replacing
            if ($existing && !$replaceImages) {
                $product['image_url'] = $existing['image_url'];
            }
        }

        // upsert product
        $idBefore = $existingId;
        $pid = find_or_insert_product($pdo, $product, $commit);
        if ($pid === 0 && !$commit) {
            $lines[] = "[#".($i+1)."] DRY-RUN product: {$title} ({$cat}) images=".count($downloadedPublic);
        } else {
            if ($existingId > 0) { $updated++; } else { $added++; }
            $lines[] = "[#".($i+1)."] OK id=".($pid ?: $existingId)." name=\"{$title}\" cat={$cat} images=".count($downloadedPublic);
        }

        $targetId = $pid ?: $existingId;

        // --- VARIANTS from Etsy (ignore TYPE; use NAME + VALUES) ---
        $optName = trim((string)($r['VARIATION 1 NAME']   ?? ''));    // e.g. "Primary color"
        $optVals = trim((string)($r['VARIATION 1 VALUES'] ?? ''));    // e.g. "Pink, Purple, Blue"

        if ($commit && $targetId > 0 && $optVals !== '') {
            $optionLabel = $optName !== '' ? $optName : 'Color';
            $vals = preg_split('/[;,]+/', $optVals);
            $vals = array_values(array_unique(array_map(fn($v)=>trim($v), $vals)));

            $insVar = $pdo->prepare("
        INSERT IGNORE INTO product_variants (product_id, option_name, value, sku, price_delta, stock, sort_order)
        VALUES (:pid, :oname, :val, NULL, 0, NULL, :ord)
    ");
            $ord = 1;
            foreach ($vals as $v) {
                if ($v === '') continue;
                $insVar->execute([
                    ':pid'=>$targetId,
                    ':oname'=>$optionLabel,
                    ':val'=>$v,
                    ':ord'=>$ord++,
                ]);
            }
        }

        // Replace images if requested
        if ($replaceImages && $targetId > 0) {
            wipe_product_images($pdo, $targetId, $commit);
        }

        // Insert images
        if ($targetId > 0 && !empty($downloadedPublic)) {
            insert_images($pdo, $targetId, $downloadedPublic, $commit);
        }
    }

    $summary = "Imported: rows={$total}, added={$added}, updated={$updated}, skipped={$skipped}, images_saved={$imgSaved}.".($commit?" [COMMIT]":" [DRY-RUN]");
    if (php_sapi_name() === 'cli') {
        echo $summary."\n";
        foreach ($lines as $ln) echo $ln."\n";
    } else {
        echo '<div class="card"><b>'.$summary.'</b><pre>'.htmlspecialchars(implode("\n",$lines)).'</pre></div>';
        echo '<p><a href="import_etsy.php">← back</a></p>';
    }
}

main();
