<?php
declare(strict_types=1);

/**
 * Services Product API
 *
 * Semua endpoint memakai prefix /products (lihat README):
 *   GET    /products                      daftar produk (q, limit, skip, sortBy, order)
 *   GET    /products/{id}                 satu produk
 *   GET    /products/search?q=phone       cari produk
 *   GET    /products/category/{category}  filter kategori
 *   GET    /products/categories           daftar kategori + URL
 *   GET    /products/category-list        daftar slug kategori
 *   POST   /products/add                  tambah produk
 *   PUT    /products/{id}                 ubah produk
 *   PATCH  /products/{id}                 ubah sebagian/seluruh produk
 *   DELETE /products/{id}                 hapus produk
 */

require __DIR__ . '/db.php';

class HttpError extends RuntimeException
{
    public function __construct(public int $status, string $message, public array $headers = [])
    {
        parent::__construct($message);
    }
}

function respond(int $status, mixed $data): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
    if (isset($_GET['pretty'])) {
        $flags |= JSON_PRETTY_PRINT;
    }

    echo json_encode($data, $flags), "\n";
    exit;
}

function require_method(string $method, array $allowed): void
{
    if (!in_array($method, $allowed, true)) {
        throw new HttpError(405, 'Method tidak diizinkan untuk endpoint ini', ['Allow' => implode(', ', $allowed)]);
    }
}

function read_input(): array
{
    $raw = file_get_contents('php://input');

    if ($raw !== false && trim($raw) !== '') {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new HttpError(400, 'Body harus berupa JSON object yang valid');
        }
        return $data;
    }

    return $_POST;
}

function now_iso(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8080';

    return ($https ? 'https' : 'http') . '://' . $host;
}

function validate_product(array $in, bool $isCreate): void
{
    $errors = [];

    if ($isCreate && (!isset($in['title']) || !is_string($in['title']) || trim($in['title']) === '')) {
        $errors[] = "Field 'title' wajib diisi";
    } elseif (array_key_exists('title', $in) && (!is_string($in['title']) || trim($in['title']) === '')) {
        $errors[] = "Field 'title' tidak boleh kosong";
    }

    foreach (['price', 'discountPercentage', 'rating', 'weight'] as $field) {
        if (isset($in[$field]) && (!is_numeric($in[$field]) || (float) $in[$field] < 0)) {
            $errors[] = "Field '{$field}' harus berupa angka >= 0";
        }
    }

    foreach (['stock', 'minimumOrderQuantity'] as $field) {
        if (isset($in[$field]) && (filter_var($in[$field], FILTER_VALIDATE_INT) === false || (int) $in[$field] < 0)) {
            $errors[] = "Field '{$field}' harus berupa bilangan bulat >= 0";
        }
    }

    if ($errors) {
        throw new HttpError(422, implode('; ', $errors));
    }
}

function find_product(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    return $row ? hydrate_product($row) : null;
}

function find_product_or_404(PDO $pdo, int $id): array
{
    return find_product($pdo, $id) ?? throw new HttpError(404, "Produk dengan id '{$id}' tidak ditemukan");
}

