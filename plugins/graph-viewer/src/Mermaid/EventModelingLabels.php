<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Examples are displayed as text; JSON, HTML, URIs and embedded DSLs never execute. */
final class EventModelingLabels
{
    public static function body(string $raw): string
    {
        $text = \trim($raw);

        return MermaidText::decode(\trim(\substr($text, offset: 1, length: -1)));
    }

    /**
     * Pinned box visuals: the theme's `em…Fill`/`em…Stroke`, else the renderer's own literals.
     *
     * @return array{string, string} fill and stroke tokens
     */
    public static function colors(string $role): array
    {
        [$name, $fill, $stroke] = match ($role) {
            'ui' => ['Ui', '#ffffff', '#dbdada'],
            'pcr', 'processor' => ['Processor', '#edb3f6', '#b88cbf'],
            'rmo', 'readmodel' => ['ReadModel', '#d3f1a2', '#a3b732'],
            'cmd', 'command' => ['Command', '#bcd6fe', '#679ac3'],
            default => ['Event', '#ffb778', '#c19a0f'],
        };

        return ['@em'.$name.'Fill|='.$fill, '@em'.$name.'Stroke|='.$stroke];
    }

    /** @return \Generator<int, null, mixed, list<array{text: string, origin: int}>> */
    public static function legend(EventModelingDiagram $source): \Generator
    {
        $legend = [];
        foreach ($source->metadata as $key => $statement) {
            if ('title' !== $key) {
                $legend[] = ['text' => \trim($statement->text), 'origin' => $statement->line];
            }
            yield null;
        }
        foreach ($source->frames as $frame) {
            $kind = $frame['reset'] ? 'rf' : 'tf';
            $type = null === $frame['dataType'] ? '' : ' ['.$frame['dataType'].']';
            $legend[] = ['text' => $kind.' '.$frame['name'].' '.$frame['modelEntityType'].' '.$frame['entityIdentifier'].$type, 'origin' => $frame['statement']->line];
            yield null;
        }
        foreach ($source->modelEntities as $entity) {
            $legend[] = ['text' => 'entity '.$entity['name'], 'origin' => $entity['statement']->line];
            yield null;
        }
        foreach ($source->dataEntities as $data) {
            $legend[] = ['text' => 'data '.$data['name'].': '.self::body($data['dataBlockValue']), 'origin' => $data['statement']->line];
            yield null;
        }
        foreach ($source->noteEntities as $note) {
            $legend[] = ['text' => 'note '.$note['sourceFrame'].': '.self::body($note['dataBlockValue']), 'origin' => $note['statement']->line];
            yield null;
        }
        foreach ($source->gwtEntities as $gwt) {
            foreach (['givenStatements' => 'Given', 'whenStatements' => 'When', 'thenStatements' => 'Then'] as $key => $label) {
                foreach ($gwt[$key] as $condition) {
                    $legend[] = ['text' => $gwt['sourceFrame'].' '.$label.' '.$condition['role'].' '.$condition['entityIdentifier'], 'origin' => $gwt['statement']->line];
                    yield null;
                }
            }
        }

        return $legend;
    }
}
