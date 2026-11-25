<?php

declare(strict_types=1);

namespace Crab\Mcp\Stdio;

use Crab\Contracts\Http\Factory;
use Crab\Contracts\Http\HttpError;
use Crab\Contracts\Http\Request;
use Crab\Mcp\Protocol\Json;
use Crab\Mcp\Protocol\McpError;
use Crab\Mcp\Protocol\Message;
use Crab\Mcp\Protocol\Server;

/**
 * The stdio transport: one JSON-RPC message to a line, read from the input
 * and answered on the output, in turn, until the input ends. Nothing but
 * messages is written to the output.
 *
 * Each message is read as the body of a request made for it, so that a
 * tool is called the way it is over HTTP and the framework's JSON reader
 * bounds what is decoded. No middleware runs: whoever started the process
 * is who calls, and a tool that takes a user is refused one.
 *
 * Messages are answered one at a time, so notifications/cancelled always
 * arrives after the request it names has been answered, and is ignored.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/stdio
 * @internal
 */
final readonly class StdioTransport
{
    /**
     * @param int $maxLine The longest message read, in bytes; a longer one is refused and skipped.
     */
    public function __construct(
        private Server $server,
        private Factory $factory,
        private int $maxLine,
    ) {}

    /**
     * @param resource $input
     * @param \Closure(string): void $write Writes one line of output, newline included.
     */
    public function run(mixed $input, \Closure $write): void
    {
        $skipping = false;
        while (true) {
            $line = stream_get_line($input, $this->maxLine + 1, "\n");
            if ($line === false) {
                return;
            }
            $long = strlen($line) > $this->maxLine;
            if ($skipping) {
                // The rest of a line already refused.
                $skipping = $long;
                continue;
            }
            if ($long) {
                $skipping = true;
                $write(
                    Json::encode(Server::error(null, McpError::parse("A message is at most {$this->maxLine} bytes.")))
                        . "\n",
                );
                continue;
            }
            $line = rtrim($line, "\r");
            if (trim($line) === '') {
                continue;
            }
            $this->answer($line, $write);
        }
    }

    /**
     * @param \Closure(string): void $write
     */
    private function answer(string $line, \Closure $write): void
    {
        $request = $this->factory
            ->createServerRequest('POST', $this->server->path)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream($line));
        if (!$request instanceof Request) {
            throw new \LogicException(
                'The HTTP factory makes requests that are not ' . Request::class . '; a tool is called with one.',
            );
        }
        try {
            try {
                $body = $request->getParsedBody();
            } catch (HttpError $error) {
                throw McpError::parse($error->status === 413 ? $error->getMessage() : 'The message is not valid JSON.');
            }
            if ($body === null) {
                // JSON that is not an object or a list is a value, not a message.
                throw json_validate($line)
                    ? McpError::invalidRequest('A message is one JSON-RPC object.')
                    : McpError::parse();
            }
            $message = Message::read($body, $line);
        } catch (McpError $error) {
            $write(Json::encode(Server::error(null, $error)) . "\n");
            return;
        }
        $reply = $this->server->handle($message, $request);
        if ($reply === null) {
            return;
        }
        if ($reply instanceof \Generator) {
            foreach ($reply as $notification) {
                $write(Json::encode($notification) . "\n");
            }
            $reply = $reply->getReturn();
        }
        $write(Json::encode($reply) . "\n");
    }
}
