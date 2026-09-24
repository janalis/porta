<?php

declare(strict_types=1);

namespace Porta\Ui\Markup;

/**
 * A stretch of text and every tag around it, outermost first — the flat shape
 * Symfony Console paints markup in: each run styled on its own, so a span that
 * closes gives back exactly the style around it, and a run cut anywhere is
 * still whole.
 */
final readonly class MarkupRun
{
    /** @param list<StyleSpec> $specs */
    public function __construct(
        public string $text,
        public array $specs,
    ) {}

    /**
     * The runs covering bytes [$start, $start + $length) of their joined text,
     * cut at both edges.
     *
     * @param list<MarkupRun> $runs
     *
     * @return list<MarkupRun>
     */
    public static function slice(array $runs, int $start, int $length): array
    {
        $sliced = [];
        $at = 0;
        foreach ($runs as $run) {
            $from = \max($start, $at);
            $to = \min($start + $length, $at + \strlen($run->text));
            if ($to > $from) {
                $sliced[] = new self(\substr($run->text, $from - $at, $to - $from), $run->specs);
            }
            $at += \strlen($run->text);
        }

        return $sliced;
    }
}
