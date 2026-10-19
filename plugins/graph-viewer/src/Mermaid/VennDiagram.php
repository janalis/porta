<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Parser records preserve declarations; synthesized pair overlaps belong only to layout. */
final class VennDiagram
{
    /** @var list<array{sets: non-empty-list<string>, size: float, label: ?string, statement: MermaidStatement}> */
    public array $subsets = [];
    /** @var list<array{sets: non-empty-list<string>, id: string, label: ?string, statement: MermaidStatement}> */
    public array $texts = [];
    /** @var list<array{targets: non-empty-list<string>, styles: array<string, string>, statement: MermaidStatement}> */
    public array $styles = [];
    /** @var array<array-key, true> */
    public array $known = [];
    public ?MermaidStatement $title = null;
    public int $origin = 0;

    public static function normalize(string $text): string
    {
        $text = \trim($text);

        return \strlen($text) >= 2 && '"' === $text[0] && \str_ends_with($text, '"') ? \substr($text, offset: 1, length: -1) : $text;
    }

    /** @param non-empty-list<string> $ids
     * @return non-empty-list<string>
     */
    public static function identifiers(array $ids): array
    {
        $ids = \array_map(self::normalize(...), $ids);
        \usort($ids, static fn (string $a, string $b): int => \strcmp(
            (string) \mb_convert_encoding($a, to_encoding: 'UTF-16BE', from_encoding: 'UTF-8'),
            (string) \mb_convert_encoding($b, to_encoding: 'UTF-16BE', from_encoding: 'UTF-8'),
        ));

        return $ids;
    }

    /** @param list<string> $ids */
    public static function key(array $ids): string
    {
        return \json_encode($ids, flags: \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }

    /** @param non-empty-list<string> $ids */
    public function subset(array $ids, ?string $label, ?float $size, MermaidStatement $statement): void
    {
        $sets = self::identifiers($ids);
        if (1 === \count($sets)) {
            $this->known[$sets[0]] = true;
        }
        $this->subsets[] = ['sets' => $sets, 'size' => $size ?? (10 / (\count($ids) ** 2)), 'label' => null === $label || '' === $label ? null : self::normalize($label), 'statement' => $statement];
    }

    /** @param non-empty-list<string> $ids */
    public function validate(array $ids, MermaidStatement $statement): void
    {
        foreach (self::identifiers($ids) as $id) {
            if (!\array_key_exists($id, $this->known)) {
                throw $statement->error('Unknown Venn set identifier '.$id);
            }
        }
    }
}
