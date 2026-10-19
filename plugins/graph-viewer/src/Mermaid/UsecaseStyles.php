<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Default and declaration classes precede top-level assignments and direct styles. */
final class UsecaseStyles
{
    /** @return \Generator<int, null, mixed, void> */
    public static function apply(UsecaseDiagram $source, MermaidGraph $graph): \Generator
    {
        foreach ($source->elements as $element) {
            if (\in_array($element->kind, ['boundary', 'edge'], strict: true)) {
                $graph->styles->assign($element->id, 'default', $element->statement);
            }
            if ([] !== $element->classes) {
                $graph->styles->assign($element->id, \implode(',', $element->classes), $element->statement);
            }
            yield null;
        }
        foreach ($source->styles as $statement) {
            self::validate($source, $statement);
            $graph->styles->accept($statement);
            yield null;
        }
    }

    private static function validate(UsecaseDiagram $source, MermaidStatement $statement): void
    {
        $match = [];
        \preg_match('/^(classDef|class|style)\s+/', $statement->text, $match);
        $kind = $match[1];
        $cursor = new UsecaseCursor($statement->slice(\strlen($match[0])));
        $ids = self::ids($cursor);
        if ('style' === $kind && \count($ids) > 1) {
            throw $statement->error('A use-case style declaration targets one identifier');
        }
        if ('classDef' !== $kind) {
            foreach ($ids as $id) {
                if (!\array_key_exists($id, $source->elements)) {
                    throw $statement->error('Unresolved use-case style target '.$id);
                }
            }
        }
        if ('class' === $kind) {
            self::ids($cursor);
            $cursor->finish();

            return;
        }
        if ('' === $cursor->rest()) {
            throw $statement->error('Expected use-case CSS declarations');
        }
    }

    /** @return list<string> */
    private static function ids(UsecaseCursor $cursor): array
    {
        $ids = [$cursor->identifier()];
        while ($cursor->consume(',')) {
            $ids[] = $cursor->identifier();
        }

        return $ids;
    }
}
