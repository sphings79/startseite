<?php
declare(strict_types=1);

/**
 * Minimal WebAuthn relying party (passkeys as second factor): ES256 credentials, no attestation checks.
 * Covers CBOR parsing, authenticator data, client data validation and assertion signatures.
 */
final class WebAuthn
{
    public const ALG_ES256 = -7;

    public static function b64u(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function unb64u(string $s): string
    {
        $raw = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        if ($raw === false) {
            throw new RuntimeException('Ungültige Base64-Daten.');
        }
        return $raw;
    }

    /** Relying party id = host name without port. */
    public static function rpId(): string
    {
        return (string) preg_replace('/:\d+$/', '', Http::host());
    }

    public static function origin(): string
    {
        return (Http::isHttps() ? 'https' : 'http') . '://' . Http::host();
    }

    // ---------- CBOR (only what WebAuthn needs) ----------

    public static function cbor(string $data, int &$offset = 0, int $depth = 0): mixed
    {
        $n = strlen($data);
        if ($depth > 8 || $offset >= $n) {
            throw new RuntimeException('Ungültige CBOR-Daten.');
        }
        $head = ord($data[$offset++]);
        $major = $head >> 5;
        $info = $head & 31;
        $need = static function (int $len) use ($data, &$offset, $n): string {
            if ($len < 0 || $offset + $len > $n) {
                throw new RuntimeException('Ungültige CBOR-Daten.');
            }
            $chunk = substr($data, $offset, $len);
            $offset += $len;
            return $chunk;
        };
        $len = match (true) {
            $info < 24   => $info,
            $info === 24 => ord($need(1)),
            $info === 25 => unpack('n', $need(2))[1],
            $info === 26 => unpack('N', $need(4))[1],
            $info === 27 => unpack('J', $need(8))[1],
            default      => $major === 7 ? $info : throw new RuntimeException('Nicht unterstütztes CBOR.'),
        };
        switch ($major) {
            case 0:
                return $len;
            case 1:
                return -1 - $len;
            case 2:
                return $need($len);
            case 3:
                return $need($len);
            case 4:
                $out = [];
                for ($i = 0; $i < $len && $i < 1000; $i++) {
                    $out[] = self::cbor($data, $offset, $depth + 1);
                }
                return $out;
            case 5:
                $out = [];
                for ($i = 0; $i < $len && $i < 1000; $i++) {
                    $k = self::cbor($data, $offset, $depth + 1);
                    $out[is_int($k) || is_string($k) ? $k : throw new RuntimeException('Ungültiger CBOR-Schlüssel.')] = self::cbor($data, $offset, $depth + 1);
                }
                return $out;
            case 7:
                return match ($info) { 20 => false, 21 => true, 22, 23 => null, default => throw new RuntimeException('Nicht unterstütztes CBOR.') };
        }
        throw new RuntimeException('Nicht unterstütztes CBOR.');
    }

    // ---------- authenticator data ----------

    /**
     * @return array{rpIdHash:string,flags:int,signCount:int,credId:?string,publicKey:?array}
     */
    public static function parseAuthData(string $ad): array
    {
        if (strlen($ad) < 37) {
            throw new RuntimeException('Authenticator-Daten zu kurz.');
        }
        $out = [
            'rpIdHash'  => substr($ad, 0, 32),
            'flags'     => ord($ad[32]),
            'signCount' => unpack('N', substr($ad, 33, 4))[1],
            'credId'    => null,
            'publicKey' => null,
        ];
        if ($out['flags'] & 0x40) {
            if (strlen($ad) < 55) {
                throw new RuntimeException('Authenticator-Daten zu kurz.');
            }
            $idLen = unpack('n', substr($ad, 53, 2))[1];
            $out['credId'] = substr($ad, 55, $idLen);
            if (strlen($out['credId']) !== $idLen) {
                throw new RuntimeException('Authenticator-Daten zu kurz.');
            }
            $off = 55 + $idLen;
            $key = self::cbor($ad, $off);
            if (!is_array($key)) {
                throw new RuntimeException('Ungültiger öffentlicher Schlüssel.');
            }
            $out['publicKey'] = $key;
        }
        return $out;
    }

    /** COSE EC2 / P-256 key to PEM. */
    public static function coseToPem(array $key): string
    {
        $x = $key[-2] ?? '';
        $y = $key[-3] ?? '';
        if (($key[1] ?? null) !== 2 || ($key[3] ?? null) !== self::ALG_ES256 || ($key[-1] ?? null) !== 1 || strlen((string) $x) !== 32 || strlen((string) $y) !== 32) {
            throw new RuntimeException('Nur ES256-Passkeys (P-256) werden unterstützt.');
        }
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    // ---------- checks ----------

    /** Validates type, origin and returns the base64url challenge from the client data. */
    public static function checkClientData(string $json, string $type): string
    {
        $cd = json_decode($json, true);
        if (!is_array($cd) || ($cd['type'] ?? '') !== $type || !is_string($cd['challenge'] ?? null)) {
            throw new RuntimeException('Ungültige Client-Daten.');
        }
        if (!hash_equals(self::origin(), (string) ($cd['origin'] ?? '')) || !empty($cd['crossOrigin'])) {
            throw new RuntimeException('Falscher Ursprung der Anfrage.');
        }
        return $cd['challenge'];
    }

    public static function checkRpAndPresence(array $auth): void
    {
        if (!hash_equals(hash('sha256', self::rpId(), true), $auth['rpIdHash'])) {
            throw new RuntimeException('Passkey gehört zu einer anderen Domain.');
        }
        if (!($auth['flags'] & 0x01)) {
            throw new RuntimeException('Keine Nutzerpräsenz.');
        }
    }

    public static function verifySignature(string $authData, string $clientDataJson, string $signature, string $pem): bool
    {
        return openssl_verify($authData . hash('sha256', $clientDataJson, true), $signature, $pem, OPENSSL_ALGO_SHA256) === 1;
    }
}
