<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Mermaid\Theme\JsObject;

/**
 * A document's merged family options, as the pinned renderers read `getConfig()`: the authored
 * value from front matter and init directives, else the pinned default. Values were checked
 * against MermaidOptionSchema where they were written.
 */
final readonly class MermaidOptions
{
    public function __construct(
        private \stdClass $document = new \stdClass(),
        private ?MermaidOptionSchema $schema = null,
    ) {}

    /** The pinned defaults alone, for layouts built outside a document. */
    public static function defaults(): self
    {
        return new self(new \stdClass(), MermaidOptionSchema::bundled());
    }

    public function value(string $family, string $key): mixed
    {
        // @mago-expect analysis:mixed-assignment -- A family section is narrowed to a map below.
        $section = JsObject::get($this->document, $family);

        return ($section instanceof \stdClass ? JsObject::get($section, $key) : null) ?? $this->schema?->default($family, $key);
    }

    /** A root option, such as `markdownAutoWrap`, else its pinned default. */
    public function root(string $key): mixed
    {
        return JsObject::get($this->document, $key) ?? $this->schema?->rootDefault($key);
    }

    public function flag(string $family, string $key): bool
    {
        return true === $this->value($family, $key);
    }

    /** A flag of a nested option object, such as `xyChart.xAxis.showLabel`. */
    public function nested(string $family, string $key, string $name): bool
    {
        // @mago-expect analysis:mixed-assignment -- An option object, narrowed below.
        $object = $this->value($family, $key);
        // @mago-expect analysis:mixed-assignment -- Narrowed to a flag below.
        $value = $object instanceof \stdClass ? JsObject::get($object, $name) : null;
        // The merged document keeps only authored nested keys; the rest come from the pinned default.
        // @mago-expect analysis:mixed-assignment -- A default option object, narrowed below.
        $default = $this->schema?->default($family, $key);

        return true === ($value ?? (\is_array($default) ? $default[$name] ?? null : null));
    }

    public function number(string $family, string $key, float $fallback = 0.0): float
    {
        // @mago-expect analysis:mixed-assignment -- Narrowed to a finite number below.
        $value = self::numeric($this->value($family, $key));

        return (\is_int($value) || \is_float($value)) && \is_finite((float) $value) ? (float) $value : $fallback;
    }

    public function text(string $family, string $key): string
    {
        // @mago-expect analysis:mixed-assignment -- Narrowed to text below.
        $value = $this->value($family, $key);

        return \is_string($value) ? $value : '';
    }

    /**
     * A numeric string as the number JavaScript arithmetic reads, as pinned options take
     * `rankSpacing: '100'`; any other value as it is.
     */
    public static function numeric(mixed $value): mixed
    {
        if (!\is_string($value) || !\is_numeric($value)) {
            return $value;
        }
        $number = (float) $value;

        return \floor($number) === $number && \abs($number) < \PHP_INT_MAX ? (int) $number : $number;
    }
}
