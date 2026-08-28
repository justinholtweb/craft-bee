<?php

namespace justinholtweb\bee\helpers;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;
use DateTimeInterface;

/**
 * Turning Craft values into Recombee property values.
 *
 * Recombee is strictly typed per property: a property declared `double` that receives the string
 * "12.00" is rejected for the whole item, so a single bad field can silently stop a product from
 * ever being recommended. Everything is coerced here, once, against the declared type.
 */
abstract class Props
{
    public const TYPE_INT = 'int';
    public const TYPE_DOUBLE = 'double';
    public const TYPE_STRING = 'string';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_TIMESTAMP = 'timestamp';
    public const TYPE_SET = 'set';
    public const TYPE_IMAGE = 'image';
    public const TYPE_IMAGE_LIST = 'imageList';

    public const TYPES = [
        self::TYPE_INT,
        self::TYPE_DOUBLE,
        self::TYPE_STRING,
        self::TYPE_BOOLEAN,
        self::TYPE_TIMESTAMP,
        self::TYPE_SET,
        self::TYPE_IMAGE,
        self::TYPE_IMAGE_LIST,
    ];

    /**
     * Recombee property names: letters, digits and underscores, not starting with a digit.
     * `!` is reserved by Recombee for its internal properties.
     */
    public static function isValidName(string $name): bool
    {
        return (bool)preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name);
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            self::TYPE_INT => Craft::t('bee', 'Whole number'),
            self::TYPE_DOUBLE => Craft::t('bee', 'Decimal number'),
            self::TYPE_STRING => Craft::t('bee', 'Text'),
            self::TYPE_BOOLEAN => Craft::t('bee', 'True/false'),
            self::TYPE_TIMESTAMP => Craft::t('bee', 'Date'),
            self::TYPE_SET => Craft::t('bee', 'Set of values'),
            self::TYPE_IMAGE => Craft::t('bee', 'Image URL'),
            self::TYPE_IMAGE_LIST => Craft::t('bee', 'List of image URLs'),
            default => $type,
        };
    }

    /**
     * Coerce a raw value to the declared Recombee type.
     *
     * Returns null when the value cannot be represented — the caller drops the property rather than
     * sending something the API will reject. Null is a legitimate Recombee value meaning "unset",
     * so dropping is never destructive.
     */
    public static function coerce(mixed $value, string $type): mixed
    {
        $value = self::unwrap($value);

        if ($value === null || $value === '') {
            return null;
        }

        return match ($type) {
            self::TYPE_INT => is_numeric($value) ? (int)$value : null,
            self::TYPE_DOUBLE => is_numeric($value) ? (float)$value : null,
            self::TYPE_BOOLEAN => self::toBool($value),
            self::TYPE_TIMESTAMP => self::toTimestamp($value),
            self::TYPE_SET => self::toSet($value),
            self::TYPE_IMAGE => self::toUrl($value),
            self::TYPE_IMAGE_LIST => array_values(array_filter(array_map(
                static fn($v) => self::toUrl($v),
                is_array($value) ? $value : [$value],
            ), static fn($v) => $v !== null)),
            default => self::toString($value),
        };
    }

    /**
     * Resolve the things Craft hands back that are not scalars: element queries, collections,
     * asset elements, models with a __toString.
     *
     * Note what is *not* here: `instanceof Traversable`. Every Craft element is Traversable (Yii
     * models are IteratorAggregate), so a generic "flatten anything iterable" branch would explode
     * an element into its attribute values and lose the element entirely.
     */
    private static function unwrap(mixed $value): mixed
    {
        if ($value instanceof ElementQueryInterface) {
            $value = $value->all();
        }

        if ($value instanceof \craft\elements\ElementCollection || $value instanceof \Illuminate\Support\Collection) {
            $value = $value->all();
        }

        return $value;
    }

    private static function toBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (bool)(float)$value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return null;
    }

    /**
     * Recombee accepts ISO 8601 or a UTC epoch. Epoch is used: it has no time zone to get wrong,
     * which matters because Craft hands back site-local DateTimes and ReQL date maths in Recombee
     * is done in UTC seconds.
     */
    private static function toTimestamp(mixed $value): ?int
    {
        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (is_numeric($value)) {
            return (int)$value;
        }

        if (is_string($value)) {
            $ts = strtotime($value);

            return $ts === false ? null : $ts;
        }

        return null;
    }

    private static function toSet(mixed $value): array
    {
        if (!is_array($value)) {
            $value = [$value];
        }

        $out = [];

        foreach ($value as $item) {
            $string = self::toString($item);

            if ($string !== null && $string !== '' && !in_array($string, $out, true)) {
                $out[] = $string;
            }
        }

        return $out;
    }

    /**
     * Assets become absolute URLs; anything else that can be a string becomes one.
     */
    private static function toString(mixed $value): ?string
    {
        if ($value instanceof Asset) {
            return $value->getUrl();
        }

        if ($value instanceof ElementInterface) {
            return (string)$value->title ?: (string)$value->id;
        }

        if (is_array($value)) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_object($value) && !method_exists($value, '__toString')) {
            return null;
        }

        return (string)$value;
    }

    /**
     * Recombee's `image` type wants an absolute URL. A protocol-relative or root-relative URL is
     * accepted by the API and then fails to load in the customer's own recommendation widgets, so
     * it is promoted here rather than left to be discovered in production.
     */
    private static function toUrl(mixed $value): ?string
    {
        $url = self::toString($value);

        if ($url === null || $url === '') {
            return null;
        }

        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        if (!preg_match('#^https?://#i', $url)) {
            $url = rtrim(Craft::$app->getSites()->getPrimarySite()->getBaseUrl() ?? '', '/')
                . '/' . ltrim($url, '/');
        }

        return $url;
    }
}
