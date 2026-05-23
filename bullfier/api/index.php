<?php

declare(strict_types=1);

const ADMIN_USERNAME = 'admin';
const ADMIN_PASSWORD = 'protein123';
const ALLOWED_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
const DEFAULT_ABOUT_TITLE = 'About BULLFIRE Nutrition';
const DEFAULT_ABOUT_CONTENT = '<p>Your trusted partner in fitness nutrition. We provide premium quality supplements to help you achieve your fitness goals and build the body you&apos;ve always wanted.</p>';

$uploadDir = __DIR__ . '/uploads';
$sliderDir = $uploadDir . '/slider';

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0775, true);
}
if (!is_dir($sliderDir)) {
    mkdir($sliderDir, 0775, true);
}

function sendJson(mixed $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = "sql105.infinityfree.com";
    $database = "if0_41963061_test";
    $user = "if0_41963061";
    $password = "2EtGpRPyAs6mF";

    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    initializeDatabase($pdo);
    return $pdo;
}

function initializeDatabase(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        description TEXT NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 0,
        category VARCHAR(100) NULL,
        image_path VARCHAR(500) NULL,
        featured TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS about (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NULL,
        content LONGTEXT NULL,
        last_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS contact (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL DEFAULT '',
        phone VARCHAR(50) NOT NULL DEFAULT '',
        address TEXT NOT NULL,
        whatsapp_link VARCHAR(255) NULL,
        last_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS slider_images (
        id INT AUTO_INCREMENT PRIMARY KEY,
        image_url VARCHAR(255) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function requestJson(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function isAllowedFile(string $filename): bool
{
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($extension, ALLOWED_EXTENSIONS, true);
}

function safeFilename(string $filename): string
{
    $base = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($filename));
    return bin2hex(random_bytes(8)) . '_' . $base;
}

function boolFromValue(mixed $value): bool
{
    return in_array(strtolower((string) $value), ['true', '1', 't', 'yes', 'on'], true);
}

function productToJson(array $product): array
{
    return [
        'id' => (int) $product['id'],
        'name' => $product['name'],
        'description' => $product['description'] ?? '',
        'price' => (float) $product['price'],
        'category' => $product['category'] ?? '',
        'image_url' => !empty($product['image_path']) ? '/api/uploads/' . basename($product['image_path']) : null,
        'featured' => (bool) $product['featured'],
        'created_at' => date(DATE_ATOM, strtotime($product['created_at'])),
    ];
}

function aboutToJson(?array $about): ?array
{
    if (!$about) {
        return [
            'id' => null,
            'title' => DEFAULT_ABOUT_TITLE,
            'content' => DEFAULT_ABOUT_CONTENT,
            'lastUpdated' => date(DATE_ATOM),
        ];
    }

    return [
        'id' => (int) $about['id'],
        'title' => $about['title'] ?? '',
        'content' => $about['content'] ?? '',
        'lastUpdated' => date(DATE_ATOM, strtotime($about['last_updated'])),
    ];
}

function contactToJson(?array $contact): ?array
{
    if (!$contact) {
        return null;
    }

    return [
        'id' => (int) $contact['id'],
        'email' => $contact['email'] ?? '',
        'phone' => $contact['phone'] ?? '',
        'address' => $contact['address'] ?? '',
        'whatsappLink' => $contact['whatsapp_link'] ?? '',
        'lastUpdated' => date(DATE_ATOM, strtotime($contact['last_updated'])),
    ];
}

function parseMultipartPut(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (!preg_match('/boundary=(.*)$/', $contentType, $matches)) {
        return ['fields' => [], 'files' => []];
    }

    $boundary = '--' . trim($matches[1], '"');
    $raw = file_get_contents('php://input') ?: '';
    $parts = array_slice(explode($boundary, $raw), 1, -1);
    $fields = [];
    $files = [];

    foreach ($parts as $part) {
        $part = ltrim($part, "\r\n");
        [$headersRaw, $body] = array_pad(explode("\r\n\r\n", $part, 2), 2, '');
        $body = preg_replace("/\r\n$/", '', $body);

        if (!preg_match('/name="([^"]+)"/', $headersRaw, $nameMatch)) {
            continue;
        }

        $name = $nameMatch[1];
        if (preg_match('/filename="([^"]*)"/', $headersRaw, $fileMatch)) {
            $tmp = tempnam(sys_get_temp_dir(), 'upload_');
            file_put_contents($tmp, $body);
            $files[$name] = [
                'name' => $fileMatch[1],
                'tmp_name' => $tmp,
                'error' => UPLOAD_ERR_OK,
                'size' => strlen($body),
            ];
            continue;
        }

        $fields[$name] = $body;
    }

    return ['fields' => $fields, 'files' => $files];
}

function formFieldsAndFiles(): array
{
    if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        return parseMultipartPut();
    }

    return ['fields' => $_POST, 'files' => $_FILES];
}

function saveUploadedFile(array $file, string $targetDir): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['name']) || !isAllowedFile($file['name'])) {
        return null;
    }

    $filename = safeFilename($file['name']);
    $targetPath = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

    if (is_uploaded_file($file['tmp_name'])) {
        move_uploaded_file($file['tmp_name'], $targetPath);
    } else {
        rename($file['tmp_name'], $targetPath);
    }

    return $targetPath;
}

