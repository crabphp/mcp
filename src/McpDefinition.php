<?php

declare(strict_types=1);

namespace Crab\Mcp;

use Crab\App;
use Crab\Contracts\Config\Config;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Definition;
use Crab\Contracts\Http\Factory;
use Crab\Contracts\Http\Request;
use Crab\Contracts\Routing\Actions;
use Crab\Contracts\Routing\RouteInfo;
use Crab\Contracts\Routing\Router;
use Crab\Contracts\Validation\Validator;
use Crab\Mcp\Http\Endpoint;
use Crab\Mcp\Protocol\Discovery;
use Crab\Mcp\Protocol\PreparedResource;
use Crab\Mcp\Protocol\PreparedTool;
use Crab\Mcp\Protocol\RouteTool;
use Crab\Mcp\Protocol\Server;
use Crab\Mcp\Protocol\ToolHandle;
use Crab\Mcp\Skills\SkillLibrary;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * The MCP servers an application declares, finished once its plugins,
 * setup and route files have run: every tool and resource read, every
 * skill loaded, and each server's endpoint added to the router as one
 * POST route, behind the middleware it was given. A class that cannot be
 * served stops the application here, before anything serves.
 *
 * @internal
 */
final class McpDefinition implements Definition
{
    /**
     * @var array<string, ServerDeclaration>
     */
    private array $declared = [];

    /**
     * @var array<string, Server>
     */
    private array $servers = [];

    private bool $finished = false;

    /**
     * Each server's filter, for the classes the container makes.
     *
     * @var array<string, ToolFilter|class-string<ToolFilter>|null>
     */
    private array $filters = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    public function declare(string $path): ServerDeclaration
    {
        if ($this->finished) {
            throw new \LogicException(
                "The MCP server {$path} is declared after the application was defined; declare servers in a routes file.",
            );
        }
        if (preg_match('~^/[^\s?#]*$~', $path) !== 1) {
            throw new \LogicException("An MCP server's path is a path such as /mcp, not \"{$path}\".");
        }
        // Declared again, as by a plugin adding its tools, it is the same server.
        return $this->declared[$path] ??= new ServerDeclaration($path);
    }

    /**
     * The servers, by path, once the application is defined.
     *
     * @return array<string, Server>
     */
    public function servers(): array
    {
        return $this->servers;
    }

    public function name(): string
    {
        return 'mcp';
    }

