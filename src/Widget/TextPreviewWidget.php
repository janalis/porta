<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsIdeService;
use Porta\Container\AsWidget;
use Porta\Editor\TextPreview;
use Porta\I18n\Translator;
use Porta\Support\AnsiText;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Support\ScrollsRows;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * The read-only look at a file too large to edit.
 *
 * Over {@see \Porta\Support\Files::MAX_TEXT_BYTES} the IDE used to flash "File
 * too large (> 2 MB)" and do nothing else, so a composer.lock that had grown, a
 * log, a bundled asset or a dump was simply unopenable — you left the IDE to
 * look at it. It opens here instead: scrollable, never editable, with the top
 * line saying exactly what you are looking at.
 *
 * A PANE rather than a read-only buffer, which is the design decision worth
 * recording. "Read-only" would be a new state every mutation path in the editor
 * has to respect — the document's ten mutators, the save path, autosave, the
 * LSP didOpen — and one missed path silently lets you edit a file that cannot
 * be written back. A pane buffer already carries a permanently-empty document
 * that is never dirty with its cursor pinned at 0:0, so the guarantee comes
 * from the shape rather than from remembering to check a flag.
 *
 * It binds the EDITOR's own navigation verbs ({@see ScrollsRows}) rather than
 * minting `preview_*` ids of its own. The help viewer mints its own because it
 * has verbs of its own — links, history, find — and this has nothing but
 * scrolling.
 */
#[AsIdeService]
#[AsWidget('text-preview')]
final class TextPreviewWidget extends EditorPaneWidget
{
    use ScrollsRows;

    /** The routing id, matching the loader registered in {@see \Porta\Bootstrap\CorePanes}. */
    public const string PANE_ID = 'large-file';

    /** The banner, which is not scrolled away: it is the reason this pane exists. */
    private const int HEADER_ROWS = 1;

    private ?string $path = null;

    private ?TextPreview $preview = null;

    /** @var list<string> the preview split into lines, built once per show() */
    private array $lines = [];

    public function __construct(
        private readonly Translator $translator,
        ?Styles $styles = null,
    ) {
        $this->initStyles($styles);
    }

    #[\Override]
    public function paneId(): string
    {
        return self::PANE_ID;
    }

    #[\Override]
    public function show(string $path, mixed $payload): void
    {
        $this->path = $path;
        $this->preview = $payload instanceof TextPreview ? $payload : null;
        // Split once, here, rather than per render: the cap is two megabytes
        // and a frame is drawn on every keystroke.
        $this->lines = null === $this->preview ? [] : \explode("\n", $this->preview->text);
        $this->scrollTop = 0;
        $this->invalidate();
    }

    #[\Override]
    public function clear(): void
    {
        $this->path = null;
        $this->preview = null;
        $this->lines = [];
        $this->scrollTop = 0;
    }

    /**
     * The status segment: what this is, and the two facts that decide whether
     * the user needs another tool — how much of the file is here, and how big
     * it really is.
     */
    #[\Override]
    public function statusRight(): string
    {
        $preview = $this->preview;
        if (null === $preview) {
            return '';
        }

        [$shown, $total] = self::distinctSizes(\strlen($preview->text), $preview->bytes);

        return $this->translator->trans('Read-only preview  %shown% of %total%', [
            '%shown%' => $shown,
            '%total%' => $total,
        ]);
    }

    #[\Override]
    public function render(RenderContext $context): array
    {
        $cols = $context->getColumns();
        $rows = $context->getRows();
        if ($cols < 1 || $rows < 1) {
            return [];
        }
        $this->bodyRows = \max(1, $rows - self::HEADER_ROWS);
        // A resize can leave the view past the end — clamped here, where the
        // height is known, the same place the help viewer re-clamps on a
        // re-wrap.
        $this->scrollTop = $this->clampScroll($this->scrollTop);

        $lines = [$this->bannerLine($cols)];
        for ($row = 0; $row < $this->bodyRows; ++$row) {
            // Truncate as well as pad: a preview is arbitrary bytes, so a single
            // long line is ordinary here rather than exceptional, and padRight
            // is a bound with no ceiling. A row wider than its pane throws out
            // of the render loop and takes the session with it.
            $lines[] = AnsiText::truncate($this->lines[$this->scrollTop + $row] ?? '', $cols, pad: true);
        }

        return $lines;
    }

    /** The one row that never scrolls: why this file is not editable. */
    private function bannerLine(int $cols): string
    {
        $preview = $this->preview;
        [$shown, $total] = null === $preview ? ['', ''] : self::distinctSizes(\strlen($preview->text), $preview->bytes);
        $text = null === $preview
            ? ''
            : ' '
            .$this->translator->trans(
                $preview->truncated
                    ? 'Read-only: %file% is %total%, showing the first %shown%'
                    : 'Read-only: %file% is %total%, too large to edit',
                [
                    '%file%' => \basename((string) $this->path),
                    '%total%' => $total,
                    '%shown%' => $shown,
                ],
            );

        return $this->styles->of(Role::HeaderBar)->apply(AnsiText::truncate($text, $cols, pad: true, ellipsis: ''));
    }

    private function rowCount(): int
    {
        return \count($this->lines);
    }

    /**
     * The pair of sizes for the banner, at a precision that tells them apart.
     *
     * The cap is exactly 2 MiB, so a file a little over it read "is 2.0 MB,
     * showing the first 2.0 MB" — a sentence that answers its own question
     * wrong. A second decimal separates them. Two is where it stops: a file a
     * hundred bytes over the cap really IS the same size as the preview to any
     * precision worth printing, and there the repetition is simply true.
     *
     * @return array{string, string}
     */
    private static function distinctSizes(int $shown, int $total): array
    {
        $pair = [self::humanBytes($shown), self::humanBytes($total)];

        return (
            $pair[0] === $pair[1] && $shown !== $total
                ? [self::humanBytes($shown, decimals: 2), self::humanBytes($total, decimals: 2)]
                : $pair
        );
    }

    /** Sizes the way a user reads them; the cap is megabytes, so one decimal is usually enough. */
    private static function humanBytes(int $bytes, int $decimals = 1): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        $unit = 0;
        while ($value >= 1024.0 && $unit < (\count($units) - 1)) {
            $value /= 1024;
            ++$unit;
        }

        return \sprintf('%.'.$decimals.'f %s', $value, $units[$unit]);
    }
}