/** Daftar produk dengan filter q/kategori, sort, limit, dan skip. */
function list_products(PDO $pdo, ?string $q = null, ?string $category = null): array
{
    $where = [];
    $bind = [];

    if ($q !== null && trim($q) !== '') {
        $like = '%' . addcslashes(trim($q), '%_\\') . '%';
        $where[] = '(title LIKE :q1 OR description LIKE :q2 OR brand LIKE :q3 OR category LIKE :q4)';
        $bind[':q1'] = $bind[':q2'] = $bind[':q3'] = $bind[':q4'] = $like;
    }

    if ($category !== null) {
        $where[] = 'category = :category';
        $bind[':category'] = $category;
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $sortable = ['id', 'title', 'price', 'rating', 'stock'];
    $sortBy = in_array($_GET['sortBy'] ?? '', $sortable, true) ? $_GET['sortBy'] : 'id';
    $order = strtolower((string) ($_GET['order'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

    $limit = isset($_GET['limit']) ? max(0, (int) $_GET['limit']) : 30;
    $skip = max(0, (int) ($_GET['skip'] ?? 0));

    if ($limit > 0) {
        $limitSql = "LIMIT {$limit} OFFSET {$skip}";
    } elseif ($skip > 0) {
        $limitSql = "LIMIT 18446744073709551615 OFFSET {$skip}";
    } else {
        $limitSql = '';
    }

    $count = $pdo->prepare("SELECT COUNT(*) FROM products {$whereSql}");
    $count->execute($bind);
    $total = (int) $count->fetchColumn();

    $stmt = $pdo->prepare("SELECT * FROM products {$whereSql} ORDER BY `{$sortBy}` {$order}, id ASC {$limitSql}");
    $stmt->execute($bind);
    $products = array_map('hydrate_product', $stmt->fetchAll());

    return [
        'products' => $products,
        'total' => $total,
        'skip' => $skip,
        'limit' => $limit > 0 ? $limit : count($products),
    ];
}

function category_slugs(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category <> '' ORDER BY category");

    return array_column($stmt->fetchAll(), 'category');
}

function create_product(PDO $pdo, array $in): array
{
    validate_product($in, true);

    if (!isset($in['meta']) || !is_array($in['meta'])) {
        $in['meta'] = ['createdAt' => now_iso(), 'updatedAt' => now_iso()];
    }

    $params = product_params($in, true);
    $cols = array_keys($params);

    $sql = sprintf(
        'INSERT INTO products (%s) VALUES (%s)',
        implode(', ', array_map(fn ($c) => "`{$c}`", $cols)),
        implode(', ', array_map(fn ($c) => ":{$c}", $cols))
    );

    $stmt = $pdo->prepare($sql);
    foreach ($params as $col => $value) {
        $stmt->bindValue(":{$col}", $value);
    }
    $stmt->execute();

    return find_product_or_404($pdo, (int) $pdo->lastInsertId());
}

function update_product(PDO $pdo, int $id, array $in): array
{
    $existing = find_product_or_404($pdo, $id);
    validate_product($in, false);

    // Pastikan ada minimal satu field valid sebelum meta otomatis ditambahkan
    if (product_params($in, false) === []) {
        throw new HttpError(422, 'Tidak ada field yang valid untuk diubah');
    }

    // Perbarui meta.updatedAt tanpa menghapus meta yang sudah ada
    $meta = is_array($existing['meta']) ? $existing['meta'] : [];
    if (isset($in['meta']) && is_array($in['meta'])) {
        $meta = array_merge($meta, $in['meta']);
    }
    $meta['updatedAt'] = now_iso();
    $in['meta'] = $meta;

    $params = product_params($in, false);

    $sets = implode(', ', array_map(fn ($c) => "`{$c}` = :{$c}", array_keys($params)));
    $stmt = $pdo->prepare("UPDATE products SET {$sets} WHERE id = :id");
    foreach ($params as $col => $value) {
        $stmt->bindValue(":{$col}", $value);
    }
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();

    return find_product_or_404($pdo, $id);
}

function delete_product(PDO $pdo, int $id): array
{
    $product = find_product_or_404($pdo, $id);

    $stmt = $pdo->prepare('DELETE FROM products WHERE id = :id');
    $stmt->execute([':id' => $id]);

    $product['isDeleted'] = true;
    $product['deletedOn'] = now_iso();

    return $product;
}

// ---------------------------------------------------------------------------
// Router
// ---------------------------------------------------------------------------

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $path = trim($path, '/');
    $segments = $path === '' ? [] : array_map('rawurldecode', explode('/', $path));

    if ($segments === []) {
        respond(200, [
            'name' => 'Services Product API',
            'docs' => 'Lihat README untuk daftar endpoint. Mulai dari GET /products',
            'endpoints' => [
                'GET /products',
                'GET /products/{id}',
                'GET /products/search?q=phone',
                'GET /products/category/{category}',
                'GET /products/categories',
                'GET /products/category-list',
                'POST /products/add',
                'PUT|PATCH /products/{id}',
                'DELETE /products/{id}',
            ],
        ]);
    }

    if ($segments[0] !== 'products') {
        throw new HttpError(404, 'Endpoint tidak ditemukan');
    }

    $pdo = db();
    ensure_schema($pdo);

    $count = count($segments);
    $second = $segments[1] ?? null;

    // /products
    if ($count === 1) {
        require_method($method, ['GET']);
        respond(200, list_products($pdo, $_GET['q'] ?? null));
    }

    // /products/category/{category}
    if ($count === 3 && $second === 'category') {
        require_method($method, ['GET']);
        respond(200, list_products($pdo, $_GET['q'] ?? null, $segments[2]));
    }

    if ($count === 2) {
        switch ($second) {
            case 'search':
                require_method($method, ['GET']);
                respond(200, list_products($pdo, $_GET['q'] ?? ''));

            case 'categories':
                require_method($method, ['GET']);
                $base = base_url();
                respond(200, array_map(
                    fn (string $slug) => [
                        'slug' => $slug,
                        'name' => ucwords(str_replace('-', ' ', $slug)),
                        'url' => $base . '/products/category/' . rawurlencode($slug),
                    ],
                    category_slugs($pdo)
                ));

            case 'category-list':
                require_method($method, ['GET']);
                respond(200, category_slugs($pdo));

            case 'add':
                require_method($method, ['POST']);
                respond(201, create_product($pdo, read_input()));
        }

        // /products/{id}
        if (ctype_digit((string) $second)) {
            $id = (int) $second;

            switch ($method) {
                case 'GET':
                    respond(200, find_product_or_404($pdo, $id));
                case 'PUT':
                case 'PATCH':
                    respond(200, update_product($pdo, $id, read_input()));
                case 'DELETE':
                    respond(200, delete_product($pdo, $id));
                default:
                    require_method($method, ['GET', 'PUT', 'PATCH', 'DELETE']);
            }
        }
    }

    throw new HttpError(404, 'Endpoint tidak ditemukan');
} catch (HttpError $e) {
    foreach ($e->headers as $name => $value) {
        header("{$name}: {$value}");
    }
    respond($e->status, ['message' => $e->getMessage()]);
} catch (InvalidArgumentException $e) {
    respond(422, ['message' => $e->getMessage()]);
} catch (PDOException $e) {
    error_log('[products-api] DB error: ' . $e->getMessage());
    $body = ['message' => 'Terjadi kesalahan pada database'];
    if (getenv('APP_DEBUG')) {
        $body['error'] = $e->getMessage();
    }
    respond(500, $body);
} catch (Throwable $e) {
    error_log('[products-api] ' . $e);
    respond(500, ['message' => 'Terjadi kesalahan pada server']);
}