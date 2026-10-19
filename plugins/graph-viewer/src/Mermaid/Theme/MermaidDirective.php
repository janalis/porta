<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid\Theme;

/**
 * One configuration directive as the pinned `addDirective` stores it: sanitizeDirective, the
 * fontFamily-to-themeVariables rule, then sanitize. Unknown keys are dropped, never errors.
 */
final readonly class MermaidDirective
{
    public const string RESOURCE = __DIR__.'/../../../resources/mermaid-config.json';
    private const string VARIABLE = '/^[\d "#%(),.;A-Za-z]+$/';
    private const array DICTIONARIES = [
        'nodeColors' => '/^#[\da-f]{3,8}$|^rgb\([\d\s%,.]+\)$|^hsl\([\d\s%,.]+\)$|^[a-z]+$/i',
        'filenameIcons' => '/^[\w-]+(?::[\w-]+)?$/',
        'extensionIcons' => '/^[\w-]+(?::[\w-]+)?$/',
    ];

    /**
     * @param array<string, true> $keys
     * @param list<string> $secure
     */
    private function __construct(
        private array $keys,
        private array $secure,
    ) {}

    public static function bundled(): self
    {
        /** @var array{configKeys: list<string>, secure: list<string>} $facts Pinned bundled data. */
        $facts = \json_decode((string) \file_get_contents(self::RESOURCE), associative: true, flags: \JSON_THROW_ON_ERROR);

        return new self(\array_fill_keys($facts['configKeys'], value: true), $facts['secure']);
    }

    /**
     * The one directive pinned `preprocessDiagram` hands to `addDirective`: several init directives
     * are sanitized and merged with assignWithDepth (a single one stays raw), an init `config` moves
     * to the diagram type's key, and lodash merges the front matter config beneath the result.
     *
     * @param list<\stdClass> $inits
     * @throws \InvalidArgumentException When a CSS option is not text, which upstream cannot iterate.
     */
    public function document(?\stdClass $frontMatter, array $inits, string $type): \stdClass
    {
        $results = 1 === \count($inits) ? MermaidMerge::object($inits[0]) : new \stdClass();
        if (\count($inits) > 1) {
            foreach ($inits as $init) {
                $copy = MermaidMerge::object($init);
                $this->directive($copy);
                $results = JsObject::object(MermaidMerge::assign($results, $copy));
            }
        }
        if (JsObject::has($results, 'config')) {
            JsObject::set($results, 'flowchart-v2' === $type ? 'flowchart' : $type, JsObject::get($results, 'config'));
            JsObject::remove($results, 'config');
        }
        // @mago-expect analysis:mixed-assignment -- A merged object, narrowed by JsObject::object() below.
        $base = MermaidMerge::deep(new \stdClass(), MermaidMerge::object($frontMatter ?? new \stdClass()));

        return $this->sanitize(JsObject::object(MermaidMerge::deep($base, $results)));
    }

    /** @throws \InvalidArgumentException When a CSS option is not text, which upstream cannot iterate. */
    public function sanitize(\stdClass $directive): \stdClass
    {
        $copy = MermaidMerge::object($directive);
        $this->directive($copy);
        // @mago-expect analysis:mixed-assignment -- Only its truthiness is tested, as upstream.
        $fontFamily = JsObject::get($copy, 'fontFamily');
        $variables = JsObject::object(JsObject::get($copy, 'themeVariables'));
        if (ThemeProgram::truthy($fontFamily) && !ThemeProgram::truthy(JsObject::get($variables, 'fontFamily'))) {
            $copy->themeVariables = (object) [...JsObject::values($variables), 'fontFamily' => $fontFamily];
        }
        $this->secure($copy);

        return $copy;
    }

    private function directive(\stdClass $object): void
    {
        // @mago-expect analysis:mixed-assignment -- Directive values are JSON-schema values.
        foreach (JsObject::entries($object) as $key => $value) {
            if (self::suspicious($key) || !\array_key_exists($key, $this->keys) || null === $value) {
                JsObject::remove($object, $key);
                continue;
            }
            if ($value instanceof \stdClass || \is_array($value)) {
                JsObject::set($object, $key, $this->nested($key, $value));
                continue;
            }
            if (\str_contains($key, 'themeCSS') || \str_contains($key, 'fontFamily') || \str_contains($key, 'altFontFamily')) {
                JsObject::set($object, $key, self::css($value));
            }
        }
        // @mago-expect analysis:mixed-assignment -- Narrowed to a map below.
        $variables = JsObject::get($object, 'themeVariables');
        if (!$variables instanceof \stdClass) {
            return;
        }
        // @mago-expect analysis:mixed-assignment -- Only text values are checked, as `val?.match` upstream.
        foreach (JsObject::entries($variables) as $key => $value) {
            if (!\is_string($value) || 1 === \preg_match(self::VARIABLE, $value)) {
                continue;
            }
            JsObject::set($variables, $key, '');
        }
    }

    /**
     * @param \stdClass|array<array-key, mixed> $value
     * @return \stdClass|array<array-key, mixed>
     */
    private function nested(string $key, \stdClass|array $value): \stdClass|array
    {
        $pattern = self::DICTIONARIES[$key] ?? null;
        $items = \is_array($value) ? $value : [$value];
        // Arrays are walked item by item; only their objects carry keys to sanitize.
        // @mago-expect analysis:mixed-assignment -- Array items are JSON-schema values; only objects are sanitized.
        foreach ($items as $item) {
            if (!$item instanceof \stdClass) {
                continue;
            }
            if (null === $pattern || \is_array($value)) {
                $this->directive($item);
                continue;
            }
            // @mago-expect analysis:mixed-assignment -- Dictionary entries are validated as text below.
            foreach (JsObject::entries($item) as $name => $entry) {
                if (!self::suspicious($name) && \is_string($entry) && 1 === \preg_match($pattern, $entry)) {
                    continue;
                }
                JsObject::remove($item, $name);
            }
        }

        return $value;
    }

    /** Pinned `sanitize`, at every level: secure keys, prototype keys and markup-bearing text never configure. */
    private function secure(\stdClass $object): void
    {
        foreach (['secure', ...$this->secure] as $key) {
            JsObject::remove($object, $key);
        }
        // @mago-expect analysis:mixed-assignment -- Directive values are JSON-schema values.
        foreach (JsObject::entries($object) as $key => $value) {
            if (\str_starts_with($key, '__') || (\is_string($value) && (\str_contains($value, '<') || \str_contains($value, '>') || \str_contains($value, 'url(data:')))) {
                JsObject::remove($object, $key);
                continue;
            }
            if ($value instanceof \stdClass) {
                $this->secure($value);
            }
        }
    }

    private static function suspicious(string $key): bool
    {
        return \str_starts_with($key, '__') || \str_contains($key, 'proto') || \str_contains($key, 'constr');
    }

    private static function css(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \InvalidArgumentException('Mermaid CSS options must be text');
        }
        $depth = 0;
        foreach (\mb_str_split($value) as $character) {
            $depth += match ($character) {
                '{' => 1,
                '}' => -1,
                default => 0,
            };
            if ($depth < 0) {
                break;
            }
        }

        return 0 === $depth ? $value : '{ /* ERROR: Unbalanced CSS */ }';
    }
}
