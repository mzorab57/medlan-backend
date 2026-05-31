<?php
class JWT
{
    private static function b64url_encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64url_decode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }

    public static function encode(array $payload, string $secret, int $expSeconds = null): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        if ($expSeconds) {
            $payload['exp'] = time() + $expSeconds;
        }
        $segments = [
            self::b64url_encode(json_encode($header)),
            self::b64url_encode(json_encode($payload)),
        ];
        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, $secret, true);
        $segments[] = self::b64url_encode($signature);
        return implode('.', $segments);
    }

    public static function decode(string $token, string $secret): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return [false, null, 'invalid token'];
        }
        [$h, $p, $s] = $parts;
        $signingInput = $h . '.' . $p;
        $expected = self::b64url_encode(hash_hmac('sha256', $signingInput, $secret, true));
        if (!hash_equals($expected, $s)) {
            return [false, null, 'signature mismatch'];
        }
        $payload = json_decode(self::b64url_decode($p), true);
        if (isset($payload['exp']) && time() >= (int)$payload['exp']) {
            return [false, null, 'token expired'];
        }
        return [true, $payload, null];
    }
}
