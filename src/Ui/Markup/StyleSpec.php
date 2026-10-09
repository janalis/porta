<?php

declare(strict_types=1);

namespace Porta\Ui\Markup;

use Porta\Ui\Role;
use Symfony\Component\Tui\Exception\InvalidArgumentException;
use Symfony\Component\Tui\Style\Color;
use Symfony\Component\Tui\Style\Style;

/**
 * One markup tag, parsed: either a named style — Symfony Console's four
 * (`info`, `comment`, `question`, `error`) or any {@see Role} by its
 * {@see Role::tag()} — or Console's inline `fg=…;bg=…;options=…;href=…`.
 *
 * Parsing is theme-independent; {@see style()} takes the role's current Style,
 * so a theme switch recolors every tag on the next render. Colors are
 * symfony/tui's ({@see Color::from()}): Console's names with `bright-*` read as
 * tui's `bright_*`, `#hex`, and a 0–255 palette index. Options are Console's
 * plus tui's attributes; `blink` and `conceal` are accepted and dropped, since
 * a tui Style has neither.
 */
final readonly class StyleSpec
{
    /** Console's named styles, mapped onto the theme's roles by meaning and hue. */
    private const array CONSOLE_STYLES = [
        'info' => Role::Added,
        'comment' => Role::StatusWarning,
        'question' => Role::Emphasis,
        'error' => Role::StatusError,
    ];

    private const array OPTIONS = ['bold', 'underscore', 'underline', 'reverse', 'dim', 'italic', 'strikethrough', 'blink', 'conceal'];

    /** @param list<string> $options each one of {@see OPTIONS} */
    private function __construct(
        public ?Role $role,
        private ?Color $foreground = null,
        private ?Color $background = null,
        private array $options = [],
        public ?string $href = null,
    ) {}

    /** The spec a tag's body names, or null when Console would print the tag as text. */
    public static function fromTag(string $tag): ?self
    {
        $role = self::CONSOLE_STYLES[$tag] ?? Role::fromTag($tag);
        $pairs = [];

        return match (true) {
            null !== $role => new self($role),
            0 === \preg_match_all('/([^=]+)=([^;]+)(;|$)/', $tag, $pairs, \PREG_SET_ORDER) => null,
            default => self::fromPairs($pairs),
        };
    }

    /**
     * The spec `key=value` pairs name; null at the first key it does not
     * know or value it cannot read.
     *
     * @param array<array-key, array<array-key, string>> $pairs
     */
    private static function fromPairs(array $pairs): ?self
    {
        $foreground = null;
        $background = null;
        $options = [];
        $href = null;
        $valid = true;
        foreach ($pairs as [, $key, $value]) {
            switch (\strtolower($key)) {
                case 'fg':
                    $foreground = self::color($value);
                    $valid = null !== $foreground;
                    break;
                case 'bg':
                    $background = self::color($value);
                    $valid = null !== $background;
                    break;
                case 'href':
                    $href = \strtr($value, ['\\<' => '<', '\\>' => '>']);
                    break;
                case 'options':
                    $named = [];
                    \preg_match_all('/[^,;]+/', \strtolower($value), $named);
                    $valid = \array_all($named[0], static fn (string $option): bool => \in_array($option, self::OPTIONS, strict: true));
                    $options = [...$options, ...$named[0]];
                    break;
                default:
                    $valid = false;
            }
            if (!$valid) {
                break;
            }
        }

        return $valid ? new self(null, $foreground, $background, $options, $href) : null;
    }

    /**
     * This spec as a tui Style over $base (the role's Style, plain for an
     * inline tag): the inline colors — dropped when the theme has none, so
     * monochrome stays monochrome — then the options.
     */
    // @mago-expect lint:no-boolean-flag-parameter -- whether the theme paints colour IS the input, as Styles::hasColor() states it.
    public function style(Style $base, bool $withColor): Style
    {
        $style = $base;
        if ($withColor && null !== $this->foreground) {
            $style = $style->withColor($this->foreground);
        }
        if ($withColor && null !== $this->background) {
            $style = $style->withBackground($this->background);
        }
        foreach ($this->options as $option) {
            $style = match ($option) {
                'bold' => $style->withBold(),
                'underscore', 'underline' => $style->withUnderline(),
                'reverse' => $style->withReverse(),
                'dim' => $style->withDim(),
                'italic' => $style->withItalic(),
                'strikethrough' => $style->withStrikethrough(),
                default => $style, // blink, conceal: no tui attribute to set
            };
        }

        return $style;
    }

    private static function color(string $value): ?Color
    {
        $value = \strtolower($value);
        if (\ctype_digit($value)) {
            return (int) $value <= 255 ? Color::palette((int) $value) : null;
        }

        try {
            $color = Color::from(\str_replace(search: '-', replace: '_', subject: $value));
        } catch (InvalidArgumentException) {
            $color = null;
        }

        return $color;
    }
}
