<?php

namespace App\Domain\Valuation;

use InvalidArgumentException;

final class Decimal
{
    public static function positive(string $value, string $field): void
    {
        if (! preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', $value) || bccomp($value, '0', self::scale($value)) <= 0) {
            throw new InvalidArgumentException("{$field} must be a positive decimal string.");
        }
    }

    public static function multiply(string $left, string $right): string
    {
        return bcmul($left, $right, self::scale($left) + self::scale($right));
    }

    public static function roundPlnGrosz(string $value): string
    {
        return bcdiv(bcadd($value, '0.005', max(self::scale($value), 3)), '1', 2);
    }

    public static function plnToGrosze(string $value): int
    {
        $grosze = bcmul($value, '100', 0);

        if (bccomp($grosze, (string) PHP_INT_MAX, 0) === 1) {
            throw new InvalidArgumentException('PLN grosze value exceeds the supported integer range.');
        }

        return (int) $grosze;
    }

    private static function scale(string $value): int
    {
        $decimalSeparator = strpos($value, '.');

        return $decimalSeparator === false ? 0 : strlen($value) - $decimalSeparator - 1;
    }
}
