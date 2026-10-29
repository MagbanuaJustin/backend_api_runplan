<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class WebPushService
{
    private $config;

    public function __construct(array $config = null)
    {
        $this->config = $config ?: integration_config('push');
    }

    public function is_ready()
    {
        $enabled = filter_var($this->config['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $enabled === true
            && extension_loaded('openssl')
            && function_exists('curl_init')
            && trim((string) ($this->config['public_key'] ?? '')) !== ''
            && trim((string) ($this->config['private_key'] ?? '')) !== ''
            && trim((string) ($this->config['subject'] ?? '')) !== '';
    }

    public function send($endpoint, array $subscription_keys, array $payload, $ttl = 3600)
    {
        try {
            if (!$this->is_ready()) return $this->result(false, 0, false);

            $url = parse_url((string) $endpoint);
            if (!is_array($url) || strtolower((string) ($url['scheme'] ?? '')) !== 'https' || empty($url['host'])) {
                return $this->result(false, 0, false);
            }

            $public_raw = $this->base64url_decode((string) $this->config['public_key']);
            $private_raw = $this->base64url_decode(trim((string) $this->config['private_key']));
            if (strlen($public_raw) !== 65 || $public_raw[0] !== "\x04" || strlen($private_raw) !== 32) {
                return $this->result(false, 0, false);
            }

            $body = $this->encrypt_payload(
                json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                (string) ($subscription_keys['p256dh'] ?? ''),
                (string) ($subscription_keys['auth'] ?? '')
            );

            $jwt = $this->vapid_token($url, $private_raw, $public_raw);
            $headers = array(
                'Authorization: vapid t=' . $jwt . ', k=' . $this->config['public_key'],
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                'TTL: ' . max(0, min(86400, (int) $ttl)),
            );

            $ch = curl_init((string) $endpoint);
            curl_setopt_array($ch, array(
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                CURLOPT_CONNECTTIMEOUT => min(5, (int) ($this->config['timeout'] ?? 10)),
                CURLOPT_TIMEOUT => max(5, (int) ($this->config['timeout'] ?? 10)),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ));

            curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_errno($ch);
            curl_close($ch);

            if ($error !== 0) return $this->result(false, 0, false);

            return $this->result(
                $status >= 200 && $status < 300,
                $status,
                in_array($status, array(404, 410), true)
            );
        } catch (Throwable $e) {
            return $this->result(false, 0, false);
        }
    }

    private function encrypt_payload($payload, $client_key_b64, $auth_b64)
    {
        $client_public = $this->base64url_decode($client_key_b64);
        $auth_secret = $this->base64url_decode($auth_b64);

        if (strlen($client_public) !== 65 || $client_public[0] !== "\x04" || strlen($auth_secret) < 16) {
            throw new RuntimeException('Invalid Web Push subscription keys.');
        }

        $ephemeral = openssl_pkey_new(array(
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ));
        if ($ephemeral === false) throw new RuntimeException('Could not create ephemeral key.');

        $details = openssl_pkey_get_details($ephemeral);
        $ec = $details['ec'] ?? array();
        $server_public = "\x04" . ($ec['x'] ?? '') . ($ec['y'] ?? '');
        if (strlen($server_public) !== 65) throw new RuntimeException('Could not read ephemeral public key.');

        $peer = openssl_pkey_get_public($this->public_key_pem($client_public));
        if ($peer === false) throw new RuntimeException('Invalid peer public key.');

        $shared = openssl_pkey_derive($peer, $ephemeral, 32);
        if ($shared === false) throw new RuntimeException('ECDH failed.');

        $auth_prk = $this->hkdf_extract($auth_secret, $shared);
        $key_info = "WebPush: info\0" . $client_public . $server_public;
        $ikm = $this->hkdf_expand($auth_prk, $key_info, 32);

        $salt = random_bytes(16);
        $prk = $this->hkdf_extract($salt, $ikm);
        $cek = $this->hkdf_expand($prk, "Content-Encoding: aes128gcm\0", 16);
        $nonce = $this->hkdf_expand($prk, "Content-Encoding: nonce\0", 12);

        $tag = '';
        $ciphertext = openssl_encrypt(
            (string) $payload . "\x02",
            'aes-128-gcm',
            $cek,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            16
        );

        if ($ciphertext === false || strlen($tag) !== 16) throw new RuntimeException('Payload encryption failed.');

        return $salt . pack('N', 4096) . chr(strlen($server_public)) . $server_public . $ciphertext . $tag;
    }

    private function vapid_token(array $url, $private_raw, $public_raw)
    {
        $origin = strtolower((string) $url['scheme']) . '://' . strtolower((string) $url['host']);
        if (isset($url['port'])) $origin .= ':' . (int) $url['port'];

        $header = $this->base64url_encode(json_encode(array('typ' => 'JWT', 'alg' => 'ES256')));
        $claims = $this->base64url_encode(json_encode(array(
            'aud' => $origin,
            'exp' => time() + 12 * 60 * 60,
            'sub' => (string) $this->config['subject'],
        ), JSON_UNESCAPED_SLASHES));

        $message = $header . '.' . $claims;
        $key = openssl_pkey_get_private($this->private_key_pem($private_raw, $public_raw));

        if ($key === false || !openssl_sign($message, $der_signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('VAPID signing failed.');
        }

        return $message . '.' . $this->base64url_encode($this->ecdsa_der_to_raw($der_signature, 32));
    }

    private function public_key_pem($point)
    {
        $algorithm = $this->der(0x30, "\x06\x07\x2A\x86\x48\xCE\x3D\x02\x01" . "\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07");
        $spki = $this->der(0x30, $algorithm . $this->der(0x03, "\0" . $point));
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private function private_key_pem($private_raw, $public_raw)
    {
        $curve = "\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07";
        $sec1 = $this->der(0x30,
            "\x02\x01\x01" .
            $this->der(0x04, $private_raw) .
            $this->der(0xA0, $curve) .
            $this->der(0xA1, $this->der(0x03, "\0" . $public_raw)));
        return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($sec1), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
    }

    private function der($tag, $value)
    {
        $length = strlen($value);
        if ($length < 0x80) {
            $encoded_length = chr($length);
        } else {
            $bytes = '';
            for ($n = $length; $n > 0; $n = intdiv($n, 256)) {
                $bytes = chr($n & 0xff) . $bytes;
            }
            $encoded_length = chr(0x80 | strlen($bytes)) . $bytes;
        }
        return chr($tag) . $encoded_length . $value;
    }

    private function ecdsa_der_to_raw($der, $size)
    {
        $offset = 0;
        if (ord($der[$offset++]) !== 0x30) throw new RuntimeException('Bad ECDSA signature.');
        $this->read_der_length($der, $offset);
        $r = $this->read_der_integer($der, $offset);
        $s = $this->read_der_integer($der, $offset);
        return str_pad(ltrim($r, "\0"), $size, "\0", STR_PAD_LEFT)
            . str_pad(ltrim($s, "\0"), $size, "\0", STR_PAD_LEFT);
    }

    private function read_der_integer($der, &$offset)
    {
        if (ord($der[$offset++]) !== 0x02) throw new RuntimeException('Bad ECDSA integer.');
        $length = $this->read_der_length($der, $offset);
        $value = substr($der, $offset, $length);
        $offset += $length;
        return $value;
    }

    private function read_der_length($der, &$offset)
    {
        $length = ord($der[$offset++]);
        if (($length & 0x80) === 0) return $length;

        $count = $length & 0x7f;
        $length = 0;
        for ($i = 0; $i < $count; $i++) $length = ($length << 8) | ord($der[$offset++]);
        return $length;
    }

    private function hkdf_extract($salt, $ikm)
    {
        return hash_hmac('sha256', $ikm, $salt, true);
    }

    private function hkdf_expand($prk, $info, $length)
    {
        $out = '';
        $previous = '';

        for ($counter = 1; strlen($out) < $length; $counter++) {
            $previous = hash_hmac('sha256', $previous . $info . chr($counter), $prk, true);
            $out .= $previous;
        }

        return substr($out, 0, $length);
    }

    private function base64url_decode($value)
    {
        $value = strtr((string) $value, '-_', '+/');
        $value .= str_repeat('=', (4 - strlen($value) % 4) % 4);
        $decoded = base64_decode($value, true);
        if ($decoded === false) throw new RuntimeException('Invalid base64url value.');
        return $decoded;
    }

    private function base64url_encode($value)
    {
        return rtrim(strtr(base64_encode((string) $value), '+/', '-_'), '=');
    }

    private function result($ok, $status, $expired)
    {
        return array('ok' => (bool) $ok, 'http_status' => (int) $status, 'expired' => (bool) $expired);
    }
}