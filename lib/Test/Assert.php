<?php

namespace FriendsOfRedaxo\DomainSettings\Test;

use function array_key_exists;
use function is_array;
use function sprintf;

/**
 * The handful of assertions these tests need.
 *
 * Modelled on YForm's own Assert (addons/yform/lib/Test/Assert.php) - same
 * idea, fewer methods.
 */
final class Assert
{
    /**
     * Ends the current test as skipped rather than passed.
     *
     * @throws SkippedException
     * @return never
     */
    public static function skip(string $reason): void
    {
        throw new SkippedException($reason);
    }

    public static function same(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new AssertionFailed(sprintf(
                '%sexpected %s, got %s',
                '' === $message ? '' : $message . ': ',
                self::describe($expected),
                self::describe($actual),
            ));
        }
    }

    /** @phpstan-assert true $condition */
    public static function true(bool $condition, string $message = ''): void
    {
        self::same(true, $condition, $message);
    }

    /** @phpstan-assert false $condition */
    public static function false(bool $condition, string $message = ''): void
    {
        self::same(false, $condition, $message);
    }

    /** @phpstan-assert null $value */
    public static function null(mixed $value, string $message = ''): void
    {
        self::same(null, $value, $message);
    }

    /** @param array<mixed> $array */
    public static function hasKey(int|string $key, array $array, string $message = ''): void
    {
        if (!array_key_exists($key, $array)) {
            throw new AssertionFailed(sprintf(
                '%skey "%s" missing, present: %s',
                '' === $message ? '' : $message . ': ',
                $key,
                implode(', ', array_keys($array)) ?: '(none)',
            ));
        }
    }

    public static function contains(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new AssertionFailed(sprintf(
                '%s"%s" not found in "%s"',
                '' === $message ? '' : $message . ': ',
                $needle,
                mb_substr($haystack, 0, 120),
            ));
        }
    }

    private static function describe(mixed $value): string
    {
        if (is_array($value)) {
            return '[' . implode(', ', array_map(self::describe(...), $value)) . ']';
        }

        return var_export($value, true);
    }
}
