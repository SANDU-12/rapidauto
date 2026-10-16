<?php
require_once __DIR__ . '/../config/db.php';
$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $rows = $pdo->query("SELECT r.*, COALESCE(u.first_name, r.guest_name) AS reviewer_name
                          FROM reviews r LEFT JOIN customers c ON c.id=r.customer_id
                          LEFT JOIN users u ON u.id=c.user_id
                          ORDER BY r.created_at DESC LIMIT 20")->fetchAll();
    respond($rows);
}

if ($method === 'POST') {
    $d = jsonInput();
    $name = trim((string)($d['name'] ?? 'Anonymous'));
    $comment = trim((string)($d['comment'] ?? ''));
    $rating = filter_var($d['rating'] ?? null, FILTER_VALIDATE_INT);
    if ($name === '') $name = 'Anonymous';
    if ($rating === false || $rating < 1 || $rating > 5) {
        respond(['error' => 'Rating must be a whole number from 1 to 5'], 400);
    }
    if ($comment === '') respond(['error' => 'Review comment is required'], 400);
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO reviews (guest_name, rating, comment) VALUES (?,?,?)");
        $stmt->execute([$name, $rating, $comment]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
        if ($id < 1) respond(['error' => 'Review was not inserted'], 500);
        respond(['id' => $id], 201);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        respond(['error' => 'Could not insert review into the reviews table: ' . $e->getMessage()], 500);
    }
}

// ---- Admin: edit a review ----
if ($method === 'PUT') {
    require_once __DIR__ . '/../includes/jwt.php';
    requireAdmin();
    $id = $_GET['id'] ?? null;
    if (!$id) respond(['error' => 'Missing review id'], 400);
    $d = jsonInput();
    $pdo->prepare("UPDATE reviews SET guest_name=?, rating=?, comment=? WHERE id=?")
        ->execute([$d['name'] ?? 'Anonymous', $d['rating'] ?? 5, $d['comment'] ?? '', $id]);
    respond(['ok' => true]);
}

// ---- Admin: delete a review ----
if ($method === 'DELETE') {
    require_once __DIR__ . '/../includes/jwt.php';
    requireAdmin();
    $id = $_GET['id'] ?? null;
    if (!$id) respond(['error' => 'Missing review id'], 400);
    $pdo->prepare("DELETE FROM reviews WHERE id=?")->execute([$id]);
    respond(['ok' => true]);
}

respond(['error' => 'Method not allowed'], 405);
