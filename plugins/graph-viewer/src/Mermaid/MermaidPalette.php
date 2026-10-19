<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphCluster;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\Theme\JsObject;
use Porta\GraphViewer\Mermaid\Theme\Khroma;
use Porta\GraphViewer\Mermaid\Theme\MermaidAppearance;
use Porta\GraphViewer\Mermaid\Theme\ThemeProgram;

/**
 * Theme variables as drawing values: colors become `#rrggbb` or `#rrggbbaa`, sizes become numbers.
 * Layouts may name a variable as a color token, `@variableName`, resolved once per picture.
 */
final readonly class MermaidPalette
{
    /** The native outline width, one pinned pixel. */
    private const float DEFAULT_WIDTH = 0.12;

    public function __construct(
        public MermaidAppearance $appearance,
        public MermaidRoles $roles = new MermaidRoles(),
    ) {}

    /** Unstyled elements take their family's theme roles; authored colors stay as written. */
    public function paint(GraphLayout $graph): GraphLayout
    {
        $roles = $this->roles;
        $neo = 'neo' === $this->appearance->look;
        // Pinned neo styles stroke every `[data-look="neo"].node` outline with the node border.
        $nodeStroke = $neo ? ['nodeBorder'] : $roles->nodeStroke;
        $width = $this->width();
        $nodes = [];
        foreach ($graph->nodes as $id => $node) {
            $nodes[$id] = $node->painted(
                $node->color ?? $this->color(...$nodeStroke),
                $node->fillColor ?? $this->color(...$roles->nodeFill),
                $node->textColor ?? $this->color(...$roles->nodeText),
            );
            // An outline at the default width takes the theme's; authored widths stay.
            $nodes[$id] = self::DEFAULT_WIDTH === $node->strokeWidth ? $nodes[$id]->stroked($width) : $nodes[$id];
        }
        $clusters = [];
        // Pinned neo styles stroke `[data-look="neo"].cluster rect` with the node border.
        $frameStroke = $neo && $roles->neoFrames ? ['nodeBorder'] : $roles->clusterStroke;
        foreach ($graph->clusters as $cluster) {
            $clusters[] = $cluster->painted(
                $cluster->color ?? $this->color(...$frameStroke),
                $cluster->fillColor ?? $this->color(...$roles->clusterFill),
                $cluster->textColor ?? $this->color(...$roles->clusterText),
            );
        }
        $edges = \array_map(fn (GraphEdge $edge): GraphEdge => $edge->painted(
            $edge->color ?? $this->color(...$roles->edge),
            $edge->textColor ?? $this->color(...$roles->edgeText),
        )->stroked(self::DEFAULT_WIDTH === $edge->strokeWidth ? $width : $edge->strokeWidth), $graph->edges);

        return new GraphLayout($graph->width, $graph->height, $nodes, $edges, $clusters);
    }

    /** The theme `strokeWidth` in native units, where the family's styles read it. */
    private function width(): float
    {
        $themed = 'theme' === $this->roles->widths || ('neo' === $this->roles->widths && 'neo' === $this->appearance->look);

        return $themed ? self::DEFAULT_WIDTH * $this->number('strokeWidth', default: 1.0) : self::DEFAULT_WIDTH;
    }

    /**
     * Shapes whose drawing needs theme colors: ER cards stripe their rows with `rowOdd` and
     * `rowEven`, which only the classic themes define; git commits draw their glyphs in a token.
     *
     * @param array<int, string> $shapes
     * @return array<int, string>
     */
    public function shapes(array $shapes): array
    {
        $odd = $this->color('rowOdd');
        $even = $this->color('rowEven');
        $stripes = null === $odd || null === $even ? null : '|'.$odd.','.$even;

        return \array_map(fn (string $shape): string => match (true) {
            null !== $stripes && \str_starts_with($shape, 'er-card:') => $shape.$stripes,
            (\str_starts_with($shape, 'git:') || \str_starts_with($shape, 'git-tag') || \str_starts_with($shape, 'tv-icon:')) && \str_contains($shape, '|@') => $this->glyph($shape),
            default => $shape,
        }, $shapes);
    }

    /** A git commit shape with its glyph token resolved. */
    private function glyph(string $shape): string
    {
        [$kind, $token] = \explode('|', $shape, limit: 2);

        return $kind.'|'.($this->resolve($token) ?? '#ffffff');
    }

    /** Pinned `labelBkg`: the caption background's channels at the family's opacity. */
    public function caption(): ?string
    {
        $color = $this->color(...$this->roles->caption);
        $alpha = (int) Khroma::round($this->roles->captionAlpha * 255);

        return null === $color || 255 === $alpha ? $color : \sprintf('%s%02x', \substr($color, offset: 0, length: 7), $alpha);
    }

    /** Layout color tokens become colors, for drawings and terminal cells alike. */
    public function resolveGraph(GraphLayout $graph): GraphLayout
    {
        return new GraphLayout(
            $graph->width,
            $graph->height,
            \array_map(fn (GraphNode $node): GraphNode => $node->painted($this->resolve($node->color), $this->resolve($node->fillColor), $this->resolve($node->textColor)), $graph->nodes),
            \array_map(fn (GraphEdge $edge): GraphEdge => $edge->painted($this->resolve($edge->color), $this->resolve($edge->textColor)), $graph->edges),
            \array_map(fn (GraphCluster $cluster): GraphCluster => $cluster->painted(
                $this->resolve($cluster->color),
                $this->resolve($cluster->fillColor),
                $this->resolve($cluster->textColor),
            ), $graph->clusters),
        );
    }

    /**
     * Pinned `colorThemeGate`: only redux-color themes with a border palette cycle colors.
     *
     * @return list<array{?string, ?string}> stroke and fill per slot
     */
    private function slots(): array
    {
        // @mago-expect analysis:mixed-assignment -- Palette arrays are theme values, narrowed below.
        $borders = JsObject::get($this->appearance->variables, 'borderColorArray');
        // @mago-expect analysis:mixed-assignment -- Palette arrays are theme values, narrowed below.
        $fills = JsObject::get($this->appearance->variables, 'bkgColorArray');
        $fills = \is_array($fills) && [] !== $fills ? \array_values($fills) : [null];
        if (!\is_array($borders) || [] === $borders || !\in_array($this->appearance->theme, ['redux-color', 'redux-dark-color'], strict: true)) {
            return [];
        }
        $slots = [];
        // @mago-expect analysis:mixed-assignment -- Palette entries are theme values, checked as text below.
        foreach (\array_values($borders) as $index => $border) {
            // @mago-expect analysis:mixed-assignment -- Palette entries are theme values, checked as text below.
            $fill = $fills[$index % \count($fills)];
            $slots[] = [\is_string($border) ? MermaidColor::css($border) : null, \is_string($fill) ? MermaidColor::css($fill) : null];
        }

        return $slots;
    }

    /** The hand-drawn look, for the families whose pinned renderers draw with roughjs. */
    public function sketch(): ?MermaidSketch
    {
        return 'handDrawn' === $this->appearance->look && $this->roles->rough ? new MermaidSketch($this->appearance->seed) : null;
    }

    /**
     * The neo look's drop shadow: the theme's CSS `drop-shadow()` or the redux filter (4px, 6% black).
     *
     * @return array{float, float, string}|null x and y offsets in pixels, and the shadow color
     */
    public function shadow(): ?array
    {
        // @mago-expect analysis:mixed-assignment -- Narrowed to text below.
        $value = JsObject::get($this->appearance->variables, 'dropShadow');
        $value = 'neo' === $this->appearance->look && $this->roles->shadows && \is_string($value) ? \trim($value) : '';
        $match = [];
        $css = 1 === \preg_match('/^drop-shadow\(\s*(-?[\d.]+)px\s+(-?[\d.]+)px\s+[\d.]+px\s+(.+?)\)\s*;?$/', $value, $match);
        $color = $css ? MermaidColor::css($match[3]) : null;

        return match (true) {
            \str_starts_with($value, 'url(') => [4.0, 4.0, '#0000000f'],
            null !== $color => [self::decimal($match[1]), self::decimal($match[2]), $color],
            default => null,
        };
    }

    /** The family's palette under the pinned defaults, for layouts built outside a document. */
    public static function defaults(string $kind): self
    {
        return new NativeMermaidDiagram($kind.' ', new MermaidConfiguration())->palette;
    }

    /** The first listed variable that holds a usable color; CSS ignores the others, as here. */
    public function color(string ...$variables): ?string
    {
        $color = null;
        foreach ($variables as $variable) {
            // A `=` prefix is a literal role value, such as `=none`.
            $color = \str_starts_with($variable, '=') ? \substr($variable, offset: 1) : self::usable($this->variable($variable));
            if (null !== $color) {
                break;
            }
        }

        return $color;
    }

    private static function usable(mixed $value): ?string
    {
        return \is_string($value) && ThemeProgram::truthy($value) ? MermaidColor::css($value) : null;
    }

    /**
     * A variable by name; `family.key` reads a nested family object, and `name[N]` entry N of
     * a comma-separated list, cycling, as xy chart plots read `plotColorPalette`. A nested key
     * the theme lacks comes from the default theme, as the pinned `cleanAndMerge(getThemeVariables(),
     * themeVariables)` of radar, xy, treemap and cynefin styles does; `^name` does the same
     * for a top-level key, as railroad's `{...getThemeVariables(), ...themeVariables}` spread does,
     * and `!family.key` reads the theme alone, as a renderer's inline attributes do.
     */
    private function variable(string $name): mixed
    {
        $match = [];
        \preg_match('/^([^\[]*)(?:\[(\d+)\])?$/', $name, $match);
        $spread = \str_starts_with($name, '^');
        $own = \str_starts_with($name, '!');
        $path = \explode('.', \ltrim($match[1] ?? $name, characters: '^!'));
        // @mago-expect analysis:mixed-assignment -- Theme values are JavaScript values, narrowed by the caller.
        $value = self::walk($this->appearance->variables, $path);
        if (null === $value && !$own && ($spread || \count($path) > 1)) {
            // @mago-expect analysis:mixed-assignment -- Theme values are JavaScript values, narrowed by the caller.
            $value = self::walk($this->appearance->defaults, $path);
        }
        $entry = $match[2] ?? null;

        return null !== $entry && \is_string($value) ? self::entry($value, (int) $entry) : $value;
    }

    /** Entry N of a comma-separated list, cycling. */
    private static function entry(string $list, int $index): string
    {
        $entries = \array_map(\trim(...), \explode(',', $list));

        return $entries[$index % \count($entries)];
    }

    /** @param list<string> $path */
    private static function walk(\stdClass $variables, array $path): mixed
    {
        $value = $variables;
        foreach ($path as $key) {
            // @mago-expect analysis:mixed-assignment -- Theme values are JavaScript values, narrowed by the caller.
            $value = $value instanceof \stdClass ? JsObject::get($value, $key) : null;
        }

        return $value;
    }

    /** A pixel or unitless size; anything else keeps the default, as an invalid declaration does. */
    public function number(string $variable, float $default): float
    {
        // @mago-expect analysis:mixed-assignment -- Theme values are JavaScript values, narrowed below.
        $value = JsObject::get($this->appearance->variables, $variable);
        $match = [];
        $text = \is_string($value) && 1 === \preg_match('/^\s*(-?(?:\d+\.?\d*|\.\d+))(?:px)?\s*$/', $value, $match);

        return match (true) {
            \is_int($value), \is_float($value) => \is_finite((float) $value) ? (float) $value : $default,
            $text => self::decimal($match[1]),
            default => $default,
        };
    }

    private static function decimal(string $digits): float
    {
        // @mago-expect analysis:invalid-type-cast -- The patterns above matched a decimal literal.
        return (float) $digits;
    }

    /**
     * A layout's color token: `@variable|fallback` names theme variables, and `@slot:stroke:N`
     * or `@slot:fill:N` a color theme's palette slot N; `@slot:fill:N:O` cycles N over the slots
     * from O upward, as agentflow containers do; `~variable` suffixes stand in when a color
     * theme's palette lacks the channel. Outside color themes a slot falls through to the
     * variables after it, or resolves to null. A trailing `@NN` applies an NN% opacity.
     */
    public function resolve(?string $color): ?string
    {
        $percent = [];

        return match (true) {
            null === $color || !\str_starts_with($color, '@') => $color,
            1 === \preg_match('/^(.+)@(\d{1,3})$/', $color, $percent) => MermaidColor::faded($this->resolve($percent[1]), (int) $percent[2] / 100),
            default => $this->token(\explode('|', \substr($color, offset: 1))),
        };
    }

    /** @param list<string> $chain a token's variables, or a slot followed by its fallbacks */
    private function token(array $chain): ?string
    {
        $match = [];
        if (1 !== \preg_match('/^slot:(stroke|fill):(\d+)(?::(\d+))?((?:~\w+)*)$/', $chain[0], $match)) {
            return $this->color(...$chain);
        }
        $slots = $this->slots();
        $count = \count($slots);
        $offset = (int) $match[3];
        $slot = $offset + ((int) $match[2] % \max(1, $count - $offset));
        // `~variable` stands in when a color theme's palette lacks this channel.
        $painted = 0 === $count ? null : $slots[$slot % $count]['stroke' === $match[1] ? 0 : 1] ?? $this->color(...\array_values(\array_filter(\explode('~', $match[4]))));

        return $painted ?? $this->color(...\array_slice($chain, offset: 1));
    }
}
