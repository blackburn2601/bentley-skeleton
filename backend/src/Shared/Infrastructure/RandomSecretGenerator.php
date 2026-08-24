<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Domain\SecretGenerator;

final readonly class RandomSecretGenerator implements SecretGenerator
{
    public function generate(int $bytes = 32): string
    {
        // URL-safe and unpadded: these values travel in cookies, URLs and headers, and
        // "+/=" get mangled by something in that path sooner or later.
        $token = rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
        \assert('' !== $token);

        return $token;
    }

    public function generateNumericCode(int $digits = 8): string
    {
        // 10 ** $digits is float|int to PHP's inference; recovery codes are 8-10 digits, so
        // the value is always well inside int range.
        $max = (int) (10 ** $digits) - 1;

        return str_pad((string) random_int(0, $max), $digits, '0', \STR_PAD_LEFT);
    }

    public function generateTemporaryPassword(int $length = 16): string
    {
        // Unambiguous alphabet: no 0/O, 1/l/I. The result must clear PasswordPolicy (length and
        // the repetition check), so 16 chars from this set is deliberately generous and varied.
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $max = \strlen($alphabet) - 1;
        $out = '';

        for ($i = 0; $i < $length; ++$i) {
            $out .= $alphabet[random_int(0, $max)];
        }

        \assert('' !== $out);

        return $out;
    }
}
