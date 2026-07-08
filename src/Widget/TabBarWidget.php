<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Support\AnsiText;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * One-line editor tab strip. Content comes from a provider closure so it
 * always reflects the BufferManager state.
 */
final class TabBarWidget extends AbstractWidget implements MenuOverlayHost
{
    use MenuOverlayHostTrait;

    /** @var (callable(): list<array{name: string, dirty: bool, active: bool}>)|null */
    private $provider = null;

    private string $lastFingerprint = '';

    /** @var list<array{index: int, start: int, width: int, closeStart: int, closeWidth: int}> layout from the last render */
    private array $lastLayout = [];

    /** @param callable(): list<array{name: string, dirty: bool, active: bool}> $provider */
    public function setProvider(callable $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    public function beforeRender(): void
    {
        if (null === $this->provider) {
            return;
        }
        $fingerprint = (string) json_encode(($this->provider)());
        if ($fingerprint !== $this->lastFingerprint) {
            $this->lastFingerprint = $fingerprint;
            $this->invalidate();
        }
    }

    public function render(RenderContext $context): array
    {
        $columns = $context->getColumns();
        $tabs = null !== $this->provider ? ($this->provider)() : [];

        if ([] === $tabs) {
            return $this->withMenuOverlay([str_repeat(' ', $columns)], $columns);
        }

        // Make sure the active tab is visible: drop leading tabs if needed. Each
        // tab ends with a clickable close ×; the dirty ● (when present) sits just
        // before it. closeOffset is the ×'s display column within the label.
        /** @var list<array{text: string, active: bool, width: int, closeOffset: int}> $parts */
        $parts = [];
        foreach ($tabs as $tab) {
            $prefix = ' '.$tab['name'].' ';
            $marker = $tab['dirty'] ? '●' : '';
            $closeOffset = mb_strwidth($prefix) + mb_strwidth($marker);
            $label = $prefix.$marker.'×'.' ';
            $parts[] = [
                'text' => $label,
                'active' => $tab['active'],
                'width' => mb_strwidth($label) + 1,
                'closeOffset' => $closeOffset,
            ];
        }

        $activeIndex = 0;
        foreach ($parts as $i => $part) {
            if ($part['active']) {
                $activeIndex = $i;
                break;
            }
        }
        $start = 0;
        while ($start < $activeIndex && array_sum(array_column(\array_slice($parts, $start, $activeIndex - $start + 1), 'width')) > $columns) {
            ++$start;
        }

        $line = '';
        $used = 0;
        $this->lastLayout = [];
        foreach (\array_slice($parts, $start, null, true) as $index => $part) {
            if (($used + $part['width']) > $columns) {
                break;
            }
            $style = $part['active'] ? "\x1b[48;5;238m\x1b[38;5;231m\x1b[1m" : "\x1b[38;5;246m";
            // Dim the close × (bright on the active tab) so it reads as an affordance.
            $closeStyle = $part['active'] ? "\x1b[38;5;252m" : "\x1b[38;5;240m";
            $text = str_replace('×', $closeStyle.'×'."\x1b[39m", $part['text']);
            $line .= $style.$text."\x1b[0m\x1b[38;5;238m│\x1b[0m";
            $this->lastLayout[] = [
                'index' => $index,
                'start' => $used,
                'width' => $part['width'],
                'closeStart' => $used + $part['closeOffset'],
                'closeWidth' => 2, // the × cell plus its trailing space
            ];
            $used += $part['width'];
        }

        return $this->withMenuOverlay([AnsiText::padRight($line, $columns)], $columns);
    }

    /**
     * Which tab index (into the full provider list) sits at column $col of
     * the last-rendered strip, or null when the click missed every tab.
     */
    public function tabIndexAtColumn(int $col): ?int
    {
        foreach ($this->lastLayout as $entry) {
            if ($col >= $entry['start'] && $col < ($entry['start'] + $entry['width'])) {
                return $entry['index'];
            }
        }

        return null;
    }

    /**
     * Which tab's close × zone sits at column $col of the last-rendered strip, or
     * null. Checked before {@see tabIndexAtColumn} so a click on the × closes the
     * tab instead of switching to it.
     */
    public function closeIndexAtColumn(int $col): ?int
    {
        foreach ($this->lastLayout as $entry) {
            if ($col >= $entry['closeStart'] && $col < ($entry['closeStart'] + $entry['closeWidth'])) {
                return $entry['index'];
            }
        }

        return null;
    }
}
