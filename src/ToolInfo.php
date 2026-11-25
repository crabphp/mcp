<?php

declare(strict_types=1);

namespace Crab\Mcp;

use Crab\Contracts\Routing\RouteInfo;

/**
 * What a server knows of one of its tools: its #[Tool], and the action or
 * route behind it. A ToolFilter is given it to decide who may see the
 * tool, a ToolCalled listener to say what was called, and a route tool's
 * request carries it under ATTRIBUTE, so that middleware can tell a tool
 * call from a request made by hand.
 */
final readonly class ToolInfo
{
    /**
     * The request attribute a route tool's request carries this under.
     */
    public const string ATTRIBUTE = 'crab.mcp.tool';

    /**
     * @param class-string $class The class whose method the tool runs.
     * @param RouteInfo|null $route The route the tool calls, for a route tool.
     */
    public function __construct(
        public string $name,
        public string $description,
        public ?string $title,
        public ?bool $readOnly,
        public ?bool $destructive,
        public ?bool $idempotent,
        public ?bool $openWorld,
        public string $class,
        public string $method,
        public ?RouteInfo $route = null,
    ) {}

    /**
     * @param class-string $class
     */
    public static function of(Tool $tool, string $class, string $method, ?RouteInfo $route = null): self
    {
        return new self(
            $tool->name,
            $tool->description,
            $tool->title,
            $tool->readOnly,
            $tool->destructive,
            $tool->idempotent,
            $tool->openWorld,
            $class,
            $method,
            $route,
        );
    }

    /**
     * The Tool as tools/list sends it, around its schemas.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $output
     * @return array<string, mixed>
     */
    public function definition(array $input, ?array $output): array
    {
        $definition = ['name' => $this->name];
        if ($this->title !== null) {
            $definition['title'] = $this->title;
        }
        $definition['description'] = $this->description;
        $definition['inputSchema'] = $input;
        if ($output !== null) {
            $definition['outputSchema'] = $output;
        }
        $annotations = array_filter(
            [
                'readOnlyHint' => $this->readOnly,
                'destructiveHint' => $this->destructive,
                'idempotentHint' => $this->idempotent,
                'openWorldHint' => $this->openWorld,
            ],
            static fn(?bool $hint): bool => $hint !== null,
        );
        if ($annotations !== []) {
            $definition['annotations'] = $annotations;
        }
        return $definition;
    }

    /**
     * Whether the name is one a tool may have: 1 to 128 letters, digits,
     * "_", "-" and ".".
     */
    public static function validName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9_.\-]{1,128}$/', $name) === 1;
    }
}
