<?php

declare(strict_types=1);

namespace Crab\Mcp\Skills;

/**
 * Reads the YAML frontmatter a SKILL.md begins with. Frontmatter is a few
 * fields, so this reads the part of YAML they are written in and refuses
 * the rest by line, rather than misread it: a host compares what it
 * parses with what the server listed, field by field.
 *
 * Read: block mappings and sequences by indentation, "- key: value" items,
 * flow sequences of scalars ([a, "b"]), plain, single- and double-quoted
 * scalars, plain and quoted scalars continued on more indented lines, the
 * block scalars "|" and ">" with "-" or "+", and comments. Plain scalars
 * resolve as YAML 1.2's core schema does: null, true and false, integers
 * and floats, otherwise text. Refused: anchors, aliases, tags, flow
 * mappings, keys that are not text, repeated keys, and tabs as
 * indentation.
 *
 * @see https://agentskills.io/specification
 * @internal
 */
final class Frontmatter
{
    /**
     * @var list<array{int, int, string}> Each line still to read: its number, its indentation, its text after the indentation.
     */
    private array $lines = [];

    private int $at = 0;

    private function __construct(
        private readonly string $file,
    ) {}

    /**
     * The fields of the frontmatter at the head of a SKILL.md.
     *
     * @return array<string, mixed>
     * @throws \InvalidArgumentException Saying the file, the line and what is wrong.
     */
    public static function read(string $markdown, string $file): array
    {
        $reader = new self($file);
        $text = str_starts_with($markdown, "\u{FEFF}") ? substr($markdown, 3) : $markdown;
        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
        if (($lines[0] ?? null) !== '---') {
            throw $reader->problem(
                1,
                'A SKILL.md begins with YAML frontmatter: a line "---", the fields, and "---" again.',
            );
        }
        $end = null;
        foreach ($lines as $number => $line) {
            if (!($number > 0 && ($line === '---' || $line === '...'))) {
                continue;
            }

            $end = $number;
            break;
        }
        if ($end === null) {
            throw $reader->problem(1, 'The frontmatter is not closed by a line "---".');
        }
        for ($number = 1; $number < $end; $number++) {
            $line = $lines[$number];
            if (preg_match('/^( *)\t/', $line) === 1) {
                throw $reader->problem($number + 1, 'YAML is indented with spaces, not tabs.');
            }
            $content = ltrim($line, ' ');
            $reader->lines[] = [$number + 1, strlen($line) - strlen($content), $content];
        }
        $reader->skipBlank();
        if ($reader->at >= count($reader->lines)) {
            return [];
        }
        [$number, $indent] = $reader->lines[$reader->at];
        if ($indent !== 0) {
            throw $reader->problem($number, 'The frontmatter\'s fields start at the beginning of the line.');
        }
        $fields = $reader->block(0);
        $reader->skipBlank();
        if ($reader->at < count($reader->lines)) {
            throw $reader->problem(
                $reader->lines[$reader->at][0],
                'This line is indented less than the block it is in.',
            );
        }
        if (!is_array($fields) || $fields !== [] && array_is_list($fields)) {
            throw $reader->problem(2, 'The frontmatter is fields, "name: value", not a list or a value.');
        }
        return $fields;
    }

