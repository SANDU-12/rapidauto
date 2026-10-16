<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/jwt.php';
$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $adminView = ($_GET['admin'] ?? '') === '1';
    if ($adminView) requireAdmin();
    $category = $_GET['category'] ?? null;
    $statusFilter = $adminView ? '' : " AND status='active'";
    if ($category && $category !== 'All') {
        $stmt = $pdo->prepare("SELECT * FROM products WHERE category = ?$statusFilter ORDER BY name");
        $stmt->execute([$category]);
    } else {
        $stmt = $pdo->query("SELECT * FROM products WHERE 1=1$statusFilter ORDER BY category, name");
    }
    respond($stmt->fetchAll());
}

if ($method === 'POST' && ($_GET['action'] ?? '') === 'upload') {
    requireAdmin();
    if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        respond(['error' => 'Please select a product image'], 400);
    }
    if ($_FILES['image']['size'] > 5 * 1024 * 1024) respond(['error' => 'Image must be 5 MB or smaller'], 400);

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/avif' => 'avif', 'image/gif' => 'gif'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['image']['tmp_name']);
    if (!isset($allowed[$mime])) respond(['error' => 'Use a JPG, PNG, WEBP, AVIF, or GIF image'], 400);

    $directory = __DIR__ . '/../images/products';
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) respond(['error' => 'Could not create the product image directory'], 500);
    $filename = bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($_FILES['image']['tmp_name'], $directory . '/' . $filename)) {
        respond(['error' => 'Could not save the product image'], 500);
    }
    respond(['image' => 'images/products/' . $filename], 201);
}

if ($method === 'POST') {
    requireAdmin();
    $d = jsonInput();
    if (empty(trim($d['name'] ?? '')) || !isset($d['price']) || !is_numeric($d['price']) || $d['price'] < 0) {
        respond(['error' => 'Product name and a valid price are required'], 400);
    }
    $stmt = $pdo->prepare("INSERT INTO products (name,category,price,image,stock) VALUES (?,?,?,?,?)");
    $stmt->execute([$d['name'], $d['category'] ?? null, $d['price'], $d['image'] ?? null, $d['stock'] ?? 50]);
    respond(['id' => $pdo->lastInsertId()], 201);
}

if ($method === 'PUT') {
    requireAdmin();
    $id = $_GET['id'] ?? null;
    if (!$id) respond(['error' => 'Missing product id'], 400);
    $d = jsonInput();
    if (empty(trim($d['name'] ?? '')) || !isset($d['price']) || !is_numeric($d['price']) || $d['price'] < 0) {
        respond(['error' => 'Product name and a valid price are required'], 400);
    }
    $pdo->prepare("UPDATE products SET name=?, category=?, price=?, image=?, stock=?, status='active' WHERE id=?")
        ->execute([$d['name'], $d['category'] ?? null, $d['price'], $d['image'] ?? null, $d['stock'] ?? 50, $id]);
    respond(['ok' => true]);
}

if ($method === 'DELETE') {
    requireAdmin();
    $id = $_GET['id'] ?? null;
    if (!$id) respond(['error' => 'Missing product id'], 400);
    $pdo->prepare("UPDATE products SET status='inactive' WHERE id=?")->execute([$id]);
    respond(['ok' => true]);
}

respond(['error' => 'Method not allowed'], 405);
