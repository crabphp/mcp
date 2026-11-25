<?php

declare(strict_types=1);

namespace Crab\Mcp\Protocol;

use Crab\Contracts\Http\HttpError;
use Crab\Contracts\Http\Problem;
use Crab\Contracts\Http\Request;
use Crab\Contracts\Routing\Actions;
use Crab\Contracts\Routing\PreparedAction;
use Crab\Contracts\Validation\Validator;
use Crab\Mcp\Content\Content;
use Crab\Mcp\Content\Text;
use Crab\Mcp\Progress;
use Crab\Mcp\Tool;
use Crab\Mcp\ToolError;
use Crab\Mcp\ToolResult;
use Psr\Http\Message\ResponseInterface;

/**
 * A tool whose class has been read: its definition for tools/list, made
 * once, and the action the router would call, prepared through
 * Contracts\Routing\Actions so that its arguments are validated the way a
 * route's body is.
 *
 * @internal
 */
final readonly class PreparedTool
{
    /**
     * @param class-string $class
     * @param array<string, mixed> $definition
     */
    private function __construct(
        public string $name,
        public string $class,
        private PreparedAction $action,
        private array $definition,
    ) {}

    /**
     * Reads the class, and throws a LogicException naming the tool and
     * what to do when it cannot be one.
     *
     * @param string $server The server's path, for messages.
     */
    public static function prepare(string $class, string $server, Actions $actions, Validator $validator): self
    {
        $where = "{$class}, a tool of the MCP server {$server},";
        if (!class_exists($class)) {
            throw new \LogicException("{$where} is not a class. List the class of a tool, such as ShowProduct::class.");
        }
        $attributes = new \ReflectionClass($class)->getAttributes(Tool::class);
        if ($attributes === []) {
            throw new \LogicException(
                "{$where} is not marked as one. Add #[Tool('name', 'What it does.')] to the class, from "
                . Tool::class
                . '.',
            );
        }
        $tool = $attributes[0]->newInstance();
        if (preg_match('/^[A-Za-z0-9_.\-]{1,128}$/', $tool->name) !== 1) {
            throw new \LogicException(
                "{$where} is named \"{$tool->name}\"; a tool's name is 1 to 128 letters, digits, \"_\", \"-\" and \".\", such as show_product.",
            );
        }
        if (trim($tool->description) === '') {
            throw new \LogicException("{$where} has no description; say what it does, for the model.");
        }
        try {
            $action = $actions->prepare('POST', $server, $class);
        } catch (\LogicException $error) {
            throw new \LogicException("{$where} cannot be called: {$error->getMessage()}", previous: $error);
        }
        $input = $action->input();
        if (isset($input['query'])) {
            throw new \LogicException(
                "{$where} reads the query string, which a tool call does not have. Take its arguments as one input class or #[Fields] Data.",
            );
        }
        $schema = $input['body'] ?? ['type' => 'object', 'properties' => new \stdClass()];
        if (Schema::hasFile($schema)) {
            throw new \LogicException(
                "{$where} takes a file, which a tool's JSON arguments cannot carry. Take the file's URL or its text instead.",
            );
        }
        $return = $action->signature()->getReturnType();
        if ($return instanceof \ReflectionNamedType && is_a($return->getName(), ResponseInterface::class, true)) {
            throw new \LogicException(
                "{$where} returns an HTTP response; a tool returns data, a string, Content or a ToolResult.",
            );
        }
        $definition = ['name' => $tool->name];
        if ($tool->title !== null) {
            $definition['title'] = $tool->title;
        }
        $definition['description'] = $tool->description;
        $definition['inputSchema'] = Schema::clean($schema);
        $output = self::outputSchema($action->signature(), $validator);
        if ($output !== null) {
            $definition['outputSchema'] = $output;
        }
        $annotations = array_filter(
            [
                'readOnlyHint' => $tool->readOnly,
                'destructiveHint' => $tool->destructive,
                'idempotentHint' => $tool->idempotent,
                'openWorldHint' => $tool->openWorld,
            ],
            static fn(?bool $hint): bool => $hint !== null,
        );
        if ($annotations !== []) {
            $definition['annotations'] = $annotations;
        }
        return new self($tool->name, $class, $action, $definition);
    }

    /**
     * The Tool, as tools/list sends it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return $this->definition;
    }

    /**
     * The classes the container makes to call it.
     *
     * @return list<class-string>
     */
    public function dependencies(): array
    {
        return $this->action->dependencies();
    }

    /**
     * Calls the tool with the arguments as the request's body. A tool
     * whose handle() is a generator gives one too, which yields its
     * Progress and returns its result.
     *
     * @param array<mixed> $arguments
     * @return array<string, mixed>|\Generator<int, Progress, mixed, array<string, mixed>>
     */
    public function call(array $arguments, Request $request): array|\Generator
    {
        try {
            $outcome = $this->action->invoke($request->withParsedBody($arguments));
            if (!$outcome->entered) {
                return self::rejected($outcome->problem)->toArray();
            }
            if ($outcome->value instanceof \Generator) {
                return $this->stream($outcome->value);
            }
            return self::result($outcome->value)->toArray();
        } catch (\Throwable $error) {
            return self::failed($error)->toArray();
        }
    }

    /**
     * @return \Generator<int, Progress, mixed, array<string, mixed>>
     */
    private function stream(\Generator $tool): \Generator
    {
        try {
            $last = null;
            while ($tool->valid()) {
                $progress = $tool->current();
                if (!$progress instanceof Progress) {
                    throw new \LogicException(
                        "The tool {$this->name} yielded "
                        . get_debug_type($progress)
                        . '; a tool yields '
                        . Progress::class
                        . ' and returns its result.',
                    );
                }
                if ($last !== null && $progress->progress <= $last) {
                    throw new \LogicException(
                        "The tool {$this->name} reported progress {$progress->progress} after {$last}; progress only grows.",
                    );
                }
                $last = $progress->progress;
                yield $progress;
                $tool->next();
            }
            return self::result($tool->getReturn())->toArray();
        } catch (\Throwable $error) {
            return self::failed($error)->toArray();
        }
    }

    /**
     * What the tool returned, as its result.
     */
    private static function result(mixed $value): ToolResult
    {
        if ($value instanceof ToolResult) {
            return $value;
        }
        if ($value instanceof Content) {
            return ToolResult::of($value);
        }
        if (is_array($value) && $value !== [] && array_is_list($value) && self::allContent($value)) {
            return ToolResult::of(...$value);
        }
        if (is_string($value)) {
            return ToolResult::of(new Text($value));
        }
        if ($value === null) {
            return ToolResult::of();
        }
        if ($value instanceof ResponseInterface || $value instanceof \Generator) {
            throw new \LogicException(
                'A tool returned '
                . get_debug_type($value)
                . '; a tool returns data, a string, Content or a ToolResult.',
            );
        }
        return ToolResult::structured($value);
    }

    /**
     * @param list<mixed> $values
     */
    private static function allContent(array $values): bool
    {
        foreach ($values as $value) {
            if (!$value instanceof Content) {
                return false;
            }
        }
        return true;
    }

    /**
     * What the tool threw, as an error the model can read when it is one
     * the tool meant to give, or thrown on to be reported.
     */
    private static function failed(\Throwable $error): ToolResult
    {
        if ($error instanceof ToolError) {
            return ToolResult::error($error->getMessage());
        }
        // A client's mistake the framework would have answered with 4xx: its message is meant to be read.
        if ($error instanceof HttpError && $error->status < 500) {
            return ToolResult::error($error->getMessage());
        }
        throw $error;
    }

    /**
     * Arguments that do not fit the tool's input, as the tool's error, so
     * that the model can correct them.
     *
     * @see https://modelcontextprotocol.io/specification/2026-07-28/server/tools#error-handling
     */
    private static function rejected(?Problem $problem): ToolResult
    {
        if ($problem === null) {
            return ToolResult::error('The arguments are invalid.');
        }
        $lines = [$problem->message];
        foreach ([...$problem->path, ...$problem->fields] as $field => $messages) {
            foreach ($messages as $message) {
                $lines[] = "{$field}: {$message}";
            }
        }
        return ToolResult::error(implode("\n", $lines));
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
    private static function outputSchema(\ReflectionFunctionAbstract $signature, Validator $validator): ?array
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
        foreach (Schema::classes($schema) as $inside) {
            if (!self::plain($inside)) {
                return null;
            }
        }
        return Schema::clean($schema);
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