    /**
     * A mapping or a sequence whose entries are at this indentation.
     *
     * @return array<mixed>
     */
    private function block(int $indent): array
    {
        $this->skipBlank();
        $content = $this->lines[$this->at][2];
        return $content === '-' || str_starts_with($content, '- ') ? $this->sequence($indent) : $this->mapping($indent);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapping(int $indent): array
    {
        $mapping = [];
        while ($this->skipBlank() && $this->lines[$this->at][1] === $indent) {
            [$number, , $content] = $this->lines[$this->at];
            if ($content === '-' || str_starts_with($content, '- ')) {
                throw $this->problem($number, 'A list item stands where a field was expected.');
            }
            [$key, $rest] = $this->key($content, $number);
            if (array_key_exists($key, $mapping)) {
                throw $this->problem($number, "The field \"{$key}\" is given twice.");
            }
            $this->at++;
            $mapping[$key] = $this->value($rest, $indent, $number);
        }
        $this->deeper($indent);
        return $mapping;
    }

    /**
     * @return list<mixed>
     */
    private function sequence(int $indent): array
    {
        $sequence = [];
        while ($this->skipBlank() && $this->lines[$this->at][1] === $indent) {
            [$number, , $content] = $this->lines[$this->at];
            if ($content !== '-' && !str_starts_with($content, '- ')) {
                // The list under a key, at the key's own indentation, ends at the next key.
                break;
            }
            $rest = ltrim(substr($content, 1), ' ');
            if ($rest !== '' && !str_starts_with($rest, '#') && $this->isKey($rest)) {
                // "- key: value": a mapping whose first line begins after the dash.
                $column = $indent + strlen($content) - strlen($rest);
                $this->lines[$this->at] = [$number, $column, $rest];
                $sequence[] = $this->mapping($column);
                continue;
            }
            $this->at++;
            $sequence[] = $this->value($rest, $indent, $number);
        }
        $this->deeper($indent);
        return $sequence;
    }

    /**
     * What follows a key's colon or a list item's dash: a scalar on the
     * line, perhaps continued below, a block scalar, or a block below.
     */
    private function value(string $rest, int $indent, int $number): mixed
    {
        $rest = self::uncomment($rest);
        if ($rest === '') {
            if ($this->skipBlank() && $this->lines[$this->at][1] > $indent) {
                return $this->block($this->lines[$this->at][1]);
            }
            if (
                $this->skipBlank()
                && $this->lines[$this->at][1] === $indent
                && ($this->lines[$this->at][2] === '-' || str_starts_with($this->lines[$this->at][2], '- '))
                && $this->isKeyLine($number)
            ) {
                // A list under a key may sit at the key's own indentation.
                return $this->sequence($indent);
            }
            return null;
        }
        if (preg_match('/^([|>])([+-]?)(?:\s+#.*)?$/', $rest, $matches) === 1) {
            return $this->blockScalar($matches[1] === '>', $matches[2], $indent);
        }
        if ($rest[0] === '"' || $rest[0] === "'") {
            return $this->quoted($rest, $indent, $number);
        }
        if ($rest[0] === '[') {
            return $this->flow($rest, $number);
        }
        if (in_array($rest[0], ['&', '*', '!', '{', '%', '@', '`'], true)) {
            throw $this->problem(
                $number,
                "\"{$rest[0]}\" begins YAML that is not read here: anchors, aliases, tags and {flow} mappings. Write the value plainly or in quotes.",
            );
        }
        $text = $rest;
        // A plain scalar may go on over lines indented more than its key, joined by spaces.
        while ($this->at < count($this->lines)) {
            [$next, $nextIndent, $content] = $this->lines[$this->at];
            if ($content !== '' && $nextIndent <= $indent) {
                break;
            }
            if ($content === '') {
                $blank = $this->at;
                while ($blank < count($this->lines) && $this->lines[$blank][2] === '') {
                    $blank++;
                }
                if ($blank >= count($this->lines) || $this->lines[$blank][1] <= $indent) {
                    break;
                }
                $text .= str_repeat("\n", $blank - $this->at);
                $this->at = $blank;
                continue;
            }
            if (str_starts_with($content, '#')) {
                break;
            }
            if ($this->isKey($content)) {
                throw $this->problem($next, 'A field is indented under a value; a value has no fields of its own.');
            }
            $text .= (str_ends_with($text, "\n") ? '' : ' ') . self::uncomment($content);
            $this->at++;
        }
        return self::resolve($text);
    }

    /**
     * A "|" (literal) or ">" (folded) scalar: the lines indented below.
     */
    private function blockScalar(bool $folded, string $chomp, int $indent): string
    {
        $raw = [];
        $column = null;
        while ($this->at < count($this->lines)) {
            [$number, $lineIndent, $content] = $this->lines[$this->at];
            if ($content !== '') {
                if ($lineIndent <= $indent) {
                    break;
                }
                $column ??= $lineIndent;
                if ($lineIndent < $column) {
                    throw $this->problem($number, 'A line of a block scalar is indented less than its first.');
                }
            }
            $raw[] = $content === '' ? null : [$lineIndent, $content];
            $this->at++;
        }
        $lines = [];
        foreach ($raw as $line) {
            $lines[] = $line === null ? '' : str_repeat(' ', $line[0] - (int) $column) . $line[1];
        }
        $trailing = 0;
        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
            $trailing++;
        }
        if ($folded) {
            // A line break between two lines of text is a space; a blank line is one line break; indented lines keep theirs.
            $text = '';
            foreach ($lines as $index => $line) {
                $previous = $lines[$index - 1] ?? null;
                $text .= match (true) {
                    $previous === null, $previous === '' && $line !== '' => $line,
                    $line === '' => "\n",
                    str_starts_with($line, ' ') || str_starts_with($previous, ' ') => "\n" . $line,
                    default => ' ' . $line,
                };
            }
        } else {
            $text = implode("\n", $lines);
        }
        return match (true) {
            $lines === [] => $chomp === '+' ? str_repeat("\n", $trailing) : '',
            $chomp === '-' => $text,
            $chomp === '+' => $text . "\n" . str_repeat("\n", $trailing),
            default => $text . "\n",
        };
    }

