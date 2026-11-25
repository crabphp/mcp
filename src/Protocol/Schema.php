<?php

declare(strict_types=1);

namespace Crab\Mcp\Protocol;

/**
 * What the framework's validator writes as JSON Schema, made fit to send:
 * the names of PHP classes it marks for its own reading ("x-class",
 * "x-rules") are left out, since they mean nothing to a client.
 *
 * @internal
 */
final class Schema
{
    private const array PRIVATE = ['x-class', 'x-rules'];

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public static function clean(array $schema): array
    {
        $clean = [];
        foreach ($schema as $key => $value) {
            if (in_array($key, self::PRIVATE, true)) {
                continue;
            }
            $clean[$key] = is_array($value) ? self::cleanValue($value) : $value;
        }
        return $clean;
    }

    /**
     * The classes the schema describes as objects, in the order met: the
     * class itself and those inside it. Enums are left out.
     *
     * @param array<mixed> $schema
     * @return list<class-string>
     */
    public static function classes(array $schema): array
    {
        $classes = [];
        if (isset($schema['x-class']) && is_string($schema['x-class']) && !isset($schema['enum'])) {
            /** @var class-string $class */
            $class = $schema['x-class'];
            $classes[] = $class;
        }
        foreach ($schema as $value) {
            if (!is_array($value)) {
                continue;
            }

            array_push($classes, ...self::classes($value));
        }
        return array_values(array_unique($classes));
    }

    /**
     * Whether some part of the schema is a file, which JSON cannot carry.
     *
     * @param array<mixed> $schema
     */
    public static function hasFile(array $schema): bool
    {
        if (($schema['format'] ?? null) === 'binary') {
            return true;
        }
        foreach ($schema as $value) {
            if (is_array($value) && self::hasFile($value)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private static function cleanValue(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(static fn(mixed $item): mixed => is_array($item)
                ? self::cleanValue($item)
                : $item, $value);
        }
        return self::clean($value);
    }
}
