<?php

declare(strict_types=1);

namespace Crab\Mcp\Protocol;

use Crab\Contracts\Validation\Validator;
use Crab\Mcp\Content\Content;
use Crab\Mcp\ToolResult;

/**
 * What the framework's validator writes as JSON Schema, made fit to send:
 * the names of PHP classes it marks for its own reading ("x-class",
 * "x-rules") are left out, since they mean nothing to a client, and a
 * schema that says nothing is {}, never [].
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
            $clean[$key] = match (true) {
                // Each property is a schema, an object even when it says nothing: some callers refuse [].
                $key === 'properties' && is_array($value) => array_map(self::property(...), $value),
                in_array($key, ['items', 'additionalProperties'], true) && $value === [] => new \stdClass(),
                is_array($value) => self::cleanValue($value),
                default => $value,
            };
        }
        return $clean;
    }

    /**
     * One property's schema, {} when it says nothing.
     */
    private static function property(mixed $property): mixed
    {
        return match (true) {
            $property === [] => new \stdClass(),
            is_array($property) => self::clean($property),
            default => $property,
        };
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

    /**
     * The schema of what the tool returns, when its return type says it
     * exactly: a class the validator can read, whose JSON is its
     * constructor's promoted properties and nothing else, as are those of
     * every class inside it. Anything less certain has none, since a
     * client may check the result against it.
     *
     * @return array<string, mixed>|null
     */
    public static function output(\ReflectionFunctionAbstract $signature, Validator $validator): ?array
    {
        $type = $signature->getReturnType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin() || $type->allowsNull()) {
            return null;
        }
        $class = $type->getName();
        if (!class_exists($class) || new \ReflectionClass($class)->isInternal()) {
            return null;
        }
        if (
            is_a($class, ToolResult::class, true)
            || is_a($class, Content::class, true)
            || is_a($class, \Traversable::class, true)
        ) {
            return null;
        }
        try {
            $schema = $validator->schema($class);
        } catch (\LogicException) {
            return null;
        }
        foreach (self::classes($schema) as $inside) {
            if (!self::plain($inside)) {
                return null;
            }
        }
        return self::clean($schema);
    }

    /**
     * Whether a class is written as JSON exactly as the validator reads
     * it: every constructor parameter a promoted public property, and no
     * other public property.
     *
     * @param class-string $class
     */
    private static function plain(string $class): bool
    {
        $reflection = new \ReflectionClass($class);
        if ($reflection->implementsInterface(\JsonSerializable::class) || $reflection->isEnum()) {
            return false;
        }
        $constructor = $reflection->getConstructor();
        $promoted = [];
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            if (!$parameter->isPromoted()) {
                return false;
            }
            $promoted[] = $parameter->getName();
        }
        $public = [];
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $public[] = $property->getName();
        }
        sort($promoted);
        sort($public);
        return $promoted === $public;
    }
}
