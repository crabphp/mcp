<?php

declare(strict_types=1);

namespace Crab\Mcp\Protocol;

use Crab\Contracts\Http\HttpError;
use Crab\Contracts\Http\Request;
use Crab\Contracts\Routing\Actions;
use Crab\Contracts\Routing\PreparedAction;
use Crab\Mcp\Contents;
use Crab\Mcp\Resource;

/**
 * A resource whose class has been read: its URI or template, what
 * resources/list or resources/templates/list says of it, and the action
 * that reads it, prepared as a GET whose pattern has the template's
 * names, so that each value is read from the URI as a route parameter is
 * from a path.
 *
 * @internal
 */
final readonly class PreparedResource
{
    /**
     * @param class-string $class
     */
    private function __construct(
        public string $class,
        public UriTemplate $uri,
        private Resource $resource,
        private PreparedAction $action,
    ) {}

    /**
     * Reads the class, and throws a LogicException naming the resource
     * and what to do when it cannot be one.
     *
     * @param string $server The server's path, which the template's names are added to.
     */
    public static function prepare(string $class, string $server, Actions $actions): self
    {
        $where = "{$class}, a resource of the MCP server {$server},";
        if (!class_exists($class)) {
            throw new \LogicException(
                "{$where} is not a class. List the class of a resource, such as ProductResource::class.",
            );
        }
        $attributes = new \ReflectionClass($class)->getAttributes(Resource::class);
        if ($attributes === []) {
            throw new \LogicException(
                "{$where} is not marked as one. Add #[Resource('scheme://its/uri', 'name')] to the class, from "
                . Resource::class
                . '.',
            );
        }
        $resource = $attributes[0]->newInstance();
        try {
            $uri = UriTemplate::parse($resource->uri);
        } catch (\InvalidArgumentException $error) {
            throw new \LogicException(
                "{$where} has the URI {$resource->uri}. {$error->getMessage()}",
                previous: $error,
            );
        }
        if (trim($resource->name) === '') {
            throw new \LogicException("{$where} has no name; give it a short one.");
        }
        if ($resource->ttl < 0) {
            throw new \LogicException("{$where} is kept for {$resource->ttl} ms; ttl is 0 or more milliseconds.");
        }
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)/', $server, $taken);
        foreach ($uri->names as $name) {
            if (in_array($name, $taken[1], true)) {
                throw new \LogicException(
                    "{$where} has {{$name}} in its URI, which is also a parameter of the server's path; name it otherwise.",
                );
            }
        }
        $pattern =
            rtrim($server, '/') . implode('', array_map(static fn(string $name): string => "/{{$name}}", $uri->names));
        try {
            $action = $actions->prepare('GET', $pattern === '' ? '/' : $pattern, $class);
        } catch (\LogicException $error) {
            throw new \LogicException(
                "{$where} cannot be read: {$error->getMessage()} A resource takes the parts of its URI by name, such as handle(int \$id) for {id}.",
                previous: $error,
            );
        }
        if (isset($action->input()['query'])) {
            throw new \LogicException("{$where} reads the query string, which reading a resource does not have.");
        }
        return new self($class, $uri, $resource, $action);
    }

    /**
     * A Resource, or for a template a ResourceTemplate, as it is listed.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return array_filter(
            [
                $this->uri->isTemplate() ? 'uriTemplate' : 'uri' => $this->uri->template,
                'name' => $this->resource->name,
                'title' => $this->resource->title,
                'description' => $this->resource->description,
                'mimeType' => $this->resource->mimeType,
            ],
            static fn(?string $value): bool => $value !== null,
        );
    }

    /**
     * @return list<class-string>
     */
    public function dependencies(): array
    {
        return $this->action->dependencies();
    }

    /**
     * Reads the resource at the URI, whose parts' values are given.
     *
     * @param array<string, string> $values
     * @return array<string, mixed> The ReadResourceResult's own fields.
     * @throws McpError When there is no such resource.
     */
    public function read(string $uri, array $values, Request $request): array
    {
        foreach ($values as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }
        try {
            $outcome = $this->action->invoke($request);
        } catch (HttpError $error) {
            if ($error->status >= 500) {
                throw $error;
            }
            throw McpError::invalidParams(
                $error->status === 404 ? "Resource not found: {$uri}." : $error->getMessage(),
            );
        }
        if (!$outcome->entered) {
            $problems = [];
            foreach ($outcome->problem->path ?? [] as $name => $messages) {
                $problems[] = "{$name} " . implode(' ', $messages);
            }
            throw McpError::invalidParams(
                $problems === []
                    ? (string) $outcome->problem?->message
                    : "Resource not found: {$uri}; " . implode('; ', $problems),
            );
        }
        if ($outcome->value === null) {
            throw McpError::invalidParams("Resource not found: {$uri}.");
        }
        return [
            'contents' => $this->contents($outcome->value, $uri),
            'ttlMs' => $this->resource->ttl,
            'cacheScope' => $this->resource->public ? 'public' : 'private',
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function contents(mixed $value, string $uri): array
    {
        $type = $this->resource->mimeType;
        if (is_string($value)) {
            return [Contents::text($value)->toArray($uri, $type)];
        }
        if ($value instanceof Contents) {
            return [$value->toArray($uri, $type)];
        }
        if (is_array($value) && array_is_list($value) && $value !== []) {
            $parts = [];
            foreach ($value as $part) {
                if (!$part instanceof Contents) {
                    $parts = null;
                    break;
                }
                $parts[] = $part->toArray($uri, $type);
            }
            if ($parts !== null) {
                return $parts;
            }
        }
        return [Contents::text(Json::encode(Json::value($value)), $type ?? 'application/json')->toArray($uri)];
    }
}
