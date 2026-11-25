<?php

declare(strict_types=1);

namespace Crab\Mcp;

use Crab\Contracts\Config\Config;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Definition;
use Crab\Contracts\Http\Factory;
use Crab\Contracts\Routing\Actions;
use Crab\Contracts\Routing\Router;
use Crab\Contracts\Validation\Validator;
use Crab\Mcp\Http\Endpoint;
use Crab\Mcp\Protocol\PreparedResource;
use Crab\Mcp\Protocol\PreparedTool;
use Crab\Mcp\Protocol\Server;
use Crab\Mcp\Skills\SkillLibrary;
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
        if (isset($this->declared[$path])) {
            throw new \LogicException(
                "The MCP server {$path} is declared twice; declare it once and list everything it offers there.",
            );
        }
        return $this->declared[$path] = new ServerDeclaration($path);
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
        foreach ($this->declared as $path => $declaration) {
            $declaration->seal();
            $tools = [];
            foreach ($declaration->tools as $class) {
                $tool = PreparedTool::prepare($class, $path, $actions, $validator);
                if (isset($tools[$tool->name])) {
                    throw new \LogicException(
                        "The MCP server {$path} has two tools named {$tool->name}, {$tools[$tool->name]->class} and {$class}; a tool's name is its own.",
                    );
                }
                $tools[$tool->name] = $tool;
            }
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
            );
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
                $lines[] = "tool {$path} {$name} {$tool->class}";
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