    /**
     * A quoted scalar, on its line or over the lines below it.
     */
    private function quoted(string $rest, int $indent, int $number): string
    {
        $quote = $rest[0];
        $text = $rest;
        while (!self::closes($text, $quote)) {
            if ($this->at >= count($this->lines)) {
                throw $this->problem($number, "The value opened with {$quote} is not closed.");
            }
            [, $lineIndent, $content] = $this->lines[$this->at];
            if ($content !== '' && $lineIndent <= $indent) {
                throw $this->problem($number, "The value opened with {$quote} is not closed.");
            }
            $text .= $content === '' ? "\n" : (str_ends_with($text, "\n") ? '' : ' ') . $content;
            $this->at++;
        }
        $end = self::closing($text, $quote);
        $after = self::uncomment(substr($text, $end + 1));
        if ($after !== '') {
            throw $this->problem($number, 'Something follows the closing quote of a value.');
        }
        return $this->unquote(substr($text, 0, $end + 1), $number);
    }

    /**
     * A flow sequence of scalars on one line: [a, "b c", 3].
     *
     * @return list<mixed>
     */
    private function flow(string $rest, int $number): array
    {
        $rest = self::uncomment($rest);
        if (!str_ends_with($rest, ']')) {
            throw $this->problem($number, 'A [list] is closed with "]" on the line it opens.');
        }
        $inner = trim(substr($rest, 1, -1));
        if ($inner === '') {
            return [];
        }
        $items = [];
        $offset = 0;
        $length = strlen($inner);
        while ($offset <= $length) {
            while ($offset < $length && $inner[$offset] === ' ') {
                $offset++;
            }
            if ($offset < $length && ($inner[$offset] === '"' || $inner[$offset] === "'")) {
                $end = self::closing(substr($inner, $offset), $inner[$offset]);
                if ($end === -1) {
                    throw $this->problem($number, 'A value in the [list] is not closed.');
                }
                $items[] = $this->unquote(substr($inner, $offset, $end + 1), $number);
                $offset += $end + 1;
                while ($offset < $length && $inner[$offset] === ' ') {
                    $offset++;
                }
                if ($offset < $length && $inner[$offset] !== ',') {
                    throw $this->problem($number, 'Values in a [list] are separated by commas.');
                }
                $offset++;
                continue;
            }
            $comma = strpos($inner, ',', $offset);
            $item = trim($comma === false ? substr($inner, $offset) : substr($inner, $offset, $comma - $offset));
            if ($item === '' || preg_match('/^[\[\]{}&*!]/', $item) === 1) {
                throw $this->problem($number, 'A [list] holds plain or quoted values, separated by commas.');
            }
            $items[] = self::resolve($item);
            if ($comma === false) {
                break;
            }
            $offset = $comma + 1;
        }
        return $items;
    }

