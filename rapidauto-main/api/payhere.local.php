<?php
// ============================================================
// PayHere credentials (server-side only — never expose in browser JS).
// Keep this file out of source control / public repos.
// ============================================================
return [
    'merchant_id'     => '1238494',
    'merchant_secret' => 'MzM1NTkxMDY4NjY4NTAxNjk4NjE1MzkwNDYzNDUzODA5ODg5MTc4',
    'currency'        => 'LKR',
    'sandbox'         => true,   // set false only when using LIVE merchant credentials
    // Leave blank to auto-detect from the request (e.g. http://localhost/rapidauto).
    'app_url'         => '',
    // PayHere cannot reach localhost. For real status updates, expose the site with a tunnel
    // (e.g. ngrok) and put the public URL here, e.g. https://abcd1234.ngrok-free.app/rapidauto/api/payhere-notify.php
    'notify_url'      => '',
];
