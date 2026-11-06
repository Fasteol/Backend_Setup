<?php
declare(strict_types=1);

/**
 * Seed data produk dari DummyJSON.
 *
 * Jalankan:
 *   docker compose exec php php /var/www/html/seed.php
 *
 * Aman dijalankan berulang kali: memakai ON DUPLICATE KEY UPDATE berdasarkan id.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("seed.php hanya bisa dijalankan lewat CLI.\n");
}

require __DIR__ . '/db.php';

const SEED_SOURCE_URL = 'https://dummyjson.com/products?limit=0';

function fetch_json(string $url): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status >= 400) {
            throw new RuntimeException("Gagal mengambil {$url} (HTTP {$status}) {$error}");
        }
    } else {
        $context = stream_context_create(['http' => ['timeout' => 60, 'header' => "Accept: application/json\r\n"]]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException("Gagal mengambil {$url}. Pastikan ada koneksi internet dan ekstensi openssl/curl aktif.");
        }
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException('Respons DummyJSON bukan JSON yang valid');
    }

    return $data;
}

function connect_with_retry(int $attempts = 15, int $delaySeconds = 2): PDO
{
    for ($i = 1; $i <= $attempts; $i++) {
        try {
            return db();
        } catch (PDOException $e) {
            fwrite(STDERR, "Menunggu MySQL siap ({$i}/{$attempts}): {$e->getMessage()}\n");
            sleep($delaySeconds);
        }
    }

    throw new RuntimeException('Tidak bisa terhubung ke MySQL. Cek container mysql dan kredensial .env');
}

try {
    $pdo = connect_with_retry();
    ensure_schema($pdo);

    echo "Mengambil data dari " . SEED_SOURCE_URL . " ...\n";
    $payload = fetch_json(SEED_SOURCE_URL);
    $products = $payload['products'] ?? [];

    if (!$products) {
        throw new RuntimeException("Data 'products' kosong pada respons DummyJSON");
    }

    $cols = PRODUCT_COLUMNS;
    $insertCols = array_merge(['id'], $cols);

    $sql = sprintf(
        'INSERT INTO products (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
        implode(', ', array_map(fn ($c) => "`{$c}`", $insertCols)),
        implode(', ', array_map(fn ($c) => ":{$c}", $insertCols)),
        implode(', ', array_map(fn ($c) => "`{$c}` = VALUES(`{$c}`)", $cols))
    );
    $stmt = $pdo->prepare($sql);

    $pdo->beginTransaction();
    $processed = 0;

    foreach ($products as $product) {
        if (!isset($product['id'])) {
            continue;
        }

        $params = product_params($product, true);
        $stmt->bindValue(':id', (int) $product['id'], PDO::PARAM_INT);
        foreach ($params as $col => $value) {
            $stmt->bindValue(":{$col}", $value);
        }
        $stmt->execute();
        $processed++;
    }

    $pdo->commit();

    $total = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    echo "Selesai: {$processed} produk diproses. Total di tabel products: {$total}.\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Seed gagal: ' . $e->getMessage() . "\n");
    exit(1);
}
