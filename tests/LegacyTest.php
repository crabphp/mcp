<?php

declare(strict_types=1);

namespace Crab\Mcp\Tests;

use Crab\App;
use Crab\Mcp\Mcp;
use Crab\Mcp\Tests\Fixtures\ListProducts;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;

/**
 * Clients of 2025-11-25, 2025-06-18 and 2025-03-26, which begin with
 * initialize and name their version only in a header, if at all.
 */
final class LegacyTest extends McpTestCase
{
    /**
     * @param array<string, mixed> $params
     * @param array<string, string> $headers
     */
    private static function legacy(
        App $app,
        string $method,
        array $params = [],
        ?string $version = '2025-11-25',
        int|string|null $id = 1,
        array $headers = [],
    ): ResponseInterface {
        $message = ['jsonrpc' => '2.0', ...($id === null ? [] : ['id' => $id]), 'method' => $method];
        if ($params !== []) {
            $message['params'] = $params;
        }
        return self::post(app: $app, body: json_encode($message, JSON_THROW_ON_ERROR), headers: [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
            ...($version === null ? [] : ['MCP-Protocol-Version' => $version]),
            ...$headers,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function initializing(string $version = '2025-11-25'): array
    {
        return [
            'protocolVersion' => $version,
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'Old', 'version' => '1.0'],
        ];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function versions(): iterable
    {
        yield '2025-11-25' => ['2025-11-25'];
        yield '2025-06-18' => ['2025-06-18'];
        yield '2025-03-26' => ['2025-03-26'];
    }

    #[DataProvider('versions')]
    public function testInitializeAgreesOnTheClientsVersionWithoutASession(string $version): void
    {
        // A client sends no version header with initialize: the version is what initialize agrees.
        $response = self::legacy(self::app(), 'initialize', self::initializing($version), version: null);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Mcp-Session-Id'));
        self::assertSame(
            [
                'protocolVersion' => $version,
                'capabilities' => [
                    'tools' => [],
                    'resources' => [],
                    'extensions' => ['io.modelcontextprotocol/skills' => ['directoryRead' => true]],
                ],
                'serverInfo' => ['name' => 'Shop', 'version' => '1.2.0'],
                'instructions' => 'Look products up before you change them.',
            ],
            self::decode($response)['result'],
        );
    }

    public function testAVersionNotServedIsAnsweredWithTheNewestLegacyOne(): void
    {
        $response = self::legacy(self::app(), 'initialize', self::initializing('2024-11-05'), version: null);

        self::assertSame('2025-11-25', self::decode($response)['result']['protocolVersion']);
    }

    public function testCapabilitiesAreAnObjectWhenNothingIsOffered(): void
    {
        $app = self::app(static fn() => Mcp::server('/mcp'));

        $response = self::legacy($app, 'initialize', self::initializing(), version: null);

        self::assertStringContainsString('"capabilities":{}', (string) $response->getBody());
    }

    public function testInitializeWithoutItsFieldsIsInvalidParams(): void
    {
        $response = self::legacy(self::app(), 'initialize', version: null);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(-32_602, self::decode($response)['error']['code']);
    }

    public function testInitializedAndOtherNotificationsAreAccepted(): void
    {
        foreach (['notifications/initialized', 'notifications/cancelled'] as $method) {
            $response = self::legacy(self::app(), $method, id: null);

            self::assertSame(202, $response->getStatusCode());
        }
    }

    public function testPingIsAnsweredWithAnEmptyObject(): void
    {
        $response = self::legacy(self::app(), 'ping');

        self::assertSame('{"jsonrpc":"2.0","id":1,"result":{}}', (string) $response->getBody());
    }

    public function testToolsAreListedAndCalledWithoutWhatOnlyTheModernRevisionHas(): void
    {
        $app = self::app();

        $listed = self::decode(self::legacy($app, 'tools/list'))['result'];
        $called = self::decode(self::legacy($app, 'tools/call', [
            'name' => 'show_product',
            'arguments' => ['id' => 3],
        ]))['result'];

        self::assertSame(['tools'], array_keys($listed));
        self::assertSame('show_product', $listed['tools'][0]['name']);
        self::assertSame(['content', 'structuredContent'], array_keys($called));
        self::assertSame(3, $called['structuredContent']['id']);
    }

    public function testNoMcpMethodOrNameHeaderIsAskedFor(): void
    {
        $response = self::legacy(self::app(), 'tools/call', ['name' => 'list_products'], version: '2025-06-18');

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('result', self::decode($response));
    }

    public function testWithoutAVersionHeaderARequestIs20250326(): void
    {
        $response = self::legacy(self::app(), 'tools/list', version: null);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayNotHasKey('ttlMs', self::decode($response)['result']);
    }

    public function testResourcesAreReadWithoutCacheHints(): void
    {
        $result = self::decode(self::legacy(self::app(), 'resources/read', ['uri' => 'shop://notes']))['result'];

        self::assertSame(['contents'], array_keys($result));
    }

    public function testProgressIsStreamedAsBefore(): void
    {
        $response = self::legacy(self::app(), 'tools/call', [
            'name' => 'countdown',
            'arguments' => ['from' => 2],
            '_meta' => ['progressToken' => 'old'],
        ]);

        $messages = self::messages($response);
        self::assertSame('notifications/progress', $messages[0]['method']);
        self::assertSame('Lift-off', $messages[2]['result']['content'][0]['text']);
        self::assertArrayNotHasKey('resultType', $messages[2]['result']);
    }

    public function testAnUnknownMethodIsAnErrorSentWith200(): void
    {
        $app = self::app(static fn() => Mcp::server('/mcp')->tools(ListProducts::class));

        foreach (['server/discover', 'skills/list', 'prompts/list'] as $method) {
            $response = self::legacy($app, $method);

            self::assertSame(200, $response->getStatusCode());
            self::assertSame(-32_601, self::decode($response)['error']['code']);
        }
    }

    public function testAVersionHeaderNotServedIsRefused(): void
    {
        $response = self::legacy(self::app(), 'tools/list', version: '2024-11-05');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(-32_022, self::decode($response)['error']['code']);
    }

    public function testAModernMetaUnderALegacyHeaderIsAMismatch(): void
    {
        $response = self::legacy(self::app(), 'tools/list', self::params(), version: '2025-11-25');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(-32_020, self::decode($response)['error']['code']);
    }

    public function testTheOriginIsCheckedAsForModernClients(): void
    {
        $response = self::legacy(self::app(), 'ping', headers: ['Origin' => 'https://evil.example']);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testSkillsAreServedAsTheExtensionSays(): void
    {
        $app = self::app();

        $listed = self::decode(self::legacy($app, 'skills/list'))['result'];
        $children = self::decode(self::legacy($app, 'resources/directory/read', [
            'uri' => 'skill://code-review',
        ]))['result'];

        self::assertSame(
            ['code-review', 'pdf-tools'],
            array_column(array_column($listed['skills'], 'frontmatter'), 'name'),
        );
        self::assertSame('public', $listed['cacheScope']);
        self::assertCount(2, $children['resources']);
    }
}
