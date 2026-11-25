<?php

declare(strict_types=1);

namespace Crab\Mcp\Protocol;

/**
 * A resource's URI, fixed or a template of level 1 of RFC 6570: literal
 * text with {name} parts, each standing for one value that holds no "/",
 * "?" or "#". The other operators ({+path}, {?query}, ...) are refused
 * where the resource is declared, since a URI matched against them is not
 * one value to a name.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6570#section-1.2
 * @internal
 */
final readonly class UriTemplate
{
    /**
     * @param list<string> $names
     */
    private function __construct(
        public string $template,
        public array $names,
        private string $pattern,
    ) {}

    /**
     * @throws \InvalidArgumentException Saying what is wrong with the template.
     */
    public static function parse(string $template): self
    {
        if ($template === '' || preg_match('/[\s\x00-\x1F\x7F]/', $template) === 1) {
            throw new \InvalidArgumentException(
                'A resource\'s URI is text with no spaces, such as shop://products/{id}.',
            );
        }
        if (preg_match('~^[A-Za-z][A-Za-z0-9+.\-]*:~', $template) !== 1) {
            throw new \InvalidArgumentException(
                'A resource\'s URI starts with its scheme, such as shop:// or file://.',
            );
        }
        $parts = preg_split('/(\{[^{}]*\})/', $template, flags: PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $names = [];
        $pattern = '';
        foreach ($parts as $index => $part) {
            if (($index % 2) === 0) {
                if (str_contains($part, '{') || str_contains($part, '}')) {
                    throw new \InvalidArgumentException('A resource\'s URI has a brace that opens no {name}.');
                }
                $pattern .= preg_quote($part, '~');
                continue;
            }
            $name = substr($part, 1, -1);
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                throw new \InvalidArgumentException(
                    "A resource's URI has {$part}; its parts are {name}, a parameter of handle(), and the template operators of RFC 6570 beyond level 1 are not read.",
                );
            }
            if (in_array($name, $names, true)) {
                throw new \InvalidArgumentException("A resource's URI has {{$name}} twice.");
            }
            $names[] = $name;
            $pattern .= "(?<{$name}>[^/?#]+)";
        }
        return new self($template, $names, "~^{$pattern}\\z~");
    }

    public function isTemplate(): bool
    {
        return $this->names !== [];
    }

    /**
     * The values of the URI's parts, decoded, or null when the URI is not
     * one of this template's.
     *
     * @return array<string, string>|null
     */
    public function match(string $uri): ?array
    {
        if (!$this->isTemplate()) {
            return $uri === $this->template ? [] : null;
        }
        if (preg_match($this->pattern, $uri, $matches) !== 1) {
            return null;
        }
        $values = [];
        foreach ($this->names as $name) {
            $values[$name] = rawurldecode($matches[$name]);
        }
        return $values;
    }
}