    /**
     * The key of a "key: value" line, and what follows its colon.
     *
     * @return array{string, string}
     */
    private function key(string $content, int $number): array
    {
        if ($content[0] === '"' || $content[0] === "'") {
            $end = self::closing($content, $content[0]);
            if ($end === -1 || ($content[$end + 1] ?? '') !== ':') {
                throw $this->problem($number, 'A quoted key is followed by ":".');
            }
            $rest = substr($content, $end + 2);
            if ($rest !== '' && $rest[0] !== ' ') {
                throw $this->problem($number, 'A key\'s ":" is followed by a space.');
            }
            return [$this->unquote(substr($content, 0, $end + 1), $number), ltrim($rest, ' ')];
        }
        if (preg_match('/^([^\s#:&*!|>\[\]{},%@`\'"][^:#]*?)\s*:(?: (.*))?$/', $content, $matches) !== 1) {
            if (in_array($content[0], ['&', '*', '!', '?'], true)) {
                throw $this->problem($number, 'Anchors, aliases, tags and complex keys are not read here.');
            }
            throw $this->problem($number, 'A field is written "name: value".');
        }
        return [$matches[1], $matches[2] ?? ''];
    }

    private function isKey(string $content): bool
    {
        if ($content[0] === '"' || $content[0] === "'") {
            $end = self::closing($content, $content[0]);
            return $end !== -1 && ($content[$end + 1] ?? '') === ':';
        }
        return preg_match('/^[^\s#:&*!|>\[\]{},%@`][^:#]*?\s*:(?: |$)/', $content) === 1;
    }

    /**
     * Whether the line of this number is a key with nothing after it.
     */
    private function isKeyLine(int $number): bool
    {
        foreach ($this->lines as [$line, , $content]) {
            if ($line === $number) {
                return $this->isKey($content);
            }
        }
        return false;
    }

    private function unquote(string $quoted, int $number): string
    {
        $inner = substr($quoted, 1, -1);
        if ($quoted[0] === "'") {
            return str_replace("''", "'", self::fold($inner));
        }
        $inner = self::fold($inner);
        $text = '';
        $length = strlen($inner);
        for ($i = 0; $i < $length; $i++) {
            if ($inner[$i] !== '\\') {
                $text .= $inner[$i];
                continue;
            }
            $escape = $inner[++$i] ?? '';
            $simple = [
                '0' => "\0",
                'a' => "\x07",
                'b' => "\x08",
                't' => "\t",
                "\t" => "\t",
                'n' => "\n",
                'v' => "\x0B",
                'f' => "\x0C",
                'r' => "\r",
                'e' => "\x1B",
                ' ' => ' ',
                '"' => '"',
                '/' => '/',
                '\\' => '\\',
                'N' => "\u{85}",
                '_' => "\u{A0}",
                'L' => "\u{2028}",
                'P' => "\u{2029}",
            ];
            if (isset($simple[$escape])) {
                $text .= $simple[$escape];
                continue;
            }
            $digits = ['x' => 2, 'u' => 4, 'U' => 8][$escape] ?? null;
            $hex = $digits === null ? '' : substr($inner, $i + 1, $digits);
            if ($digits === null || strlen($hex) !== $digits || !ctype_xdigit($hex)) {
                throw $this->problem($number, "\"\\{$escape}\" is not an escape YAML has.");
            }
            $text .= mb_chr((int) hexdec($hex), 'UTF-8');
            $i += $digits;
        }
        return $text;
    }

