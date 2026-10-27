<?php

declare(strict_types=1);

namespace Porta\Widget\Support;

use Porta\Support\IntMath;
use Symfony\Component\Tui\Input\Key;

/**
 * A read-only pane that scrolls whole rows and has nothing else to do with
 * the keys: the large-file look and the markdown preview. It binds the
 * EDITOR's own navigation verbs rather than minting ids of its own, so
 * rebinding "page down" moves it here too — which is the answer a user
 * rebinding page down means.
 *
 * The tab verbs are re-bound for the reason the help viewer re-binds them: the
 * code editor is detached while a pane owns the slot, so its keymap is not
 * listening.
 *
 * The host says how many rows there are ({@see rowCount()}) and records how
 * many it drew in {@see $bodyRows} as it renders.
 *
 * @require-extends \Porta\Widget\EditorPaneWidget
 */
trait ScrollsRows
{
    private int $scrollTop = 0;

    /** Body rows of the last render, so PageUp/PageDown move by what was on screen. */
    private int $bodyRows = 1;

    /** The rows there are to scroll through. */
    abstract private function rowCount(): int;

    /**
     * @return array<string, list<string>>
     */
    #[\Override]
    protected static function getDefaultKeybindings(): array
    {
        return [
            'cursor_up' => [Key::UP],
            'cursor_down' => [Key::DOWN],
            'page_up' => [Key::PAGE_UP],
            'page_down' => [Key::PAGE_DOWN],
            'document_start' => [Key::HOME],
            'document_end' => [Key::END],
            'next_buffer' => ['ctrl+right'],
            'prev_buffer' => ['ctrl+left'],
            'close_buffer' => ['ctrl+f4', 'alt+w'],
            'goto_file' => ['ctrl+shift+n', 'ctrl+p'],
        ];
    }

    #[\Override]
    public function handleInput(string $data): void
    {
        $kb = $this->getKeybindings();
        match (true) {
            $kb->matches($data, 'cursor_up') => $this->scrollBy(-1),
            $kb->matches($data, 'cursor_down') => $this->scrollBy(1),
            $kb->matches($data, 'page_up') => $this->scrollBy(-$this->bodyRows),
            $kb->matches($data, 'page_down') => $this->scrollBy($this->bodyRows),
            $kb->matches($data, 'document_start') => $this->scrollTo(0),
            $kb->matches($data, 'document_end') => $this->scrollTo(\PHP_INT_MAX),
            default => $this->tabKey($data),
        };
    }

    #[\Override]
    public function wheelBy(int $rows): void
    {
        $this->scrollBy($rows);
    }

    /** Move the view by $rows; the argument is a notch or a page, never a jump. */
    public function scrollBy(int $rows): void
    {
        $this->scrollTo($this->scrollTop + $rows);
    }

    /**
     * Put the view AT $top, clamped — which is how Home and End are expressed.
     *
     * Separate from {@see scrollBy()} rather than passing it `PHP_INT_MAX`, the
     * idiom the help viewer uses: `$scrollTop + PHP_INT_MAX` overflows to float
     * the moment the view is not already at the top, and the clamp below takes
     * an int. That is not theoretical — End after a PageDown is the ordinary
     * way to reach the bottom of a file, and it was a TypeError.
     */
    public function scrollTo(int $top): void
    {
        $this->scrollTop = $this->clampScroll($top);
        $this->invalidate();
    }

    /** The furthest the view may scroll: the last body-full of rows. */
    private function clampScroll(int $top): int
    {
        return IntMath::clamp($top, 0, \max(0, $this->rowCount() - $this->bodyRows));
    }
}
