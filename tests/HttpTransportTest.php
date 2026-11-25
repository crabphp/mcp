<?php

declare(strict_types=1);

namespace Crab\Mcp\Tests;

use Crab\Mcp\Mcp;
use Crab\Mcp\Tests\Fixtures\ListProducts;
use Crab\Mcp\Tests\Fixtures\RequireKey;
use Crab\Mcp\Tests\Fixtures\ShowProduct;

final class HttpTransportTest extends McpTestCase
{
    public function testDiscoverSaysWhatTheServerSpeaksAndOffers(): void
    {
        $result = self::answer(self::app(), 'server/discover');

        self::assertSame('complete', $result['resultType']);
        self::assertSame(['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26'], $result['supportedVersions']);
        self::assertSame(
            [
                'tools' => [],
                'resources' => [],
                'extensions' => ['io.modelcontextprotocol/skills' => ['directoryRead' => true]],
            ],
            $result['capabilities'],
        );
        self::assertSame('Look products up before you change them.', $result['instructions']);
        self::assertSame(0, $result['ttlMs']);
        self::assertSame('public', $result['cacheScope']);
        self::assertSame(
            ['name' => 'Shop', 'version' => '1.2.0'],
            $result['_meta']['io.modelcontextprotocol/serverInfo'],
        );
    }

    public function testCapabilitiesAreAnEmptyObjectWhenNothingIsOffered(): void
    {
        $app = self::app(static function (): void {
            Mcp::server('/mcp');
        });

        $response = self::send($app, 'server/discover', self::params());

        self::assertStringContainsString('"capabilities":{}', (string) $response->getBody());
        self::assertStringContainsString('"name":"Crab","version":"0.0.0"', (string) $response->getBody());
    }

    public function testAMethodOfACapabilityNotDeclaredIsNotFound(): void
    {
        $app = self::app(static function (): void {
            Mcp::server('/mcp');
        });

        $response = self::send($app, 'tools/list', self::params());

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(-32_601, self::decode($response)['error']['code']);
    }