    /**
     * A quoted scalar's line breaks, as YAML folds them: one becomes a
     * space, and each further one a line feed.
     */
    private static function fold(string $text): string
    {
        return (
            preg_replace_callback(
                '/ *\n((?: *\n)*) */',
                static fn(array $match): string => $match[1] === ''
                    ? ' '
                    : str_repeat("\n", substr_count($match[1], "\n")),
                $text,
            ) ?? $text
        );
    }

    /**
     * Whether the quoted text opened at its start is closed in it.
     */
    private static function closes(string $text, string $quote): bool
    {
        return self::closing($text, $quote) !== -1;
    }

    /**
     * Where the quote opened at the start of the text closes, or -1.
     */
    private static function closing(string $text, string $quote): int
    {
        $length = strlen($text);
        for ($i = 1; $i < $length; $i++) {
            if ($quote === '"' && $text[$i] === '\\') {
                $i++;
                continue;
            }
            if ($text[$i] === $quote) {
                if ($quote === "'" && ($text[$i + 1] ?? '') === "'") {
                    $i++;
                    continue;
                }
                return $i;
            }
        }
        return -1;
    }

    /**
     * A plain scalar's text, as YAML 1.2's core schema reads it.
     */
    private static function resolve(string $text): mixed
    {
        return match (true) {
            in_array($text, ['', '~', 'null', 'Null', 'NULL'], true) => null,
            in_array($text, ['true', 'True', 'TRUE'], true) => true,
            in_array($text, ['false', 'False', 'FALSE'], true) => false,
            preg_match('/^[-+]?[0-9]+$/', $text) === 1 && self::fitsInt($text) => (int) $text,
            preg_match('/^0o[0-7]+$/', $text) === 1 => (int) octdec(substr($text, 2)),
            preg_match('/^0x[0-9a-fA-F]+$/', $text) === 1 => (int) hexdec(substr($text, 2)),
            preg_match('/^[-+]?(\.[0-9]+|[0-9]+(\.[0-9]*)?)([eE][-+]?[0-9]+)?$/', $text) === 1 => (float) $text,
            default => $text,
        };
    }

    private static function fitsInt(string $text): bool
    {
        $digits = ltrim($text, '+-');
        $digits = ltrim($digits, '0') === '' ? '0' : ltrim($digits, '0');
        $max = (string) PHP_INT_MAX;
        return strlen($digits) < strlen($max) || strlen($digits) === strlen($max) && strcmp($digits, $max) <= 0;
    }

    /**
     * The text before a comment: a "#" at the start or after a space.
     */
    private static function uncomment(string $text): string
    {
        if (str_starts_with($text, '#')) {
            return '';
        }
        $quote = null;
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if ($quote !== null) {
                if ($quote === '"' && $char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if (($char === '"' || $char === "'") && ($i === 0 || in_array($text[$i - 1], [' ', '[', ','], true))) {
                $quote = $char;
            } elseif ($char === '#' && $text[$i - 1] === ' ') {
                return rtrim(substr($text, 0, $i));
            }
        }
        return rtrim($text);
    }

    /**
     * Moves past blank and comment lines; whether a line remains.
     */
    private function skipBlank(): bool
    {
        while (
            $this->at < count($this->lines)
            && ($this->lines[$this->at][2] === '' || str_starts_with($this->lines[$this->at][2], '#'))
        ) {
            $this->at++;
        }
        return $this->at < count($this->lines);
    }

    /**
     * Refuses a line indented more than the block it ends, which belongs
     * to nothing.
     */
    private function deeper(int $indent): void
    {
        if ($this->skipBlank() && $this->lines[$this->at][1] > $indent) {
            throw $this->problem(
                $this->lines[$this->at][0],
                'This line is indented more than the field above it holds.',
            );
        }
    }

    private function problem(int $line, string $message): \InvalidArgumentException
    {
        return new \InvalidArgumentException("{$this->file}, line {$line}: {$message}");
    }
}
