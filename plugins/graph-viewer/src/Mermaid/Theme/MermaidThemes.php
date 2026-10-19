<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid\Theme;

/**
 * Pinned `getThemeVariables()` for the eleven registered themes: the theme's constructor, then
 * `calculate(overrides)`. Programs are extracted from the pinned sources (MIT; licenses/mermaid.txt).
 */
final readonly class MermaidThemes
{
    public const string RESOURCE = __DIR__.'/../../../resources/mermaid-themes.json';
    public const array NAMES = ['default', 'base', 'dark', 'forest', 'neutral', 'neo', 'neo-dark', 'redux', 'redux-dark', 'redux-color', 'redux-dark-color'];

    /** @param array<string, array{calculate: string, constructor: list<mixed>, updateColors: list<mixed>}> $themes */
    private function __construct(
        private array $themes,
    ) {}

    public static function bundled(): self
    {
        /** @var array{themes: array<string, array{calculate: string, constructor: list<mixed>, updateColors: list<mixed>}>} $resource Pinned bundled data. */
        $resource = \json_decode((string) \file_get_contents(self::RESOURCE), associative: true, flags: \JSON_THROW_ON_ERROR);

        return new self($resource['themes']);
    }

    /**
     * @param \stdClass|null $overrides Authored themeVariables; null calculates the theme alone.
     * @throws \InvalidArgumentException When a variable the theme derives from is not a usable color.
     */
    public function variables(string $name, ?\stdClass $overrides = null): \stdClass
    {
        $theme = $this->themes[$name] ?? throw new \InvalidArgumentException('Unknown Mermaid theme '.$name);
        $self = new \stdClass();
        $program = new ThemeProgram($theme['updateColors']);
        $program->run($theme['constructor'], $self);
        if ('calculated' === $theme['calculate']) {
            self::forget($self);
        }
        if (null === $overrides) {
            $program->updateColors($self);

            return $self;
        }
        // @mago-expect analysis:mixed-assignment -- Overrides keep their authored JSON-schema values.
        foreach (JsObject::entries($overrides) as $key => $value) {
            JsObject::set($self, $key, $value);
        }
        $program->updateColors($self);
        // @mago-expect analysis:mixed-assignment -- Overrides keep their authored JSON-schema values.
        foreach (JsObject::entries($overrides) as $key => $value) {
            JsObject::set($self, $key, self::override(JsObject::get($self, $key), $value));
        }
        if ('gradient' === $theme['calculate'] && \property_exists($overrides, 'nodeBorder') && !\property_exists($overrides, 'useGradient')) {
            $self->useGradient = false;
        }

        return $self;
    }

    /** The default theme marks variables `calculated` so that its calculation derives them. */
    private static function forget(\stdClass $self): void
    {
        // @mago-expect analysis:mixed-assignment -- The sentinel test compares any value.
        foreach (JsObject::entries($self) as $key => $value) {
            if ('calculated' !== $value) {
                continue;
            }
            JsObject::remove($self, $key);
        }
    }

    /** An object-valued variable keeps the keys the theme generated beneath the user's. */
    private static function override(mixed $generated, mixed $authored): mixed
    {
        return $generated instanceof \stdClass && $authored instanceof \stdClass
            ? (object) \array_replace(JsObject::values($generated), JsObject::values($authored))
            : $authored;
    }
}
