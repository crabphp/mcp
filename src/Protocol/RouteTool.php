<?php

declare(strict_types=1);

namespace Crab\Mcp\Protocol;

use Crab\Contracts\Http\Factory;
use Crab\Contracts\Http\Request;
use Crab\Contracts\Routing\RouteInfo;
use Crab\Contracts\Routing\Router;
use Crab\Contracts\Validation\Validator;
use Crab\Mcp\Content\Text;
use Crab\Mcp\Tool;
use Crab\Mcp\ToolInfo;
use Crab\Mcp\ToolResult;
use Psr\Http\Message\ResponseInterface;

/**
 * A route of the application that is a tool: its action's method is
 * marked #[Tool]. A call is a request to the route, made in the process
 * and handled by the application as one from outside would be, through
 * every middleware around it and around the route, so that whatever
 * guards the route guards the tool. It carries the MCP request's headers,
 * its credentials among them, and its address.
 *
 * The arguments are one object: the route's path parameters, its query
 * fields and its body's fields side by side, each sent where the route
 * reads it. The route's answer is the result: what it answers 2xx with,
 * and with isError what it answers 4xx with, so that the model reads the
 * application's own refusal. Any other status is a failure the
 * application has already reported, answered with an internal error.
 *
 * @internal
 */
final readonly class RouteTool implements ToolHandle
{
    /**
     * A route's parameter in its pattern, its expression perhaps holding
     * braces of its own: {id:\d{1,9}}.
     */
    private const string PARAMETER = '/\{([A-Za-z_][A-Za-z0-9_]*)(?::((?:[^{}]++|\{[^{}]*\})+))?\}/';

    /**
     * Headers that belong to the MCP request's own transport, not to
     * whoever sent it.
     */
    private const array TRANSPORT = [
        'accept',
        'accept-encoding',
        'connection',
        'content-length',
        'content-type',
        'expect',
        'last-event-id',
        'te',
        'transfer-encoding',
        'upgrade',
    ];

    /**
     * @param array<string, mixed> $definition
     * @param array<string, string> $locations Where each argument goes: "parameters", "query" or "body".
     * @param \Closure(Request): ResponseInterface $application Handles a request as the application does.
     */
    private function __construct(
        private ToolInfo $info,
        private array $definition,
        private array $locations,
        private Factory $factory,
        private \Closure $application,
    ) {}

    /**
     * Reads the route as a tool, and throws a LogicException naming it
     * when it cannot be one.
     *
     * @param \Closure(Request): ResponseInterface $application
     */
    public static function prepare(
        RouteInfo $route,
        Tool $tool,
        Router $router,
        Validator $validator,
        Factory $factory,
        \Closure $application,
        string $server,
    ): self {
        $where = "The route {$route->method} {$route->pattern}, a tool of the MCP server {$server},";
        if (!ToolInfo::validName($tool->name)) {
            throw new \LogicException(
                "{$where} is named \"{$tool->name}\"; a tool's name is 1 to 128 letters, digits, \"_\", \"-\" and \".\".",
            );
        }
        if (trim($tool->description) === '') {
            throw new \LogicException("{$where} has no description; say what it does, for the model.");
        }
        $input = $router->input($route) ?? throw new \LogicException(
            "{$where} is answered by a Handler of its own, whose input is not declared.",
        );
        $properties = [];
        $required = [];
        $locations = [];
        $parameters = (array) $input['parameters'];
        $parts = [
            'parameters' => ['properties' => $parameters, 'required' => array_keys($parameters)],
            'query' => $input['query'] ?? [],
            'body' => $input['body'] ?? [],
        ];
        foreach ($parts as $location => $part) {
            foreach ((array) ($part['properties'] ?? []) as $name => $property) {
                if (isset($locations[$name])) {
                    throw new \LogicException(
                        "{$where} takes {$name} in its {$locations[$name]} and in its {$location}; a tool's arguments are one object, so name one of them otherwise.",
                    );
                }
                $locations[$name] = $location;
                $properties[$name] = $property;
            }
            array_push($required, ...array_values((array) ($part['required'] ?? [])));
        }
        $schema = ['type' => 'object', 'properties' => $properties === [] ? new \stdClass() : $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }
        $schema['additionalProperties'] = false;
        if (Schema::hasFile($schema)) {
            throw new \LogicException("{$where} takes a file, which a tool's JSON arguments cannot carry.");
        }
        $signature = $router->signature($route);
        $class = $signature instanceof \ReflectionMethod ? $signature->getDeclaringClass()->getName() : 'Closure';
        $method = $signature?->getName() ?? '';
        $info = ToolInfo::of($tool, $class, $method, $route);
        $output = $signature === null ? null : Schema::output($signature, $validator);
        return new self($info, $info->definition(Schema::clean($schema), $output), $locations, $factory, $application);
    }

    public function info(): ToolInfo
    {
        return $this->info;
    }

    public function definition(): array
    {
        return $this->definition;
    }

    public function dependencies(): array
    {
        // The route's own: the application checks them as it checks every route.
        return [];
    }

    public function call(array $arguments, Request $request): array
    {
        $parts = ['parameters' => [], 'query' => [], 'body' => []];
        foreach ($arguments as $name => $value) {
            $location = $this->locations[$name] ?? null;
            if ($location === null) {
                return ToolResult::error(
                    "{$name} is not an argument of {$this->info->name}; it takes "
                    . ($this->locations === [] ? 'none.' : implode(', ', array_keys($this->locations)) . '.'),
                )->toArray();
            }
            $parts[$location][$name] = $value;
        }
        $route = $this->info->route;
        $path = '';
        try {
            $path = $this->path((string) $route?->pattern, $parts['parameters']);
        } catch (\InvalidArgumentException $error) {
            return ToolResult::error($error->getMessage())->toArray();
        }
        $query = http_build_query($parts['query'], '', '&', PHP_QUERY_RFC3986);
        $method = (string) $route?->method;
        $inner = $this->factory->createServerRequest($method, $path . ($query === '' ? '' : "?{$query}"), [
            'REMOTE_ADDR' => $request->ip(),
        ]);
        foreach ($request->getHeaders() as $name => $values) {
            $lower = strtolower((string) $name);
            if (!in_array($lower, self::TRANSPORT, true) && !str_starts_with($lower, 'mcp-')) {
                $inner = $inner->withHeader((string) $name, $values);
            }
        }
        $inner = $inner->withHeader('Accept', 'application/json')->withAttribute(ToolInfo::ATTRIBUTE, $this->info);
        if (!in_array($method, ['GET', 'HEAD', 'DELETE'], true)) {
            $inner = $inner
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->factory->createStream(Json::encode((object) $parts['body'])));
        }
        if (!$inner instanceof Request) {
            throw new \LogicException(
                'The HTTP factory makes requests that are not ' . Request::class . '; a route tool is called with one.',
            );
        }
        return self::result(($this->application)($inner), $this->info->name);
    }

    /**
     * The route's path with the arguments in place of its parameters,
     * each held to the parameter's expression.
     *
     * @param array<string, mixed> $values
     */
    private function path(string $pattern, array $values): string
    {
        return (string) preg_replace_callback(
            self::PARAMETER,
            static function (array $match) use ($values): string {
                $name = $match[1];
                $value = $values[$name] ?? null;
                if (!is_int($value) && !is_string($value)) {
                    throw new \InvalidArgumentException("{$name} is required, as a string or an integer.");
                }
                $expression = $match[2] ?? '[^/]+';
                if (preg_match('~^(?:' . str_replace('~', '\~', $expression) . ')$~D', (string) $value) !== 1) {
                    throw new \InvalidArgumentException("{$name} is not of the form this tool takes.");
                }
                return rawurlencode((string) $value);
            },
            $pattern,
        );
    }

    /**
     * What the route answered, as the tool's result.
     *
     * @return array<string, mixed>
     */
    private static function result(ResponseInterface $response, string $name): array
    {
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300 && $status < 400 || $status >= 500) {
            // A failure the application's error handler has already reported, or an answer that is not data.
            throw new RouteToolFailed("The route of the tool {$name} answered {$status}.");
        }
        $error = $status >= 400;
        $body = (string) $response->getBody();
        if ($body === '') {
            return $error
                ? ToolResult::error("The tool was refused ({$status}).")->toArray()
                : ToolResult::of()->toArray();
        }
        $type = strtolower($response->getHeaderLine('Content-Type'));
        if (preg_match('~^application/(?:[a-z0-9.\-]+\+)?json\b~', $type) === 1) {
            try {
                $value = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $value = null;
            }
            if ($value !== null || $body === 'null') {
                $result = ToolResult::structured($value, new Text($body))->toArray();
                if ($error) {
                    $result['isError'] = true;
                }
                return $result;
            }
        }
        $text = mb_check_encoding($body, 'UTF-8')
            ? $body
            : '(' . strlen($body) . ' bytes of ' . ($type ?: 'data') . ')';
        return $error ? ToolResult::error($text)->toArray() : ToolResult::of(new Text($text))->toArray();
    }
}
