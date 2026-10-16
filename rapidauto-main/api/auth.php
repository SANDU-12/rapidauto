<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/jwt.php';
require_once __DIR__ . '/../config/google.php';
$pdo = getDB();
$action = $_GET['action'] ?? '';
$input = jsonInput();

/* ---------------- REGISTER (customer sign-up) ---------------- */
if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($input['email'] ?? ''));
    $password = $input['password'] ?? '';
    $confirm = $input['confirmPassword'] ?? '';
    if (!$email || !$password) respond(['error' => 'Email and password are required'], 400);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['error' => 'Please enter a valid email address'], 400);
    if (strlen($password) < 6) respond(['error' => 'Password must be at least 6 characters'], 400);
    if ($confirm !== '' && $password !== $confirm) respond(['error' => 'Passwords do not match'], 400);

    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) respond(['error' => 'An account with this email already exists'], 409);

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $pdo->beginTransaction();
    try {
        // Create the login identity and customer profile atomically so they cannot diverge.
        $pdo->prepare("INSERT INTO users (first_name,last_name,email,phone,password,role) VALUES (?,?,?,?,?,'customer')")
            ->execute([$input['firstName'] ?? '', $input['lastName'] ?? '', $email, $input['phone'] ?? '', $hash]);
        $userId = $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO customers (user_id,first_name,last_name,email,phone) VALUES (?,?,?,?,?)")
            ->execute([$userId, $input['firstName'] ?? '', $input['lastName'] ?? '', $email, $input['phone'] ?? '']);
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        respond(['error' => 'Could not create customer account'], 500);
    }

    $token = jwt_encode(['id' => $userId, 'email' => $email, 'role' => 'customer']);
    respond(['token' => $token, 'user' => [
        'id' => $userId, 'firstName' => $input['firstName'] ?? '', 'email' => $email, 'role' => 'customer'
    ]], 201);
}

/* ---------------- LOGIN ---------------- */
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($input['email'] ?? ''));
    $password = $input['password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !$user['password'] || !password_verify($password, $user['password'])) {
        respond(['error' => 'Invalid email or password'], 401);
    }
    if ($user['status'] === 'suspended') respond(['error' => 'This account has been suspended'], 403);

    $token = jwt_encode(['id' => $user['id'], 'email' => $user['email'], 'role' => $user['role']]);
    respond(['token' => $token, 'user' => [
        'id' => $user['id'], 'firstName' => $user['first_name'], 'lastName' => $user['last_name'],
        'email' => $user['email'], 'role' => $user['role']
    ]]);
}

/* ---------------- FORGOT PASSWORD ---------------- */
// Generates a reset token valid for 1 hour. No SMTP server is configured in this project,
// so instead of emailing the link, we return it directly in the response — swap this for a
// real mail send (PHPMailer / Nodemailer-equivalent) once you have an SMTP account.
if ($action === 'forgot' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($input['email'] ?? ''));
    if (!$email) respond(['error' => 'Email is required'], 400);

    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    // Always respond success-shaped, even if not found, so this can't be used to enumerate accounts.
    if (!$user) respond(['ok' => true, 'message' => 'If that email has an account, a reset link has been generated.']);

    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + 3600);
    $pdo->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?,?,?)")
        ->execute([$user['id'], $token, $expires]);

    $resetLink = 'reset-password.html?token=' . $token;
    respond(['ok' => true, 'message' => 'Reset link generated (demo mode — no email server configured).', 'resetLink' => $resetLink]);
}

/* ---------------- RESET PASSWORD ---------------- */
if ($action === 'reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $input['token'] ?? '';
    $newPassword = $input['newPassword'] ?? '';
    if (!$token || !$newPassword) respond(['error' => 'Token and new password are required'], 400);
    if (strlen($newPassword) < 6) respond(['error' => 'Password must be at least 6 characters'], 400);

    $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token = ?");
    $stmt->execute([$token]);
    $reset = $stmt->fetch();
    if (!$reset) respond(['error' => 'This reset link is invalid'], 400);
    if ($reset['used']) respond(['error' => 'This reset link has already been used'], 400);
    if (strtotime($reset['expires_at']) < time()) respond(['error' => 'This reset link has expired. Please request a new one.'], 400);

    $hash = password_hash($newPassword, PASSWORD_BCRYPT);
    $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([$hash, $reset['user_id']]);
    $pdo->prepare("UPDATE password_resets SET used=1 WHERE id=?")->execute([$reset['id']]);
    respond(['ok' => true, 'message' => 'Password reset successfully. You can now log in.']);
}

/* ---------------- GOOGLE SIGN-IN ---------------- */
// Verifies the ID token Google's Identity Services library returns in the browser, then
// finds or creates the matching user. Requires the site owner's own Google OAuth Client ID
// (set in app.js as GOOGLE_CLIENT_ID) — see README for setup steps.
if ($action === 'google' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $idToken = $input['credential'] ?? '';
    if (!$idToken) respond(['error' => 'Missing Google credential'], 400);

    $verifyUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
    $context = stream_context_create(['http' => ['timeout' => 8]]);
    $result = @file_get_contents($verifyUrl, false, $context);
    if ($result === false) respond(['error' => 'Could not verify Google sign-in — check your server can reach Google'], 502);

    $payload = json_decode($result, true);
    if (empty($payload['email']) || empty($payload['sub'])) respond(['error' => 'Invalid Google credential'], 400);
    $googleClientId = trim(GOOGLE_CLIENT_ID);
    if ($googleClientId === '') respond(['error' => 'Google sign-in is not configured. Add the client ID to config/google.php'], 503);
    if (!empty($payload['aud']) && $payload['aud'] !== $googleClientId) {
        respond(['error' => 'Google credential was issued for a different application'], 401);
    }

    $email = strtolower($payload['email']);
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? OR google_id = ?");
    $stmt->execute([$email, $payload['sub']]);
    $user = $stmt->fetch();

    if (!$user) {
        $pdo->prepare("INSERT INTO users (first_name,last_name,email,role,google_id) VALUES (?,?,?,?,?)")
            ->execute([$payload['given_name'] ?? '', $payload['family_name'] ?? '', $email, 'customer', $payload['sub']]);
        $userId = $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO customers (user_id,first_name,last_name,email,phone) VALUES (?,?,?,?,?)")
            ->execute([$userId, $payload['given_name'] ?? '', $payload['family_name'] ?? '', $email, '']);
        $role = 'customer'; $firstName = $payload['given_name'] ?? '';
    } else {
        if (!$user['google_id']) $pdo->prepare("UPDATE users SET google_id=? WHERE id=?")->execute([$payload['sub'], $user['id']]);
        if ($user['role'] === 'customer') {
            $customerStmt = $pdo->prepare("SELECT id FROM customers WHERE user_id=?");
            $customerStmt->execute([$user['id']]);
            if (!$customerStmt->fetch()) {
                $pdo->prepare("INSERT INTO customers (user_id,first_name,last_name,email,phone,profile_image) VALUES (?,?,?,?,?,?)")
                    ->execute([$user['id'], $user['first_name'], $user['last_name'], $user['email'], $user['phone'], $user['profile_image']]);
            }
        }
        $userId = $user['id']; $role = $user['role']; $firstName = $user['first_name'];
    }

    $token = jwt_encode(['id' => $userId, 'email' => $email, 'role' => $role]);
    respond(['token' => $token, 'user' => ['id' => $userId, 'firstName' => $firstName, 'email' => $email, 'role' => $role]]);
}

respond(['error' => 'Unknown action'], 404);
