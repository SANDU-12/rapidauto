<?php
// Settings come from environment variables first, then config/payhere.local.php.
$payHereLocal = is_file(__DIR__ . '/payhere.local.php') ? (require __DIR__ . '/payhere.local.php') : [];
function payhereSetting(string $env, string $key, $default = '') {
    global $payHereLocal;
    $v = getenv($env);
    if ($v !== false && trim($v) !== '') return trim($v);
    $v = $payHereLocal[$key] ?? '';
    return ($v === '' || $v === null) ? $default : $v;
}

// Auto-detect the site URL, e.g. http://localhost/rapidauto
function payhereDetectAppUrl(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/api/x.php'))), '/');
    return ($https ? 'https://' : 'http://') . $host . $base;
}

$payHereSandbox = payhereSetting('PAYHERE_SANDBOX', 'sandbox', true);
$payHereAppUrl = rtrim((string)payhereSetting('PAYHERE_APP_URL', 'app_url', payhereDetectAppUrl()), '/');

define('PAYHERE_MERCHANT_ID', (string)payhereSetting('PAYHERE_MERCHANT_ID', 'merchant_id', '1238494'));
define('PAYHERE_MERCHANT_SECRET', (string)payhereSetting('PAYHERE_MERCHANT_SECRET', 'merchant_secret', ''));
define('PAYHERE_CURRENCY', strtoupper((string)payhereSetting('PAYHERE_CURRENCY', 'currency', 'LKR')));
define('PAYHERE_APP_URL', $payHereAppUrl);
define('PAYHERE_NOTIFY_URL', (string)payhereSetting('PAYHERE_NOTIFY_URL', 'notify_url', $payHereAppUrl . '/api/payhere-notify.php'));
define('PAYHERE_SANDBOX', is_bool($payHereSandbox) ? $payHereSandbox : filter_var($payHereSandbox, FILTER_VALIDATE_BOOLEAN));

function payhereCheckoutData(string $orderId, float $amount, array $customer, string $items, string $returnUrl, string $cancelUrl): array {
    if (!PAYHERE_MERCHANT_ID || !PAYHERE_MERCHANT_SECRET) {
        throw new RuntimeException('PayHere is not configured. Set PAYHERE_MERCHANT_ID and PAYHERE_MERCHANT_SECRET on the server.');
    }
    if ($amount <= 0 || !in_array(PAYHERE_CURRENCY, ['LKR','USD','GBP','EUR','AUD'], true)) {
        throw new RuntimeException('PayHere currency or amount is invalid.');
    }

    $amountFormatted = number_format($amount, 2, '.', '');
    $hashedSecret = strtoupper(md5(PAYHERE_MERCHANT_SECRET));
    $hash = strtoupper(md5(PAYHERE_MERCHANT_ID . $orderId . $amountFormatted . PAYHERE_CURRENCY . $hashedSecret));
    $nameParts = preg_split('/\s+/', trim((string)($customer['name'] ?? '')), 2);
    $firstName = trim($nameParts[0] ?? '');
    $lastName = trim($nameParts[1] ?? '') ?: $firstName;

    return [
        'sandbox' => PAYHERE_SANDBOX,
        'payment' => [
            'merchant_id' => PAYHERE_MERCHANT_ID,
            'return_url' => $returnUrl,
            'cancel_url' => $cancelUrl,
            'notify_url' => PAYHERE_NOTIFY_URL,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => trim((string)($customer['email'] ?? '')),
            'phone' => trim((string)($customer['phone'] ?? '')),
            'address' => trim((string)($customer['address'] ?? '')),
            'city' => trim((string)($customer['city'] ?? '')),
            'country' => trim((string)($customer['country'] ?? 'Sri Lanka')),
            'order_id' => $orderId,
            'items' => $items,
            'currency' => PAYHERE_CURRENCY,
            'amount' => $amountFormatted,
            'hash' => $hash,
        ],
    ];
}

function payhereVerifyNotification(array $data): bool {
    if (!PAYHERE_MERCHANT_ID || !PAYHERE_MERCHANT_SECRET) return false;
    $merchantId = (string)($data['merchant_id'] ?? '');
    $orderId = (string)($data['order_id'] ?? '');
    $amount = (string)($data['payhere_amount'] ?? '');
    $currency = (string)($data['payhere_currency'] ?? '');
    $statusCode = (string)($data['status_code'] ?? '');
    $signature = strtoupper((string)($data['md5sig'] ?? ''));
    if ($merchantId !== PAYHERE_MERCHANT_ID || !$orderId || !$amount || !$currency || $statusCode === '' || !$signature) return false;

    $hashedSecret = strtoupper(md5(PAYHERE_MERCHANT_SECRET));
    $expected = strtoupper(md5($merchantId . $orderId . $amount . $currency . $statusCode . $hashedSecret));
    return hash_equals($expected, $signature);
}