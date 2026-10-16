<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/jwt.php';

$user = requireAuth();
if (($user['role'] ?? '') !== 'customer') respond(['error' => 'Customer access required'], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') respond(['error' => 'Method not allowed'], 405);

$pdo = getDB();
$stmt = $pdo->prepare('SELECT id, reward_points FROM customers WHERE user_id=? LIMIT 1');
$stmt->execute([$user['id']]);
$customer = $stmt->fetch();
if (!$customer) respond(['error' => 'Customer profile not found'], 404);

$historyStmt = $pdo->prepare('SELECT transaction_type, points, description, created_at
                              FROM loyalty_transactions WHERE customer_id=?
                              ORDER BY created_at DESC, id DESC LIMIT 8');
$historyStmt->execute([$customer['id']]);
respond([
    'balance' => (int)$customer['reward_points'],
    'pointsPer100Lkr' => 1,
    'discountPoints' => 100,
    'freeWashPoints' => 500,
    'transactions' => $historyStmt->fetchAll(),
]);