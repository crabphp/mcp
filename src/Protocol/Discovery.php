<?php

declare(strict_types=1);

namespace Crab\Mcp\Protocol;

use Crab\Mcp\ToolResult;

/**
 * Discovery for a server with many tools: tools/list gives a few tools
 * and two of discovery's own, one that searches the tools by words or by
 * group and one that calls a tool found, so that a model reads the
 * description of a tool when it needs it rather than of every tool
 * before it starts. "?tools=all" on the endpoint lists every tool, and
 * "?tools=content,analytics" those groups.
 *
 * A tool's group is its name's first word, before "_", or what the
 * server's aliases make of it.
 *
 * @internal
 */
final readonly class Discovery
{
    private const int MATCHES = 5;
    private const int MOST = 20;

    /**
     * @param string $search The name of the tool that searches.
     * @param string $call The name of the tool that calls.
     * @param list<string> $always Tools listed beside the two.
     * @param array<string, string> $aliases A name's first word, and the group it stands for: "error" => "errors".
     */
    public function __construct(
        public string $search,
        public string $call,
        public array $always,
        public array $aliases,
    ) {}

    public function group(string $tool): string
    {
        $first = explode('_', $tool)[0];
        return $this->aliases[$first] ?? $first;
    }

    /**
     * The groups the tools fall in, in order of name.
     *
     * @param list<ToolHandle> $tools
     * @return list<string>
     */
    public function groups(array $tools): array
    {
        $groups = array_values(array_unique(array_map(
            fn(ToolHandle $tool): string => $this->group($tool->info()->name),
            $tools,
        )));
        sort($groups);
        return $groups;
    }

    /**
     * The search and call tools, as tools/list sends them.
     *
     * @param list<string> $groups
     * @return list<array<string, mixed>>
     */
    public function definitions(array $groups): array
    {
        $named = $groups === [] ? '' : ' The groups are ' . implode(', ', $groups) . '.';
        $group = ['type' => 'string'];
        if ($groups !== []) {
            $group['enum'] = $groups;
        }
        return [
            [
                'name' => $this->search,
                'description' => "Find this server's tools before calling them with {$this->call}. Give query, words such as \"publish post\", for the best matches (5, or limit up to 20), or group for every tool of a group; each match has its name, description, inputSchema and readOnly. With neither, answers every group with its tools' names.{$named}",
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MOST],
                        'group' => $group,
                    ],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => true],
            ],
            [
                'name' => $this->call,
                'description' => "Call a tool found with {$this->search}: tool is its name, and arguments follow its inputSchema. Answers what the tool answers.",
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'tool' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
                        'arguments' => ['type' => 'object'],
                    ],
                    'required' => ['tool'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => false],
            ],
        ];
    }

    /**
     * The tools the search finds among those the caller may see.
     *
     * @param array<mixed> $arguments
     * @param list<ToolHandle> $visible
     * @return array<string, mixed>
     */
    public function search(array $arguments, array $visible): array
    {
        $query = $arguments['query'] ?? null;
        $group = $arguments['group'] ?? null;
        $limit = $arguments['limit'] ?? self::MATCHES;
        $unknown = array_diff(array_keys($arguments), ['query', 'group', 'limit']);
        if (
            $unknown !== []
            || $query !== null && !is_string($query)
            || $group !== null && !is_string($group)
            || !is_int($limit)
            || $limit < 1
            || $limit > self::MOST
        ) {
            return ToolResult::error('Give query or group as text, and limit from 1 to ' . self::MOST . '.')->toArray();
        }
        if ($group !== null) {
            $tools = array_values(array_filter(
                $visible,
                fn(ToolHandle $tool): bool => $this->group($tool->info()->name) === $group,
            ));
            return ToolResult::structured(['tools' => array_map(self::described(...), $tools)])->toArray();
        }
        if ($query === null || trim($query) === '') {
            $groups = [];
            foreach ($visible as $tool) {
                $groups[$this->group($tool->info()->name)][] = $tool->info()->name;
            }
            return ToolResult::structured(['groups' => $groups === [] ? new \stdClass() : $groups])->toArray();
        }
        $words = array_values(array_filter(
            preg_split('/[^a-z0-9]+/', strtolower($query)) ?: [],
            static fn(string $word): bool => strlen($word) > 1,
        ));
        $scored = [];
        foreach ($visible as $tool) {
            $name = str_replace('_', ' ', strtolower($tool->info()->name));
            $text = strtolower($tool->info()->description);
            $score = 0;
            $named = 0;
            foreach ($words as $word) {
                // A plural asks for what its singular names: "posts" finds content_post_show.
                $stem = rtrim($word, 's');
                $inName = str_contains($name, $stem);
                $named += $inName ? 1 : 0;
                $score += ($inName ? 3 : 0) + (str_contains($text, $stem) ? 1 : 0);
            }
            // A name holding every word is what was asked for.
            $score += $words !== [] && $named === count($words) ? 10 : 0;
            if ($score > 0) {
                $scored[] = [$score, $tool];
            }
        }
        // The best score first; between equals, the name with the fewest words besides those asked for,
        // so "errors list" finds errors_list before errors_keys_list.
        usort(
            $scored,
            static fn(array $a, array $b): int => (
                [$b[0], substr_count($a[1]->info()->name, '_'), $a[1]->info()->name] <=> [
                    $a[0],
                    substr_count($b[1]->info()->name, '_'),
                    $b[1]->info()->name,
                ]
            ),
        );
        // Loose matches far below the best one are noise.
        $best = $scored[0][0] ?? 0;
        $scored = array_values(array_filter($scored, static fn(array $pair): bool => ($pair[0] * 2) >= $best));
        $tools = array_map(static fn(array $pair): ToolHandle => $pair[1], array_slice($scored, 0, $limit));
        return ToolResult::structured(['tools' => array_map(self::described(...), $tools)])->toArray();
    }

    /**
     * The call tool's arguments, as the name and arguments of the tool it
     * calls.
     *
     * @param array<mixed> $arguments
     * @return array{string, array<mixed>}
     */
    public function unwrap(array $arguments): array
    {
        $tool = $arguments['tool'] ?? null;
        if (!is_string($tool)) {
            throw McpError::invalidParams("{$this->call} names the tool to call in tool, from {$this->search}.");
        }
        $inner = $arguments['arguments'] ?? [];
        if (!Json::isObject($inner)) {
            throw McpError::invalidParams('The arguments of the tool called are an object.');
        }
        return [$tool, $inner];
    }

    /**
     * @return array<string, mixed>
     */
    private static function described(ToolHandle $tool): array
    {
        $definition = $tool->definition();
        return [
            'name' => $definition['name'],
            'description' => $definition['description'],
            'inputSchema' => $definition['inputSchema'],
            'readOnly' => $tool->info()->readOnly === true,
        ];
    }
}
