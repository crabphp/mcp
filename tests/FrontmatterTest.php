<?php

declare(strict_types=1);

namespace Crab\Mcp\Tests;

use Crab\Mcp\Skills\Frontmatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FrontmatterTest extends TestCase
{
    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function documents(): iterable
    {
        yield 'plain fields' => [
            "name: a\ndescription: Does things.",
            ['name' => 'a', 'description' => 'Does things.'],
        ];
        yield 'core schema scalars' => [
            "a: true\nb: False\nc: ~\nd: 12\ne: -1.5\nf: 0x1F\ng: 1e3\nh: 007\ni: yes\nj:",
            [
                'a' => true,
                'b' => false,
                'c' => null,
                'd' => 12,
                'e' => -1.5,
                'f' => 31,
                'g' => 1000.0,
                'h' => 7,
                'i' => 'yes',
                'j' => null,
            ],
        ];
        yield 'quoted' => [
            "a: 'it''s'\nb: \"tab\\there \\u00e9\"\nc: \"# not a comment\" # a comment\nd: '12'",
            ['a' => "it's", 'b' => "tab\there é", 'c' => '# not a comment', 'd' => '12'],
        ];
        yield 'comments' => ["# heading\nname: a # trailing\nurl: http://x#y", ['name' => 'a', 'url' => 'http://x#y']];
        yield 'nested mapping' => [
            "metadata:\n  owner: me\n  deep:\n    level: 2",
            ['metadata' => ['owner' => 'me', 'deep' => ['level' => 2]]],
        ];
        yield 'block sequence' => ["tools:\n  - Read\n  - Write", ['tools' => ['Read', 'Write']]];
        yield 'sequence at the key\'s indentation' => [
            "tools:\n- Read\n- Write\nafter: 1",
            ['tools' => ['Read', 'Write'], 'after' => 1],
        ];
        yield 'sequence of mappings' => [
            "items:\n  - name: a\n    size: 1\n  - name: b",
            ['items' => [['name' => 'a', 'size' => 1], ['name' => 'b']]],
        ];
        yield 'flow sequence' => ['tags: [a, "b, c", 3, \'d\']', ['tags' => ['a', 'b, c', 3, 'd']]];
        yield 'empty flow sequence' => ['tags: []', ['tags' => []]];
        yield 'folded plain scalar' => [
            "description: Fill and\n  merge PDF\n\n  forms.",
            ['description' => "Fill and merge PDF\nforms."],
        ];
        yield 'literal block' => [
            "text: |\n  one\n    two\n\n  three\nnext: x",
            ['text' => "one\n  two\n\nthree\n", 'next' => 'x'],
        ];
        yield 'folded block, stripped' => ["text: >-\n  one\n  two\n\n  three\n", ['text' => "one two\nthree"]];
        yield 'literal block, kept' => ["text: |+\n  one\n", ['text' => "one\n\n"]];
        yield 'quoted over lines' => ["description: \"Fill and\n  merge\"", ['description' => 'Fill and merge']];
        yield 'quoted key' => ['"a key": 1', ['a key' => 1]];
        yield 'colon in a value' => ['allowed-tools: Bash(git:*) Read', ['allowed-tools' => 'Bash(git:*) Read']];
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('documents')]
    public function testItReadsWhatSkillsAreWrittenIn(string $yaml, array $expected): void
    {
        self::assertSame($expected, Frontmatter::read("---\n{$yaml}\n---\n# Body\n", 'SKILL.md'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refused(): iterable
    {
        yield 'an anchor' => ['a: &x 1', 'line 2'];
        yield 'an alias' => ["a: 1\nb: *x", 'line 3'];
        yield 'a tag' => ['a: !!str 1', 'line 2'];
        yield 'a flow mapping' => ['a: {b: 1}', 'line 2'];
        yield 'a repeated key' => ["a: 1\na: 2", 'given twice'];
        yield 'a tab' => ["a:\n\tb: 1", 'tabs'];
        yield 'a stray indentation' => ["a: 1\n  b: 2", 'line 3'];
        yield 'an unclosed quote' => ['a: "open', 'not closed'];
        yield 'a list for fields' => ['- a', 'not a list'];
        yield 'a bad escape' => ['a: "\\q"', 'not an escape'];
    }

    #[DataProvider('refused')]
    public function testItRefusesWhatItDoesNotReadByLine(string $yaml, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Frontmatter::read("---\n{$yaml}\n---\n", 'SKILL.md');
    }

    public function testTheFrontmatterMustBeClosed(): void
    {
        $this->expectExceptionMessage('not closed by a line "---"');

        Frontmatter::read("---\nname: a\n", 'SKILL.md');
    }

    public function testCarriageReturnsAndAByteOrderMarkAreRead(): void
    {
        self::assertSame(['name' => 'a'], Frontmatter::read("\u{FEFF}---\r\nname: a\r\n---\r\n", 'SKILL.md'));
    }
}