    public function finish(): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;
        $container = $this->container;
        $config = $container->get(Config::class);
        $actions = $container->get(Actions::class);
        $validator = $container->get(Validator::class);
        $router = $container->get(Router::class);
        $factory = $container->get(Factory::class);
        $url = $config->get('app.url');
        $own = is_string($url) ? Endpoint::origin($url) : null;
        $logger = static fn(): ?LoggerInterface => $container->has(LoggerInterface::class)
            ? $container->get(LoggerInterface::class)
            : null;
        $application = static fn(Request $request): ResponseInterface => $container->get(App::class)->handle($request);
        $events = static fn(): ?EventDispatcherInterface => $container->has(EventDispatcherInterface::class)
            ? $container->get(EventDispatcherInterface::class)
            : null;
        foreach ($this->declared as $path => $declaration) {
            $declaration->seal();
            $tools = [];
            $prepared = array_map(static fn(string $class): ToolHandle => PreparedTool::prepare(
                $class,
                $path,
                $actions,
                $validator,
            ), $declaration->tools);
            if ($declaration->fromRoutes) {
                $routed = [];
                foreach ($router->routes() as $route) {
                    $tool = self::routeTool($router, $route);
                    if ($tool !== null) {
                        $routed[] = RouteTool::prepare(
                            $route,
                            $tool,
                            $router,
                            $validator,
                            $factory,
                            $application,
                            $path,
                        );
                    }
                }
                // By name: one order however the plugins that declared the routes were listed.
                usort($routed, static fn(ToolHandle $a, ToolHandle $b): int => strcmp(
                    $a->info()->name,
                    $b->info()->name,
                ));
                array_push($prepared, ...$routed);
            }
            foreach ($prepared as $tool) {
                $name = $tool->info()->name;
                if (isset($tools[$name])) {
                    throw new \LogicException(
                        "The MCP server {$path} has two tools named {$name}, in "
                        . self::where($tools[$name])
                        . ' and '
                        . self::where($tool)
                        . "; a tool's name is its own.",
                    );
                }
                $tools[$name] = $tool;
            }
            $discovery = null;
            if ($declaration->discovery !== null) {
                $discovery = new Discovery(
                    $declaration->discovery['search'],
                    $declaration->discovery['call'],
                    $declaration->discovery['always'],
                    $declaration->discovery['groups'],
                );
                foreach ([$discovery->search, $discovery->call] as $named) {
                    if (isset($tools[$named]) || !ToolInfo::validName($named)) {
                        throw new \LogicException(
                            "The MCP server {$path} names a discovery tool {$named}, which "
                            . (
                                isset($tools[$named])
                                    ? 'is the name of another tool.'
                                    : 'is not a name a tool can have.'
                            ),
                        );
                    }
                }
                foreach ($discovery->always as $always) {
                    if (!isset($tools[$always])) {
                        throw new \LogicException(
                            "The MCP server {$path} always lists the tool {$always}, which it does not have.",
                        );
                    }
                }
            }
            $filter = self::filter($declaration->filter, $container);
            $resources = [];
            foreach ($declaration->resources as $class) {
                $resource = PreparedResource::prepare($class, $path, $actions);
                foreach ($resources as $other) {
                    if ($other->uri->template === $resource->uri->template) {
                        throw new \LogicException(
                            "The MCP server {$path} has two resources at {$resource->uri->template}, {$other->class} and {$class}.",
                        );
                    }
                }
                $resources[] = $resource;
            }
            $origins = [];
            foreach ($declaration->origins as $origin) {
                $normal = $origin === '*' ? '*' : Endpoint::origin($origin);
                if ($normal === null) {
                    throw new \LogicException(
                        "The MCP server {$path} is given the origin \"{$origin}\"; an origin is a scheme and host, such as https://app.example.com, or \"*\".",
                    );
                }
                $origins[] = $normal;
            }
            $server = new Server(
                $path,
                $declaration->name ?? (string) $config->get('app.name'),
                $declaration->version,
                $declaration->instructions,
                $declaration->ttl,
                $tools,
                $resources,
                $declaration->skills === null ? null : SkillLibrary::load($this->directory($declaration, $config)),
                $logger,
                $filter,
                $events,
                $declaration->maxResult,
                $discovery,
            );
            $this->filters[$path] = $declaration->filter;
            $endpoint = new Endpoint($server, $factory, $own, $origins);
            $route = $router->post($path, $endpoint->handle(...));
            if ($declaration->middleware !== []) {
                $route->middleware(...$declaration->middleware);
            }
            $this->servers[$path] = $server;
        }
    }

    public function entries(): array
    {
        $entries = [];
        foreach ($this->servers as $path => $server) {
            foreach ($server->tools as $name => $tool) {
                foreach ($tool->dependencies() as $class) {
                    $entries[$class] = "Tool {$name} (MCP server {$path})";
                }
            }
            $filter = $this->filters[$path] ?? null;
            if (is_string($filter)) {
                $entries[$filter] = "The tool filter of the MCP server {$path}";
            }
            foreach ($server->resources as $resource) {
                foreach ($resource->dependencies() as $class) {
                    $entries[$class] = "Resource {$resource->uri->template} (MCP server {$path})";
                }
            }
        }
        return $entries;
    }

    public function describe(): array
    {
        $lines = [];
        foreach ($this->servers as $path => $server) {
            $lines[] = "server {$path} {$server->name} {$server->version}";
            foreach ($server->tools as $name => $tool) {
                $info = $tool->info();
                $lines[] =
                    "tool {$path} {$name} "
                    . ($info->route === null ? $info->class : "{$info->route->method} {$info->route->pattern}");
            }
            foreach ($server->resources as $resource) {
                $lines[] = "resource {$path} {$resource->uri->template} {$resource->class}";
            }
            foreach ($server->skills->skills ?? [] as $name => $skill) {
                $lines[] = "skill {$path} {$name} {$skill->uri}";
            }
        }
        return $lines;
    }

    /**
     * The #[Tool] of a route's action, on its method or, for a class with
     * handle(), on the class; null when it has none.
     */
    private static function routeTool(Router $router, RouteInfo $route): ?Tool
    {
        $signature = $router->signature($route);
        if (!$signature instanceof \ReflectionMethod) {
            return null;
        }
        $attributes = $signature->getAttributes(Tool::class);
        if ($attributes === [] && $signature->getName() === 'handle') {
            $attributes = $signature->getDeclaringClass()->getAttributes(Tool::class);
        }
        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    private static function where(ToolHandle $tool): string
    {
        $info = $tool->info();
        return $info->route === null ? $info->class : "the route {$info->route->method} {$info->route->pattern}";
    }

    /**
     * The filter as the server calls it: a class named is made in the
     * worker, on first use.
     *
     * @param ToolFilter|class-string<ToolFilter>|null $filter
     * @return \Closure(ToolInfo, Request): bool|null
     */
    private static function filter(ToolFilter|string|null $filter, Container $container): ?\Closure
    {
        if ($filter === null) {
            return null;
        }
        if (is_string($filter) && !is_a($filter, ToolFilter::class, true)) {
            throw new \LogicException("{$filter} is given as a tool filter and is not a " . ToolFilter::class . '.');
        }
        return static function (ToolInfo $tool, Request $request) use ($filter, $container): bool {
            $made = is_string($filter) ? $container->get($filter) : $filter;
            return $made->handle($tool, $request);
        };
    }

    private function directory(ServerDeclaration $declaration, Config $config): string
    {
        $directory = (string) $declaration->skills;
        if (str_starts_with($directory, '/')) {
            return rtrim($directory, '/');
        }
        $base = $config->get('app.path');
        if (!is_string($base)) {
            throw new \LogicException(
                "The MCP server {$declaration->path} serves the skills in {$directory}, which is relative, and the application has no folder; give the full path, or build it with App::configure(__DIR__).",
            );
        }
        return rtrim($base, '/') . '/' . rtrim($directory, '/');
    }
}
