<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Architecture terminals preserve Langium's ASCII IDs and distinct title/string escaping. */
final class ArchitectureText
{
    public const string ID = '[A-Za-z0-9_](?:[-A-Za-z0-9_]*[A-Za-z0-9_])?';
    public const string ICON = '\([\w:-]+\)';
    public const string TITLE = '\[(?:"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|[^\[\]\r\n]+)\]';

    public static function identifier(string $id, MermaidStatement $statement): string
    {
        if (1 === \preg_match('/^(?:[LRTB]|title)/', $id) || \in_array($id, ['group', 'service', 'junction', 'in', 'align', 'row', 'column', 'architecture-beta'], strict: true)) {
            throw $statement->error('Reserved architecture identifier '.$id);
        }

        return $id;
    }

    public static function title(string $text): string
    {
        $title = \trim(\substr($text, offset: 1, length: -1));
        if (('"' === ($title[0] ?? '') && \str_ends_with($title, '"')) || ("'" === ($title[0] ?? '') && \str_ends_with($title, "'"))) {
            $title = \str_replace(['\\"', "\\'"], replace: ['"', "'"], subject: \substr($title, offset: 1, length: -1));
        }

        return \trim($title);
    }
}
