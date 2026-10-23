<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Library: GoogleIdentityService
 *
 * Server-side Google Identity Services (OIDC ID token) verifier
 * (Spec Section 5.6, NFR-01, TC-11, TC-12).
 *
 * Validates:
 *   1. JWS structure (`alg === 'RS256'`, non-empty `kid`)
 *   2. Cryptographic RSA-SHA256 signature against Google's published JWKS
 *      (converted from JWK modulus/exponent to DER SubjectPublicKeyInfo in PHP)
 *   3. `iss` (`https://accounts.google.com` or `accounts.google.com`)
 *   4. `aud` matching configured `GOOGLE_CLIENT_ID` (and `azp` if multi-aud)
 *   5. `exp`, `iat`, `nbf` within configured clock-skew leeway
 *   6. `sub` non-empty, `email` valid, and `email_verified === true`
 *   7. Optional `nonce` binding when supplied
 *
 * Google Sign-In establishes identity ONLY — it never grants Google Calendar
 * permissions (Spec 1, 4, 5.6).
 */
class GoogleIdentityService {

    /** @var HttpClient */
    private $http;

    /** @var array */
    private $cfg;

    /** @var string|null */
    private $cache_file;

    public function __construct(?array $override_config = null, ?HttpClient $http = null, $cache_file = null)
    {
        if (!class_exists('HttpClient')) {
            require_once APP_DIR . 'libraries/HttpClient.php';
        }
        $this->cfg  = $override_config !== null ? $override_config : integration_config('google_identity');
        $timeout    = (int) ($this->cfg['timeout'] ?? 10);
        $this->http = $http ?: new HttpClient($timeout);

        if ($cache_file !== null) {
            $this->cache_file = $cache_file;
        } elseif (defined('ROOT_DIR')) {
            $this->cache_file = ROOT_DIR . 'runtime/google_jwks.json';
        }
    }

