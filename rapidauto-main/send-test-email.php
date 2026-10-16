<?php
// Command-line only: php send-test-email.php you@example.com
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/includes/email.php';
$to = $argv[1] ?? '';
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { fwrite(STDERR, "Usage: php send-test-email.php you@example.com\n"); exit(1); }
$ok = sendRapidAutoEmail($to, 'Rapid Auto test email', '<p>Your Rapid Auto email settings work.</p>', 'Your Rapid Auto email settings work.');
echo $ok ? "Sent to $to\n" : "FAILED - see the PHP error log for the reason\n";
exit($ok ? 0 : 1);
