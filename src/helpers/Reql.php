<?php

namespace justinholtweb\bee\helpers;

/**
 * Building ReQL — Recombee's filter/booster expression language.
 *
 * ReQL is evaluated server-side by Recombee, so an unescaped value is a real injection surface:
 * a search term or a category slug pasted straight into a filter can change which items a visitor
 * is allowed to see. Nothing anywhere in Bee interpolates a value into ReQL directly; it goes
 * through `value()` or `equals()`.
 */
abstract class Reql
{
    /**
     * A property reference. ReQL names properties in single quotes.
     */
    public static function prop(string $name): string
    {
        // Property names are validated on the way in, but a reference built from unchecked input
        // would otherwise be able to close the quote and append an expression.
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $name) . "'";
    }

    /**
     * A literal. Strings become double-quoted and escaped; numbers, booleans and null stay bare;
     * arrays become ReQL sets.
     */
    public static function value(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        if (is_array($value)) {
            return '{' . implode(', ', array_map(static fn($v) => self::value($v), $value)) . '}';
        }

        return '"' . str_replace(["\\", '"', "\n", "\r"], ["\\\\", '\\"', '\\n', '\\r'], (string)$value) . '"';
    }

    public static function equals(string $property, mixed $value): string
    {
        return self::prop($property) . ' == ' . self::value($value);
    }

    /**
     * Combine expressions with `and`. Empty pieces are dropped, so callers can build a filter from
     * a list of optional conditions without special-casing the empty result.
     */
    public static function all(array $expressions): ?string
    {
        $expressions = array_values(array_filter(array_map('trim', $expressions), static fn($e) => $e !== ''));

        if ($expressions === []) {
            return null;
        }

        if (count($expressions) === 1) {
            return $expressions[0];
        }

        return implode(' and ', array_map(static fn($e) => '(' . $e . ')', $expressions));
    }

    public static function any(array $expressions): ?string
    {
        $expressions = array_values(array_filter(array_map('trim', $expressions), static fn($e) => $e !== ''));

        if ($expressions === []) {
            return null;
        }

        if (count($expressions) === 1) {
            return $expressions[0];
        }

        return implode(' or ', array_map(static fn($e) => '(' . $e . ')', $expressions));
    }

    /**
     * The filter Bee adds to every recommendation request unless the caller opts out.
     *
     * The catalog is push-based, so it can lag: an entry disabled or expired thirty seconds ago is
     * still in Recombee. Without this, the first thing a merchant sees after unpublishing something
     * is that thing being recommended.
     */
    public static function liveOnly(?int $siteId = null): string
    {
        $parts = [
            self::prop('enabled') . ' == true',
            '(' . self::prop('expiryDate') . ' == null or ' . self::prop('expiryDate') . ' > now())',
            '(' . self::prop('postDate') . ' == null or ' . self::prop('postDate') . ' <= now())',
        ];

        if ($siteId !== null) {
            $parts[] = self::equals('siteId', $siteId);
        }

        return self::all($parts);
    }
}
