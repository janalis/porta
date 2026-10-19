<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Unicode branch prefixes become indentation without changing original byte offsets or lines. */
final class TreeViewBranches
{
    private ?int $segment = null;

    public function __construct(
        private readonly string $source,
    ) {
        $matches = [];
        if (0 === \preg_match_all('/^([│┃\t ]*)[├└┣┗]/mu', $source, $matches)) {
            return;
        }
        $this->segment = 4;
        foreach ($matches[1] as $prefix) {
            $column = \mb_strlen(\str_replace("\t", replace: '    ', subject: $prefix));
            if ($column > 0) {
                $this->segment = $column;
                break;
            }
        }
    }

    public function item(MermaidStatement $statement): ?MermaidStatement
    {
        if (null === $this->segment) {
            return $statement;
        }

        return $this->normalize($statement, $this->segment);
    }

    private function normalize(MermaidStatement $statement, int $segment): ?MermaidStatement
    {
        $match = [];
        if (1 === \preg_match('/^([│┃\t ]*)[├└┣┗][─━]*[\t ]*(.*)$/us', $statement->text, $match)) {
            $leading = \substr($this->source, $statement->offset - $statement->column, $statement->column);
            $column = \strlen(\str_replace("\t", replace: '    ', subject: $leading)) + \mb_strlen(\str_replace("\t", replace: '    ', subject: $match[1]));
            $depth = (int) \round($column / $segment) + 1;
            $prefix = \strlen($match[0]) - \strlen($match[2]);
            if ('' === $match[2]) {
                throw $statement->error('Empty tree-view branch');
            }

            return new MermaidStatement($match[2], $statement->offset + $prefix, $statement->line, $depth * 4);
        }

        return self::plain($statement);
    }

    private static function plain(MermaidStatement $statement): ?MermaidStatement
    {
        if (1 === \preg_match('/^[│┃─━\t ]+$/u', $statement->text)) {
            return null;
        }
        if ($statement->column > 0) {
            throw $statement->error('Unexpected indentation without box-drawing branches');
        }

        return $statement;
    }
}
