<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid\Theme;

/**
 * The theme, look, layout and theme variables one diagram renders with, resolved as the pinned
 * `updateCurrentConfig` does: directives first (a diagram's own section before the top level),
 * then the pinned defaults. Variables are re-derived only when the theme was chosen.
 */
final readonly class MermaidAppearance
{
    public const array LOOKS = ['classic', 'handDrawn', 'neo'];
    /** Top-level keys that choose the picture's appearance rather than a family's options. */
    public const array KEYS = ['theme', 'themeVariables', 'look', 'layout', 'handDrawnSeed', 'fontFamily', 'altFontFamily'];
    /** Keys a family section may hold to choose its own appearance. */
    public const array SCOPED = ['theme', 'look', 'layout'];

    public function __construct(
        public string $theme,
        public string $look,
        public mixed $layout,
        public \stdClass $variables,
        /** The default theme's own variables, which several pinned families merge under the theme's. */
        public \stdClass $defaults = new \stdClass(),
        /**
         * `handDrawnSeed`. The pinned default of zero draws differently on every browser
         * render; native rendering stays reproducible and draws it as seed 1.
         */
        public int $seed = 1,
    ) {}

    /**
     * @param list<\stdClass> $directives Sanitized directives in source order.
     * @throws \InvalidArgumentException When the chosen theme cannot derive its colors.
     */
    public static function resolve(array $directives, string $section, MermaidThemes $themes): self
    {
        /** @var array{appearance: array<string, string>, sections: array<string, array<string, string>>} $facts Pinned bundled data. */
        $facts = \json_decode((string) \file_get_contents(MermaidDirective::RESOURCE), associative: true, flags: \JSON_THROW_ON_ERROR);
        $sum = new \stdClass();
        foreach ($directives as $directive) {
            $sum = JsObject::object(MermaidMerge::assign($sum, MermaidMerge::object($directive)));
        }
        $defaults = (object) [...$facts['appearance'], ...\array_map(static fn (array $values): \stdClass => (object) $values, $facts['sections'])];
        $layers = [$sum, ...\array_reverse($directives), $defaults];
        $theme = self::text(self::pick($layers, $section, key: 'theme'), fallback: 'default');
        // @mago-expect analysis:mixed-assignment -- Authored variables are JSON-schema values.
        $authored = MermaidMerge::copy(JsObject::get($sum, 'themeVariables'));
        $chosen = ThemeProgram::truthy(JsObject::get($sum, 'theme')) || 'default' !== $theme;
        // @mago-expect analysis:mixed-assignment -- Theme variables are JavaScript values, narrowed by JsObject::object().
        $variables = $chosen && \in_array($theme, MermaidThemes::NAMES, strict: true)
            ? $themes->variables($theme, JsObject::object(MermaidMerge::assign(new \stdClass(), $authored)))
            : MermaidMerge::assign($themes->variables('default'), $authored, depth: 1);

        return new self(
            $theme,
            self::text(self::pick($layers, $section, key: 'look'), fallback: 'classic'),
            self::pick($layers, $section, key: 'layout'),
            JsObject::object($variables),
            JsObject::object($themes->variables('default')),
            self::seed(self::pick($layers, $section, key: 'handDrawnSeed')),
        );
    }

    /** A family section without its appearance keys, which are not family options. */
    public static function options(mixed $section): mixed
    {
        if (!$section instanceof \stdClass) {
            return $section;
        }
        $options = MermaidMerge::object($section);
        foreach (self::SCOPED as $key) {
            JsObject::remove($options, $key);
        }

        return $options;
    }

    /**
     * Pinned `readAppearance` over each layer; a value this build cannot render is passed over.
     *
     * @param list<\stdClass> $layers
     */
    private static function pick(array $layers, string $section, string $key): mixed
    {
        $candidates = [];
        foreach ($layers as $layer) {
            // @mago-expect analysis:mixed-assignment -- A section is a map when it scopes an appearance; checked below.
            $scoped = JsObject::get($layer, $section);
            $candidates[] = $scoped instanceof \stdClass ? JsObject::get($scoped, $key) : null;
            $candidates[] = JsObject::get($layer, $key);
        }

        // The pinned defaults are the last layer, so some candidate is always usable.
        return \array_find($candidates, static fn (mixed $value): bool => null !== $value && self::usable($key, $value));
    }

    private static function usable(string $key, mixed $value): bool
    {
        return match ($key) {
            'theme' => 'null' === $value || \in_array($value, MermaidThemes::NAMES, strict: true),
            'look' => \in_array($value, self::LOOKS, strict: true),
            'handDrawnSeed' => \is_int($value) || (\is_float($value) && \is_finite($value)),
            default => true,
        };
    }

    /** The seed as roughjs reads it, `Math.imul` truncating it to 32 bits; zero becomes one. */
    private static function seed(mixed $value): int
    {
        $number = \is_int($value) || \is_float($value) ? (float) $value : 0.0;
        $whole = (int) \fmod($number < 0 ? \ceil($number) : \floor($number), num2: 4_294_967_296.0);

        return 0 === $whole ? 1 : $whole;
    }

    private static function text(mixed $value, string $fallback): string
    {
        return \is_string($value) ? $value : $fallback;
    }
}
