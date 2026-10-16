<?php
// ============================================================
// Database connection — default WampServer settings.
// WampServer's MySQL default: host=localhost, user=root, password=(empty)
// If you changed these in WAMP, update them below.
// ============================================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'rapidauto');
define('DB_USER', 'root');
define('DB_PASS', '');       // WAMP's default root password is blank
define('JWT_SECRET', 'change_this_to_a_long_random_string');

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER, DB_PASS,
                [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}

// CORS + JSON headers for every API request
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

function jsonInput() {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}
function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}
