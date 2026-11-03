<?php

namespace App\Support;

/**
 * Initial passwords emailed to new employees: letters, digits and "%", with at least one of each kind.
 * Look-alike characters (0/O, 1/l/I) are left out so the password can be typed from the email reliably.
 */
final class PasswordGenerator
{
    private const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const LOWER = 'abcdefghijkmnopqrstuvwxyz';

    private const DIGITS = '23456789';

    private const SYMBOL = '%';

    public static function generate(int $length = 10): string
    {
        $all = self::UPPER.self::LOWER.self::DIGITS.self::SYMBOL;

        $chars = [self::pick(self::UPPER), self::pick(self::LOWER), self::pick(self::DIGITS), self::SYMBOL];
        while (count($chars) < $length) {
            $chars[] = self::pick($all);
        }

        // Fisher-Yates with a CSPRNG, so the guaranteed characters aren't always at the front.
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    private static function pick(string $pool): string
    {
        return $pool[random_int(0, strlen($pool) - 1)];
    }
}
