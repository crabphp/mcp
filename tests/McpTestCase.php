<?php

declare(strict_types=1);

namespace Crab\Mcp\Tests;

use Crab\App;
use Crab\Config\Config;
use Crab\Http\Request;
use Crab\Mcp\Mcp;
use Crab\Mcp\McpPlugin;
use Crab\Mcp\Tests\Fixtures\Countdown;
use Crab\Mcp\Tests\Fixtures\Crash;
use Crab\Mcp\Tests\Fixtures\ListProducts;
use Crab\Mcp\Tests\Fixtures\Notes;
use Crab\Mcp\Tests\Fixtures\ProductResource;
use Crab\Mcp\Tests\Fixtures\Refuse;
use Crab\Mcp\Tests\Fixtures\ShowProduct;
use Crab\Mcp\Tests\Fixtures\WhoAmI;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

abstract class McpTestCase extends TestCase
{
    protected const string SKILLS = __DIR__ . '/Fixtures/skills';

    /**
     * @param array<string, mixed> $config
     */
    protected static function app(?\Closure $declare = null, array $config = []): App
    {
        $app = new App(new Config(['log' => ['path' => 'php://memory'], ...$config]));
        $app->register(McpPlugin::class);
        if ($declare !== null) {
            $declare();
        } else {
            Mcp::server('/mcp')
                ->tools([
                    ShowProduct::class,
                    ListProducts::class,
                    Countdown::class,
                    Refuse::class,
                    Crash::class,
                    WhoAmI::class,
                ])
                ->resources([Notes::class, ProductResource::class])
                ->skills(self::SKILLS)
                ->info('Shop', '1.2.0')
                ->instructions('Look products up before you change them.');
        }
        return $app;
    }

    /**
     * The params of a request, with the _meta every request carries.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    protected static function params(array $params = [], array $meta = []): array
    {
        return [
            ...$params,
            '_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientInfo' => ['name' => 'Test', 'version' => '1.0.0'],
                'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
                ...$meta,
            ],
        ];
    }

    /**
     * Sends a request as a conforming client does, its headers from its body.
     *
     * @param array<string, mixed> $params
     * @param array<string, string|null> $headers Replace or, as null, remove a header.
     */
    protected static function send(
        App $app,
        string $method,
        array $params = [],
        array $headers = [],
        int|string|null $id = 1,
        string $path = '/mcp',
    ): ResponseInterface {
        $message = [
            'jsonrpc' => '2.0',
            ...($id === null ? [] : ['id' => $id]),
            'method' => $method,
            'params' => $params,
        ];
        $name = $params['name'] ?? $params['uri'] ?? null;
        $all = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => $method,
            ...(
                is_string($name) && in_array($method, ['tools/call', 'resources/read'], true)
                    ? ['Mcp-Name' => $name]
                    : []
            ),
            ...$headers,
        ];
        return self::post($app, json_encode($message, JSON_THROW_ON_ERROR), $all, $path);
    }

    /**
     * @param array<string, string|null> $headers
     */
    protected static function post(App $app, string $body, array $headers, string $path = '/mcp'): ResponseInterface
    {
        return $app->handle(
            new Request(
                'POST',
                $path,
                array_filter($headers, static fn(?string $value): bool => $value !== null),
                $body,
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected static function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }

    /**
     * The result of a request that must succeed.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected static function answer(App $app, string $method, array $params = []): array
    {
        $response = self::send($app, $method, self::params($params));
        $body = self::decode($response);
        self::assertSame(200, $response->getStatusCode(), (string) json_encode($body));
        self::assertArrayHasKey('result', $body, (string) json_encode($body));
        return $body['result'];
    }

    /**
     * The messages of an event stream.
     *
     * @return list<array<string, mixed>>
     */
    protected static function messages(ResponseInterface $response): array
    {
        $messages = [];
        foreach (explode("\n\n", trim((string) $response->getBody())) as $event) {
            self::assertStringStartsWith('data: ', $event);
            $messages[] = json_decode(substr($event, 6), true, flags: JSON_THROW_ON_ERROR);
        }
        return $messages;
    }
}