    /**
     * Verify a Google ID token (`credential`) and return the verified identity claims.
     *
     * @param string      $id_token
     * @param string|null $expected_nonce
     * @param int|null    $now_override   Unix timestamp (used by deterministic tests)
     * @return array ['ok' => true, 'claims' => [...]] or ['ok' => false, 'status' => int, 'error' => string, 'reason' => string]
     */
    public function verify_id_token($id_token, $expected_nonce = null, $now_override = null)
    {
        $enabled   = filter_var($this->cfg['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $client_id = trim((string) ($this->cfg['client_id'] ?? ''));

        if (!$enabled || $client_id === '') {
            return [
                'ok'     => false,
                'status' => 503,
                'error'  => 'Google Sign-In is not configured on this server.',
                'reason' => 'not_configured',
            ];
        }

        $id_token = trim((string) $id_token);
        if ($id_token === '' || strlen($id_token) > 8192) {
            return $this->reject('Invalid Google sign-in credential.', 'malformed_token');
        }

        $parts = explode('.', $id_token);
        if (count($parts) !== 3) {
            return $this->reject('Invalid Google sign-in credential.', 'malformed_token');
        }

        [$h64, $p64, $s64] = $parts;
        $header  = json_decode((string) self::b64url_decode($h64), true);
        $payload = json_decode((string) self::b64url_decode($p64), true);
        $sig     = self::b64url_decode($s64);

        if (!is_array($header) || !is_array($payload) || $sig === false || $sig === '') {
            return $this->reject('Invalid Google sign-in credential.', 'malformed_token');
        }

        if (($header['alg'] ?? '') !== 'RS256' || empty($header['kid']) || !is_string($header['kid'])) {
            return $this->reject('Unsupported Google token algorithm.', 'bad_header');
        }

        // Validate claims before and alongside cryptographic signature check
        $issuers = $this->cfg['issuers'] ?? ['https://accounts.google.com', 'accounts.google.com'];
        $iss     = (string) ($payload['iss'] ?? '');
        if (!in_array($iss, $issuers, true)) {
            return $this->reject('Invalid Google token issuer.', 'bad_issuer');
        }

        $aud = $payload['aud'] ?? null;
        if (is_string($aud)) {
            $aud_ok = hash_equals($client_id, $aud);
        } elseif (is_array($aud)) {
            $aud_ok = in_array($client_id, $aud, true)
                && isset($payload['azp']) && is_string($payload['azp'])
                && hash_equals($client_id, $payload['azp']);
        } else {
            $aud_ok = false;
        }
        if (!$aud_ok) {
            return $this->reject('Google credential was issued for a different application.', 'bad_audience');
        }

        $now    = $now_override !== null ? (int) $now_override : time();
        $leeway = max(0, (int) ($this->cfg['leeway_seconds'] ?? 120));
        $exp    = isset($payload['exp']) && is_numeric($payload['exp']) ? (int) $payload['exp'] : 0;
        $iat    = isset($payload['iat']) && is_numeric($payload['iat']) ? (int) $payload['iat'] : 0;
        $nbf    = isset($payload['nbf']) && is_numeric($payload['nbf']) ? (int) $payload['nbf'] : null;

        if ($exp <= 0 || ($now - $leeway) >= $exp) {
            return $this->reject('Your Google sign-in session expired. Please try again.', 'expired');
        }
        if ($iat <= 0 || ($iat - $leeway) > $now) {
            return $this->reject('Google token issue time is invalid.', 'bad_iat');
        }
        if ($nbf !== null && ($nbf - $leeway) > $now) {
            return $this->reject('Google token is not valid yet.', 'not_before');
        }

        if ($expected_nonce !== null && $expected_nonce !== '') {
            $token_nonce = (string) ($payload['nonce'] ?? '');
            if ($token_nonce === '' || !hash_equals((string) $expected_nonce, $token_nonce)) {
                return $this->reject('Google sign-in nonce mismatch. Please try again.', 'bad_nonce');
            }
        }

        $sub   = trim((string) ($payload['sub'] ?? ''));
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $ver   = filter_var($payload['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($sub === '' || strlen($sub) > 191) {
            return $this->reject('Google token is missing subject identifier.', 'missing_sub');
        }
        if ($email === '' || strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$ver) {
            return $this->reject('A verified Google email address is required to sign in.', 'unverified_email');
        }

        // Verify signature against Google's JWKS (refreshing cache once on unknown kid)
        $pem = $this->resolve_public_key_pem($header['kid']);
        if ($pem === null) {
            return [
                'ok'     => false,
                'status' => 503,
                'error'  => 'Could not verify Google sign-in keys right now. Please try again.',
                'reason' => 'jwks_unavailable',
            ];
        }
        if ($pem === false) {
            return $this->reject('Unrecognized Google signing key.', 'unknown_kid');
        }

        $verified = openssl_verify($h64 . '.' . $p64, $sig, $pem, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            return $this->reject('Google credential signature verification failed.', 'bad_signature');
        }

        return [
            'ok'     => true,
            'claims' => [
                'sub'            => $sub,
                'email'          => $email,
                'email_verified' => true,
                'name'           => isset($payload['name']) && is_string($payload['name'])
                    ? trim(mb_substr($payload['name'], 0, 50))
                    : null,
            ],
        ];
    }

    /**
     * Resolve PEM public key for $kid:
     *   - string PEM when found
     *   - false      when JWKS loaded cleanly but $kid is absent
     *   - null       when JWKS could not be fetched at all
     */
    private function resolve_public_key_pem($kid)
    {
        $jwks = $this->read_cached_jwks();
        if (is_array($jwks)) {
            $pem = self::jwk_set_find_pem($jwks, $kid);
            if ($pem !== null) {
                return $pem;
            }
        }

        // Key rotation or cold cache: fetch fresh JWKS once
        $uri = (string) ($this->cfg['jwks_uri'] ?? 'https://www.googleapis.com/oauth2/v3/certs');
        $res = $this->http->get_json($uri);
        if (!$res['ok'] || !is_array($res['data']) || empty($res['data']['keys']) || !is_array($res['data']['keys'])) {
            return null;
        }

        $this->write_cached_jwks($res['data']);
        $pem = self::jwk_set_find_pem($res['data'], $kid);
        return $pem !== null ? $pem : false;
    }

    private function read_cached_jwks()
    {
        if (!$this->cache_file || !is_readable($this->cache_file)) {
            return null;
        }
        $ttl = max(60, (int) ($this->cfg['jwks_cache_seconds'] ?? 3600));
        $raw = @file_get_contents($this->cache_file);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $wrapped = json_decode($raw, true);
        if (!is_array($wrapped) || empty($wrapped['fetched_at']) || empty($wrapped['jwks'])) {
            return null;
        }
        if ((time() - (int) $wrapped['fetched_at']) > $ttl) {
            return null;
        }
        return $wrapped['jwks'];
    }

    private function write_cached_jwks(array $jwks)
    {
        if (!$this->cache_file) {
            return;
        }
        $dir = dirname($this->cache_file);
        if (is_dir($dir) && is_writable($dir)) {
            @file_put_contents(
                $this->cache_file,
                json_encode(['fetched_at' => time(), 'jwks' => $jwks], JSON_UNESCAPED_SLASHES)
            );
        }
    }

    public static function jwk_set_find_pem(array $jwks, $kid)
    {
        foreach (($jwks['keys'] ?? []) as $key) {
            if (!is_array($key)) {
                continue;
            }
            if (($key['kid'] ?? '') === $kid && ($key['kty'] ?? '') === 'RSA'
                && !empty($key['n']) && !empty($key['e'])) {
                return self::rsa_jwk_to_pem((string) $key['n'], (string) $key['e']);
            }
        }
        return null;
    }

    /**
     * Convert base64url RSA modulus ($n_b64) & exponent ($e_b64) into a PEM
     * SubjectPublicKeyInfo string suitable for openssl_verify().
     */
    public static function rsa_jwk_to_pem($n_b64, $e_b64)
    {
        $n = self::b64url_decode($n_b64);
        $e = self::b64url_decode($e_b64);
        if ($n === false || $e === false || $n === '' || $e === '') {
            return null;
        }

        $rsa_pub = self::asn1_seq(self::asn1_uint($n) . self::asn1_uint($e));
        // rsaEncryption OID 1.2.840.113549.1.1.1 + NULL
        $alg_id  = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $bit_str = "\x03" . self::asn1_len(strlen($rsa_pub) + 1) . "\x00" . $rsa_pub;
        $spki    = self::asn1_seq($alg_id . $bit_str);

        return "-----BEGIN PUBLIC KEY-----\n"
             . chunk_split(base64_encode($spki), 64, "\n")
             . "-----END PUBLIC KEY-----\n";
    }

    private static function asn1_uint($bytes)
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . self::asn1_len(strlen($bytes)) . $bytes;
    }

    private static function asn1_seq($bytes)
    {
        return "\x30" . self::asn1_len(strlen($bytes)) . $bytes;
    }

    private static function asn1_len($len)
    {
        if ($len < 0x80) {
            return chr($len);
        }
        $tmp = '';
        while ($len > 0) {
            $tmp = chr($len & 0xff) . $tmp;
            $len >>= 8;
        }
        return chr(0x80 | strlen($tmp)) . $tmp;
    }

    public static function b64url_decode($input)
    {
        $rem = strlen($input) % 4;
        if ($rem > 0) {
            $input .= str_repeat('=', 4 - $rem);
        }
        return base64_decode(strtr($input, '-_', '+/'), true);
    }

    public static function b64url_encode($bytes)
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function reject($message, $reason, $status = 401)
    {
        return [
            'ok'     => false,
            'status' => $status,
            'error'  => $message,
            'reason' => $reason,
        ];
    }
}
