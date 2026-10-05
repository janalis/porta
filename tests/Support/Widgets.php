<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * A mounted widget as its own type. `Tui::getById()` answers the base
 * AbstractWidget, so every call a test chained onto it was a downcast an
 * analyser could not follow — and one a wrong id turned into "undefined
 * method" rather than a name.
 */
final class Widgets
{
    /** The editor pane's widget. */
    public static function editor(Tui $tui): CodeEditorWidget
    {
        $editor = $tui->getById('editor');
        if (!$editor instanceof CodeEditorWidget) {
            throw new \LogicException('No editor is mounted under the id "editor".');
        }

        return $editor;
    }

    /**
     * The widget mounted under $id, as $class.
     *
     * @template T of AbstractWidget
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public static function byId(Tui $tui, string $id, string $class): AbstractWidget
    {
        $widget = $tui->getById($id);
        if (!$widget instanceof $class) {
            throw new \LogicException(\sprintf('No %s is mounted under the id "%s".', $class, $id));
        }

        return $widget;
    }
}
