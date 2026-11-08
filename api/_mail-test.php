<?php
// Command-line only: sends a test email the same way order emails go out.
// Run from cPanel Terminal:  php ~/public_html/api/_mail-test.php
// Or send to another address: php ~/public_html/api/_mail-test.php someone@example.com
// (Blocked from the web by api/.htaccess because the name starts with "_".)
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
require __DIR__ . '/_lib.php';
$cfg = mf_config();
if (empty($cfg['smtp_pass'])) {
    echo "smtp_pass is not set in config.php yet.\n";
    exit(1);
}
$to = $argv[1] ?? $cfg['notify_email'];
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    echo "That doesn't look like an email address: {$to}\n";
    exit(1);
}
$ok = mf_smtp_send($cfg, $to, 'Magnet Fairy shop email test',
    "If you can read this, order emails will reach you.\n\nSent " . date('r') . "\n");
echo $ok ? "SENT to {$to} via Google. Check the inbox.\n"
         : "FAILED. See the latest lines of ~/magnet-fairy-private/logs/shop-" . date('Y-m') . ".log\n";