    public function testAnUnknownMethodIs404WithMethodNotFound(): void
    {
        $response = self::send(self::app(), 'prompts/list', self::params());

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'id' => 1,
                'error' => ['code' => -32_601, 'message' => 'Method not found: prompts/list.'],
            ],
            self::decode($response),
        );
    }

    public function testANotificationIsAccepted(): void
    {
        $response = self::send(self::app(), 'notifications/cancelled', ['requestId' => 1], id: null);

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
    }

    public function testAnUnsupportedVersionIsRefusedWithTheSupportedOnes(): void
    {
        $response = self::send(
            self::app(),
            'tools/list',
            self::params(meta: ['io.modelcontextprotocol/protocolVersion' => '2099-01-01']),
            ['MCP-Protocol-Version' => '2099-01-01'],
        );

        self::assertSame(400, $response->getStatusCode());
        $error = self::decode($response)['error'];
        self::assertSame(-32_022, $error['code']);
        self::assertSame(
            ['supported' => ['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26'], 'requested' => '2099-01-01'],
            $error['data'],
        );
    }

    public function testInitializeInTheModernEraIsNotAMethod(): void
    {
        $response = self::send(self::app(), 'initialize', self::params());

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(-32_601, self::decode($response)['error']['code']);
    }

    /**
     * @return iterable<string, array{array<string, string|null>, string}>
     */
    public static function mismatchedHeaders(): iterable
    {
        yield 'no protocol version' => [['MCP-Protocol-Version' => null], 'MCP-Protocol-Version'];
        yield 'no method' => [['Mcp-Method' => null], 'Mcp-Method'];
        yield 'another method' => [['Mcp-Method' => 'tools/list'], 'Mcp-Method'];
        yield 'no name' => [['Mcp-Name' => null], 'Mcp-Name'];
        yield 'another name' => [['Mcp-Name' => 'list_products'], 'Mcp-Name'];
        yield 'a character a header may not hold' => [['Mcp-Name' => 'shöw_product'], 'Mcp-Name'];
    }

    /**
     * @param array<string, string|null> $headers
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('mismatchedHeaders')]
    public function testHeadersThatDoNotMatchTheBodyAreRefused(array $headers, string $named): void
    {
        $response = self::send(
            self::app(),
            'tools/call',
            self::params(['name' => 'show_product', 'arguments' => ['id' => 1]]),
            $headers,
        );

        self::assertSame(400, $response->getStatusCode());
        $error = self::decode($response)['error'];
        self::assertSame(-32_020, $error['code']);
        self::assertStringContainsString($named, $error['message']);
        self::assertSame(1, self::decode($response)['id']);
    }

    public function testTheBodysVersionMustBeTheHeaders(): void
    {
        $response = self::send(self::app(), 'tools/list', self::params(meta: [
            'io.modelcontextprotocol/protocolVersion' => '2025-11-25',
        ]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(-32_020, self::decode($response)['error']['code']);
    }

    public function testABase64EncodedNameIsDecodedBeforeItIsCompared(): void
    {
        $response = self::send(self::app(), 'tools/call', self::params(['name' => 'list_products']), [
            'Mcp-Name' => '=?base64?' . base64_encode('list_products') . '?=',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('result', self::decode($response));
    }

    public function testARequestWithoutMetaIsInvalid(): void
    {
        $response = self::send(self::app(), 'tools/list', [], ['MCP-Protocol-Version' => '2026-07-28']);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(-32_020, self::decode($response)['error']['code']);
    }

    public function testARequestWithoutClientCapabilitiesIsInvalid(): void
    {
        $params = self::params();
        unset($params['_meta']['io.modelcontextprotocol/clientCapabilities']);

        $response = self::send(self::app(), 'tools/list', $params);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(-32_602, self::decode($response)['error']['code']);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function malformed(): iterable
    {
        yield 'not JSON' => ['{"jsonrpc":', -32_700];
        yield 'a batch' => ['[{"jsonrpc":"2.0","id":1,"method":"tools/list"}]', -32_600];
        yield 'no version' => ['{"id":1,"method":"tools/list"}', -32_600];
        yield 'a response' => ['{"jsonrpc":"2.0","id":1,"result":{}}', -32_600];
        yield 'a null id' => ['{"jsonrpc":"2.0","id":null,"method":"tools/list"}', -32_600];
        yield 'params that are a list' => ['{"jsonrpc":"2.0","id":1,"method":"tools/list","params":[1]}', -32_600];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformed')]
    public function testAMalformedMessageIsRefused(string $body, int $code): void
    {
        $response = self::post(self::app(), $body, ['Content-Type' => 'application/json']);

        self::assertSame(400, $response->getStatusCode());
        $decoded = self::decode($response);
        self::assertSame($code, $decoded['error']['code']);
        self::assertArrayNotHasKey('id', $decoded);
    }

    public function testABodyThatIsNotJsonIsAParseError(): void
    {
        $response = self::post(self::app(), 'id=1', ['Content-Type' => 'application/x-www-form-urlencoded']);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(-32_700, self::decode($response)['error']['code']);
    }

    public function testGetAndDeleteAreNotAllowed(): void
    {
        $app = self::app();

        foreach (['GET', 'DELETE'] as $method) {
            $response = $app->handle(new \Crab\Http\Request($method, '/mcp'));

            self::assertSame(405, $response->getStatusCode());
            self::assertSame('POST', $response->getHeaderLine('Allow'));
        }
    }

    public function testASessionIdIsIgnoredAndNeverMinted(): void
    {
        $response = self::send(self::app(), 'tools/list', self::params(), ['Mcp-Session-Id' => 'abc']);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Mcp-Session-Id'));
    }

    public function testAnotherSitesOriginIsRefused(): void
    {
        $response = self::send(self::app(), 'tools/list', self::params(), ['Origin' => 'https://evil.example']);

        self::assertSame(403, $response->getStatusCode());
        self::assertArrayNotHasKey('id', self::decode($response));
    }

    public function testTheApplicationsOwnOriginIsTaken(): void
    {
        $app = self::app(config: ['app' => ['url' => 'https://shop.example']]);

        $own = self::send($app, 'tools/list', self::params(), ['Origin' => 'https://shop.example']);
        $other = self::send($app, 'tools/list', self::params(), ['Origin' => 'http://shop.example']);

        self::assertSame(200, $own->getStatusCode());
        self::assertSame(403, $other->getStatusCode());
    }

    public function testWithoutAnApplicationUrlTheRequestsOwnOriginIsTaken(): void
    {
        $response = self::send(self::app(), 'tools/list', self::params(), [
            'Host' => 'localhost:8080',
            'Origin' => 'http://localhost:8080',
        ]);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testOriginsGivenToTheServerAreTaken(): void
    {
        $app = self::app(static function (): void {
            Mcp::server('/mcp')->tools(ListProducts::class)->origins('https://Inspector.example:443');
        });

        $response = self::send($app, 'tools/list', self::params(), ['Origin' => 'https://inspector.example']);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testTheServersMiddlewareGuardsIt(): void
    {
        $app = self::app(static function (): void {
            Mcp::server('/mcp')->tools(ShowProduct::class)->middleware(new RequireKey());
        });

        $refused = self::send($app, 'tools/list', self::params());
        $let = self::send($app, 'tools/list', self::params(), ['X-Key' => 'open']);

        self::assertSame(401, $refused->getStatusCode());
        self::assertSame(200, $let->getStatusCode());
    }

    public function testServersAtTwoPathsAreApart(): void
    {
        $app = self::app(static function (): void {
            Mcp::server('/mcp/a')->tools(ShowProduct::class);
            Mcp::server('/mcp/b')->tools(ListProducts::class);
        });

        $a = self::decode(self::send($app, 'tools/list', self::params(), path: '/mcp/a'));
        $b = self::decode(self::send($app, 'tools/list', self::params(), path: '/mcp/b'));

        self::assertSame(['show_product'], array_column($a['result']['tools'], 'name'));
        self::assertSame(['list_products'], array_column($b['result']['tools'], 'name'));
    }

    public function testACursorIsInvalidSinceListsAreWhole(): void
    {
        $response = self::send(self::app(), 'tools/list', self::params(['cursor' => 'abc']));

        self::assertSame(-32_602, self::decode($response)['error']['code']);
    }

    public function testTheTtlIsTheServersAndListsArePublic(): void
    {
        $app = self::app(static function (): void {
            Mcp::server('/mcp')->tools(ListProducts::class)->ttl(300_000);
        });

        $result = self::answer($app, 'tools/list');

        self::assertSame(300_000, $result['ttlMs']);
        self::assertSame('public', $result['cacheScope']);
    }
}
