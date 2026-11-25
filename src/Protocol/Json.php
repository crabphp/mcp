<?php

declare(strict_types=1);

namespace Crab\Mcp\Protocol;

/**
 * Writes what the server sends as JSON: one line, never pretty, so that a
 * message fits the stdio transport's one message to a line, and values a
 * tool returns as the framework writes them for a route, dates as text
 * and enums as their values.
 *
 * @internal
 */
final class Json
{
    private const int FLAGS =
        JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * The value as one line of JSON.
     */
    public static function encode(mixed $value): string
    {
        return json_encode($value, self::FLAGS);
    }

    /**
     * The value as plain data, the way the framework answers a route with
     * it: a date as ISO 8601 text, a backed enum as its value, an object as
     * its public properties, and JsonSerializable as what it says.
     */
    public static function value(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \UnitEnum) {
            return $value->name;
        }
        if ($value instanceof \JsonSerializable) {
            return self::value($value->jsonSerialize());
        }
        if (is_object($value)) {
            $properties = get_object_vars($value);
            if ($properties === []) {
                return new \stdClass();
            }
            return (object) array_map(self::value(...), $properties);
        }
        if (is_array($value)) {
            return array_map(self::value(...), $value);
        }
        if (is_float($value) && !is_finite($value)) {
            throw new \UnexpectedValueException('JSON has no number for ' . var_export($value, true) . '.');
        }
        return $value;
    }

    /**
     * Whether decoded JSON was an object: a list is not one, and an empty
     * array may have been either.
     */
    public static function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }
}
