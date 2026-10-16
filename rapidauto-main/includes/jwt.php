<?php
// Minimal HS256 JWT implementation — no Composer/library needed, works out of the box on WAMP.
require_once __DIR__ . '/../config/db.php';

function base64url_encode($data) { return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); }
function base64url_decode($data) { return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4)); }

function jwt_encode($payload) {
    $header = base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    $payload['exp'] = time() + (2 * 60 * 1000); // 2 minutes
    $body = base64url_encode(json_encode($payload));
    $signature = base64url_encode(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));
    return "$header.$body.$signature";
}

function jwt_decode($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$header, $body, $signature] = $parts;
    $expected = base64url_encode(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));
    if (!hash_equals($expected, $signature)) return null;
    $payload = json_decode(base64url_decode($body), true);
    if (!$payload || (isset($payload['exp']) && $payload['exp'] < time())) return null;
    return $payload;
}

function getBearerToken() {
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    if (preg_match('/Bearer\s+(.*)$/i', $auth, $m)) return $m[1];
    return null;
}

function requireAuth() {
    $token = getBearerToken();
    if (!$token) respond(['error' => 'No token provided'], 401);
    $payload = jwt_decode($token);
    if (!$payload) respond(['error' => 'Invalid or expired token'], 401);
    return $payload;
}

function requireAdmin() {
    $user = requireAuth();
    $adminRoles = ['super_admin','manager','cashier','receptionist','mechanic','car_wash_staff','inventory_manager'];
    if (!in_array($user['role'], $adminRoles)) respond(['error' => 'Admin access required'], 403);
    return $user;
}

function requireStaff() {
    $user = requireAuth();
    $staffRoles = ['super_admin','manager','cashier','receptionist','mechanic','car_wash_staff','inventory_manager'];
    if (!in_array($user['role'], $staffRoles, true)) respond(['error' => 'Staff access required'], 403);
    return $user;
}

function requireVehicleAdmin() {
    $user = requireAuth();
    if (!in_array($user['role'], ['super_admin','manager'], true)) respond(['error' => 'Vehicle administrator access required'], 403);
    return $user;
}
