<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphNode;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Pinned `ClassMember`: a member's display text and classifier. A method is a member with a `)`
 * after its first character; `~T~` generics read `<T>`, a return type follows ` : `, and a
 * trailing `$` (static) underlines the member while `*` (abstract) sets it in italics.
 */
final class ClassMembers
{
    private const array VISIBILITIES = ['#', '+', '~', '-'];

    /** @return array{string, string} the display text and its classifier: `$`, `*` or none */
    public static function display(string $member): array
    {
        $member = \trim($member);

        return \str_contains(\substr($member, offset: 1), needle: ')') ? self::method($member) : self::attribute($member);
    }

    /** Pinned `parseGenericTypes`: each set's outermost `~` pairs become angle brackets. */
    public static function generics(string $text): string
    {
        $split = \preg_split('/(,)/', $text, flags: \PREG_SPLIT_DELIM_CAPTURE);
        $sets = false === $split ? [$text] : $split;
        /** @var list<string> $output */
        $output = [];
        $count = \count($sets);
        for ($index = 0; $index < $count; ++$index) {
            $set = $sets[$index];
            if (',' === $set && $index > 0 && ($index + 1) < $count && 1 === \substr_count($sets[$index - 1], needle: '~') && 1 === \substr_count($sets[$index + 1], needle: '~')) {
                $set = $sets[$index - 1].','.$sets[++$index];
                \array_pop($output);
            }
            $output[] = self::set($set);
        }

        return \implode('', $output);
    }

    /**
     * The underlines of a card's static members: `;u=<width>=<line>,…` after its divider rows,
     * the members padded to `width` columns about the card's center.
     *
     * @return list<MermaidMark>
     */
    public static function underlines(GraphNode $node, string $shape): array
    {
        $match = [];
        if (1 !== \preg_match('/;u=(\d+)=([\d,]+)/', $shape, $match)) {
            return [];
        }
        $lines = \explode("\n", $node->drawLabel ?? $node->label);
        $left = $node->x - ((int) $match[1] / 2);
        $top = $node->y - ($node->h / 2);
        $marks = [];
        foreach (\explode(',', $match[2]) as $line) {
            $width = AnsiUtils::visibleWidth(\rtrim($lines[(int) $line] ?? '', characters: ' '));
            $y = $top + 3.8 + (2 * (int) $line);
            $marks[] = new MermaidMark('polyline', [[$left, $y], [$left + $width, $y]], stroke: $node->textColor ?? '#333333', strokeWidth: 0.08);
        }

        return $marks;
    }

    /** @return array{string, string} */
    private static function method(string $member): array
    {
        $match = [];
        \preg_match('/^([#+~-])?(.+)\((.*)\)([\s$*])?(.*?)([$*])?$/s', $member, $match);
        $classifier = \trim($match[4] ?? '');
        // A classifier right after `)` wins; otherwise the return type's last `$` or `*` is one.
        $return = \trim(($match[5] ?? '').('' === $classifier ? '' : $match[6] ?? ''));
        $classifier = '' === $classifier ? $match[6] ?? '' : $classifier;
        $text = ($match[1] ?? '').self::generics(self::id($match[2] ?? '')).'('.self::generics(\trim($match[3] ?? '')).')'.('' === $return ? '' : ' : '.self::generics($return));

        return [\trim($text), $classifier];
    }

    /** @return array{string, string} */
    private static function attribute(string $member): array
    {
        $visibility = \in_array($member[0] ?? '', self::VISIBILITIES, strict: true) ? $member[0] : '';
        $classifier = '' !== $member && \in_array($member[\strlen($member) - 1], ['$', '*'], strict: true) ? $member[\strlen($member) - 1] : '';
        $id = \substr($member, \strlen($visibility), \strlen($member) - \strlen($visibility) - \strlen($classifier));

        return [\trim($visibility.self::generics(self::id($id))), $classifier];
    }

    /** Pinned ids keep one leading space, so `+ name` reads with its gap. */
    private static function id(string $id): string
    {
        return (\str_starts_with($id, ' ') ? ' ' : '').\trim($id);
    }

    /** Pinned `processSet`: matching `~` from both ends turn into `<` and `>`; a lone leading one stays. */
    private static function set(string $input): string
    {
        $tildes = \substr_count($input, needle: '~');
        if ($tildes <= 1) {
            return $input;
        }
        $leading = 0 !== ($tildes % 2) && \str_starts_with($input, '~');
        $chars = \mb_str_split($leading ? \substr($input, offset: 1) : $input);
        $first = \array_search('~', $chars, strict: true);
        $last = \array_search('~', \array_reverse($chars, preserve_keys: true), strict: true);
        while (false !== $first && false !== $last && $first !== $last) {
            $chars[$first] = '<';
            $chars[$last] = '>';
            $first = \array_search('~', $chars, strict: true);
            $last = \array_search('~', \array_reverse($chars, preserve_keys: true), strict: true);
        }

        return ($leading ? '~' : '').\implode('', $chars);
    }
}