function serveFile(string $path): void
{
    if (!is_file($path)) {
        sendJson(['error' => 'File not found'], 404);
    }

    header('Content-Type: ' . (mime_content_type($path) ?: 'application/octet-stream'));
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    sendJson(['status' => 'ok']);
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'];

if (preg_match('#^/(uploads|slider-images|products|about|contact|admin|health)(/.*)?$#', $path)) {
    $path = '/api' . $path;
}

try {
    if ($method === 'GET' && preg_match('#^/api/uploads/slider/([^/]+)$#', $path, $matches)) {
        serveFile($sliderDir . '/' . basename($matches[1]));
    }

    if ($method === 'GET' && preg_match('#^/api/uploads/([^/]+)$#', $path, $matches)) {
        serveFile($uploadDir . '/' . basename($matches[1]));
    }

    if ($path === '/api/health' && $method === 'GET') {
        sendJson(['status' => 'ok']);
    }

    if ($path === '/api/admin/login' && $method === 'POST') {
        $data = requestJson();
        if (($data['username'] ?? '') === ADMIN_USERNAME && ($data['password'] ?? '') === ADMIN_PASSWORD) {
            sendJson([
                'token' => bin2hex(random_bytes(32)),
                'expires_at' => (new DateTimeImmutable('+8 hours'))->format(DATE_ATOM),
            ]);
        }
        sendJson(['error' => 'Invalid credentials'], 401);
    }

    if ($path === '/api/slider-images' && $method === 'GET') {
        $rows = pdo()->query('SELECT id, image_url FROM slider_images ORDER BY created_at DESC')->fetchAll();
        sendJson(array_map(fn ($row) => ['id' => (int) $row['id'], 'url' => $row['image_url']], $rows));
    }

    if ($path === '/api/slider-images' && $method === 'POST') {
        $file = $_FILES['image'] ?? null;
        if (!$file) {
            sendJson(['error' => 'No image provided'], 400);
        }

        $saved = saveUploadedFile($file, $sliderDir);
        if (!$saved) {
            sendJson(['error' => 'Invalid file type'], 400);
        }

        $url = '/api/uploads/slider/' . basename($saved);
        pdo()->prepare('INSERT INTO slider_images (image_url) VALUES (?)')->execute([$url]);
        sendJson(['id' => (int) pdo()->lastInsertId(), 'url' => $url], 201);
    }

    if ($method === 'DELETE' && preg_match('#^/api/slider-images/(\d+)$#', $path, $matches)) {
        $stmt = pdo()->prepare('SELECT * FROM slider_images WHERE id = ?');
        $stmt->execute([(int) $matches[1]]);
        $image = $stmt->fetch();
        if (!$image) {
            sendJson(['error' => 'Image not found'], 404);
        }

        $file = $sliderDir . '/' . basename($image['image_url']);
        if (is_file($file)) {
            unlink($file);
        }
        pdo()->prepare('DELETE FROM slider_images WHERE id = ?')->execute([(int) $matches[1]]);
        sendJson(['message' => 'Image deleted successfully']);
    }

    if ($path === '/api/products' && $method === 'GET') {
        $where = [];
        $params = [];
        if (!empty($_GET['category'])) {
            $where[] = 'category = ?';
            $params[] = $_GET['category'];
        }
        if (!empty($_GET['q'])) {
            $where[] = '(name LIKE ? OR description LIKE ?)';
            $params[] = '%' . $_GET['q'] . '%';
            $params[] = '%' . $_GET['q'] . '%';
        }

        $sql = 'SELECT * FROM products' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY created_at DESC';
        $stmt = pdo()->prepare($sql);
        $stmt->execute($params);
        sendJson(array_map('productToJson', $stmt->fetchAll()));
    }

    if ($path === '/api/products' && $method === 'POST') {
        ['fields' => $fields, 'files' => $files] = formFieldsAndFiles();
        $name = trim((string) ($fields['name'] ?? ''));
        if ($name === '') {
            sendJson(['error' => 'Product name required'], 400);
        }

        $saved = isset($files['image']) ? saveUploadedFile($files['image'], $uploadDir) : null;
        $stmt = pdo()->prepare('INSERT INTO products (name, description, price, category, image_path, featured) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $name,
            $fields['description'] ?? '',
            (float) ($fields['price'] ?? 0),
            $fields['category'] ?? '',
            $saved,
            boolFromValue($fields['featured'] ?? 'false') ? 1 : 0,
        ]);

        $stmt = pdo()->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([(int) pdo()->lastInsertId()]);
        sendJson(productToJson($stmt->fetch()), 201);
    }

    if ($method === 'GET' && preg_match('#^/api/products/(\d+)$#', $path, $matches)) {
        $stmt = pdo()->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([(int) $matches[1]]);
        $product = $stmt->fetch();
        if (!$product) {
            sendJson(['error' => 'Product not found'], 404);
        }
        sendJson(productToJson($product));
    }

    if ($method === 'PUT' && preg_match('#^/api/products/(\d+)$#', $path, $matches)) {
        $id = (int) $matches[1];
        $stmt = pdo()->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $product = $stmt->fetch();
        if (!$product) {
            sendJson(['error' => 'Product not found'], 404);
        }

        ['fields' => $fields, 'files' => $files] = formFieldsAndFiles();
        $saved = isset($files['image']) ? saveUploadedFile($files['image'], $uploadDir) : null;
        $stmt = pdo()->prepare('UPDATE products SET name = ?, description = ?, price = ?, category = ?, image_path = ?, featured = ? WHERE id = ?');
        $stmt->execute([
            $fields['name'] ?? $product['name'],
            $fields['description'] ?? $product['description'],
            (float) ($fields['price'] ?? $product['price']),
            $fields['category'] ?? $product['category'],
            $saved ?: $product['image_path'],
            array_key_exists('featured', $fields) ? (boolFromValue($fields['featured']) ? 1 : 0) : (int) $product['featured'],
            $id,
        ]);

        $stmt = pdo()->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$id]);
        sendJson(productToJson($stmt->fetch()));
    }

    if ($method === 'DELETE' && preg_match('#^/api/products/(\d+)$#', $path, $matches)) {
        $stmt = pdo()->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([(int) $matches[1]]);
        $product = $stmt->fetch();
        if (!$product) {
            sendJson(['error' => 'Product not found'], 404);
        }
        if (!empty($product['image_path']) && is_file($product['image_path'])) {
            unlink($product['image_path']);
        }
        pdo()->prepare('DELETE FROM products WHERE id = ?')->execute([(int) $matches[1]]);
        sendJson(['success' => true]);
    }

    if ($path === '/api/about' && $method === 'GET') {
        $about = pdo()->query('SELECT * FROM about ORDER BY id ASC LIMIT 1')->fetch();
        sendJson(['about' => aboutToJson($about ?: null)]);
    }

    if ($path === '/api/about' && $method === 'PUT') {
        $data = requestJson();
        $about = pdo()->query('SELECT * FROM about ORDER BY id ASC LIMIT 1')->fetch();
        $title = $data['title'] ?? ($about['title'] ?? '');
        $content = $data['content'] ?? ($about['content'] ?? '');

        if ($about) {
            pdo()->prepare('UPDATE about SET title = ?, content = ?, last_updated = CURRENT_TIMESTAMP WHERE id = ?')->execute([$title, $content, $about['id']]);
            $id = (int) $about['id'];
        } else {
            pdo()->prepare('INSERT INTO about (title, content) VALUES (?, ?)')->execute([$title, $content]);
            $id = (int) pdo()->lastInsertId();
        }

        $stmt = pdo()->prepare('SELECT * FROM about WHERE id = ?');
        $stmt->execute([$id]);
        sendJson(['success' => true, 'about' => aboutToJson($stmt->fetch())]);
    }

    if ($path === '/api/contact' && $method === 'GET') {
        $contact = pdo()->query('SELECT * FROM contact ORDER BY id ASC LIMIT 1')->fetch();
        sendJson(['contact' => contactToJson($contact ?: null)]);
    }

    if ($path === '/api/contact' && $method === 'PUT') {
        $data = requestJson();
        $contact = pdo()->query('SELECT * FROM contact ORDER BY id ASC LIMIT 1')->fetch();
        $email = $data['email'] ?? ($contact['email'] ?? '');
        $phone = $data['phone'] ?? ($contact['phone'] ?? '');
        $address = $data['address'] ?? ($contact['address'] ?? '');
        $whatsapp = $data['whatsappLink'] ?? ($contact['whatsapp_link'] ?? '');

        if ($contact) {
            pdo()->prepare('UPDATE contact SET email = ?, phone = ?, address = ?, whatsapp_link = ?, last_updated = CURRENT_TIMESTAMP WHERE id = ?')
                ->execute([$email, $phone, $address, $whatsapp, $contact['id']]);
            $id = (int) $contact['id'];
        } else {
            pdo()->prepare('INSERT INTO contact (email, phone, address, whatsapp_link) VALUES (?, ?, ?, ?)')->execute([$email, $phone, $address, $whatsapp]);
            $id = (int) pdo()->lastInsertId();
        }

        $stmt = pdo()->prepare('SELECT * FROM contact WHERE id = ?');
        $stmt->execute([$id]);
        sendJson(['success' => true, 'contact' => contactToJson($stmt->fetch())]);
    }

    sendJson(['error' => 'Not found'], 404);
} catch (Throwable $error) {
    error_log($error->getMessage());
    sendJson(['error' => 'Server error', 'detail' => $error->getMessage()], 500);
}
