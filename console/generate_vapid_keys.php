<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (!extension_loaded('openssl')) {
    fwrite(STDERR, "PHP OpenSSL extension is required.\n");
    exit(1);
}

$key = openssl_pkey_new(array(
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name' => 'prime256v1',
));
$details = $key ? openssl_pkey_get_details($key) : false;

if (!$details || empty($details['ec']['x']) || empty($details['ec']['y']) || empty($details['ec']['d'])) {
    fwrite(STDERR, "Could not generate a P-256 VAPID key pair.\n");
    exit(1);
}

$encode = function ($value) {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
};

$public = "\x04" . $details['ec']['x'] . $details['ec']['y'];
$private = $details['ec']['d'];
$token = $encode(random_bytes(32));

echo "Add these lines to the backend .env file. Keep the private key and worker token secret.\n\n";
echo 'PUSH_ENABLED=true' . PHP_EOL;
echo 'PUSH_DRIVER=vapid' . PHP_EOL;
echo 'VAPID_PUBLIC_KEY=' . $encode($public) . PHP_EOL;
echo 'VAPID_PRIVATE_KEY=' . $encode($private) . PHP_EOL;
echo 'VAPID_SUBJECT=mailto:you@example.com' . PHP_EOL;
echo 'NOTIFICATION_WORKER_TOKEN=' . $token . PHP_EOL;