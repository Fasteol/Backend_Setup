<?php
declare(strict_types=1);

/**
 * Koneksi database, skema tabel, dan helper produk yang dipakai
 * oleh index.php (API) dan seed.php (impor data DummyJSON).
 *
 * File ini hanya berisi definisi fungsi dan tidak menghasilkan output.
 */

// Kolom bertipe JSON di tabel products
const PRODUCT_JSON_COLUMNS = ['tags', 'dimensions', 'reviews', 'meta', 'images'];

// Semua kolom produk selain id
const PRODUCT_COLUMNS = [
    'title', 'description', 'category', 'price', 'discountPercentage', 'rating',
    'stock', 'tags', 'brand', 'sku', 'weight', 'dimensions', 'warrantyInformation',
    'shippingInformation', 'availabilityStatus', 'reviews', 'returnPolicy',
    'minimumOrderQuantity', 'meta', 'images', 'thumbnail',
];

// Nilai bawaan untuk kolom NOT NULL jika tidak diisi
const PRODUCT_DEFAULTS = [
    'price' => 0,
    'discountPercentage' => 0,
    'rating' => 0,
    'stock' => 0,
    'minimumOrderQuantity' => 1,
];

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // Prioritas: DB_* (di-set docker-compose untuk service php), lalu MYSQL_* (dari .env),
    // lalu nilai bawaan yang cocok dengan .env.example dan nama service mysql.
    $host = getenv('DB_HOST') ?: (getenv('MYSQL_HOST') ?: 'mysql');
    $port = getenv('DB_PORT') ?: (getenv('MYSQL_PORT') ?: '3306');
    $name = getenv('DB_DATABASE') ?: (getenv('MYSQL_DATABASE') ?: 'app');
    $user = getenv('DB_USERNAME') ?: (getenv('MYSQL_USER') ?: 'app');
    $pass = getenv('DB_PASSWORD') ?: (getenv('MYSQL_PASSWORD') ?: 'app_secret');

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $pdo;
}

/** Membuat tabel products hanya jika belum ada (tidak mengisi data). */
function ensure_schema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS products (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    category VARCHAR(100) NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    discountPercentage DECIMAL(5,2) NOT NULL DEFAULT 0,
    rating DECIMAL(3,2) NOT NULL DEFAULT 0,
    stock INT NOT NULL DEFAULT 0,
    tags JSON NULL,
    brand VARCHAR(100) NULL,
    sku VARCHAR(100) NULL,
    weight DECIMAL(8,2) NULL,
    dimensions JSON NULL,
    warrantyInformation VARCHAR(255) NULL,
    shippingInformation VARCHAR(255) NULL,
    availabilityStatus VARCHAR(50) NULL,
    reviews JSON NULL,
    returnPolicy VARCHAR(255) NULL,
    minimumOrderQuantity INT NOT NULL DEFAULT 1,
    meta JSON NULL,
    images JSON NULL,
    thumbnail VARCHAR(500) NULL,
    PRIMARY KEY (id),
    INDEX idx_products_category (category),
    INDEX idx_products_price (price),
    INDEX idx_products_rating (rating)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
}

/**
 * Mengubah input (array dari JSON/form) menjadi parameter siap simpan.
 * Hanya kolom yang ada di whitelist yang diambil; kolom JSON di-encode.
 *
 * @param bool $fillMissing isi kolom yang tidak ada dengan default/NULL (untuk insert/upsert)
 */
function product_params(array $in, bool $fillMissing = false): array
{
    $out = [];

    foreach (PRODUCT_COLUMNS as $col) {
        if (!array_key_exists($col, $in)) {
            if ($fillMissing) {
                $out[$col] = PRODUCT_DEFAULTS[$col] ?? null;
            }
            continue;
        }

        $value = $in[$col];

        if (in_array($col, PRODUCT_JSON_COLUMNS, true)) {
            $out[$col] = $value === null
                ? null
                : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            continue;
        }

        if (is_array($value)) {
            throw new InvalidArgumentException("Field '{$col}' harus berupa nilai tunggal, bukan array/object");
        }

        if ($value === null && array_key_exists($col, PRODUCT_DEFAULTS)) {
            $value = PRODUCT_DEFAULTS[$col];
        }

        $out[$col] = is_bool($value) ? (int) $value : $value;
    }

    return $out;
}

/** Mengubah satu baris database menjadi struktur JSON yang rapi (tipe data benar). */
function hydrate_product(array $row): array
{
    foreach (PRODUCT_JSON_COLUMNS as $col) {
        if (array_key_exists($col, $row)) {
            $row[$col] = $row[$col] === null ? null : json_decode((string) $row[$col], true);
        }
    }

    $row['id'] = (int) $row['id'];

    foreach (['price', 'discountPercentage', 'rating', 'weight'] as $col) {
        if (array_key_exists($col, $row) && $row[$col] !== null) {
            $row[$col] = (float) $row[$col];
        }
    }

    foreach (['stock', 'minimumOrderQuantity'] as $col) {
        if (array_key_exists($col, $row) && $row[$col] !== null) {
            $row[$col] = (int) $row[$col];
        }
    }

    return $row;
}