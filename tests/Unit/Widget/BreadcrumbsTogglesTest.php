<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Editor\PreviewMode;
use Porta\Event\PreviewModeRequested;
use Porta\I18n\Translator;
use Porta\Widget\BreadcrumbsWidget;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * The arrangement toggles a previewed file adds to the breadcrumbs bar's right
 * end: drawn with the one lit, hit-tested by the columns the render recorded,
 * hovered at toggle granularity, and turned into a request on a press.
 */
final class BreadcrumbsTogglesTest extends TestCase
{
    /** @var list<PreviewModeRequested> */
    private array $requested = [];

    /** The arrangement the bar's provider answers with, flipped by the tests. */
    private ?PreviewMode $mode = null;

    private function bar(string $locale = 'en'): BreadcrumbsWidget
    {
        $events = new EventDispatcher();
        $events->addListener(PreviewModeRequested::class, function (PreviewModeRequested $event): void {
            $this->requested[] = $event;
        });
        $bar = new BreadcrumbsWidget(translator: new Translator($locale), events: $events);
        $bar->setProvider(static fn (): array => []);
        $bar->setPreviewProvider(fn (): ?PreviewMode => $this->mode);

        return $bar;
    }

    private static function plain(BreadcrumbsWidget $bar, int $columns): string
    {
        return AnsiUtils::stripAnsiCodes(\implode("\n", $bar->render(new RenderContext($columns, 1))));
    }

    public function testAPreviewedFileGetsTheBarEvenWithNoScopeAndTheShownModeIsLit(): void
    {
        $this->mode = PreviewMode::Split;
        $bar = $this->bar();

        $this->assertSame(\str_repeat(' ', times: 57).' Editor  [Split]  Preview  ', self::plain($bar, 84), 'right-aligned, a gap after each tag');

        $this->mode = null;
        $this->assertSame('', self::plain($bar, 84), 'no preview, no scope: no bar at all');
    }

    public function testTheTogglesSitBesideTheTrail(): void
    {
        $this->mode = PreviewMode::Editor;
        $bar = $this->bar();
        $bar->setProvider(static fn (): array => [['label' => 'Intro', 'line' => 3]]);

        $row = self::plain($bar, 60);
        $this->assertStringStartsWith(' Intro', $row);
        $this->assertStringEndsWith('[Editor]  Split   Preview  ', $row);
        $this->assertSame(60, AnsiUtils::visibleWidth($row));
    }

    public function testAPressAsksForTheArrangementUnderIt(): void
    {
        $this->mode = PreviewMode::Split;
        $bar = $this->bar();
        $row = self::plain($bar, 84);

        $this->assertSame(PreviewMode::Preview, $bar->previewModeAt(\strpos($row, needle: 'Preview')));
        $this->assertSame(PreviewMode::Editor, $bar->previewModeAt(\strpos($row, needle: 'Editor') - 1), 'a tag is its whole box, its padding too');
        $this->assertNull($bar->previewModeAt(0), 'the trail is no toggle');
        $this->assertNull($bar->previewModeAt(\strpos($row, needle: 'Split') + 6), 'nor the gap between two');

        $this->assertTrue($bar->pressToggle(\strpos($row, needle: 'Split')));
        $this->assertFalse($bar->pressToggle(0));
        $this->assertSame([PreviewMode::Split], \array_map(static fn (PreviewModeRequested $event): PreviewMode => $event->mode, $this->requested), 'only the press on a toggle asked');
    }

    public function testTheHoverResolvesPerToggleSoMovingInsideOneDoesNotRepaint(): void
    {
        $this->mode = PreviewMode::Split;
        $bar = $this->bar();
        $row = self::plain($bar, 84);
        $preview = (int) \strpos($row, needle: 'Preview');

        $this->assertTrue($bar->hoverAt($preview), 'onto a toggle');
        $this->assertFalse($bar->hoverAt($preview + 1), 'the same toggle');
        $this->assertTrue($bar->hoverAt((int) \strpos($row, needle: 'Editor')), 'onto its neighbour');
        $this->assertTrue($bar->hoverAt(0), 'off the toggles');
    }

    public function testABarTooNarrowForAllThreeDrawsNoneRatherThanACutOne(): void
    {
        $this->mode = PreviewMode::Split;
        $bar = $this->bar();

        $this->assertSame(\str_repeat(' ', times: 20), self::plain($bar, 20));
        $this->assertNull($bar->previewModeAt(10), 'and none can be clicked');
    }

    public function testFlippingTheArrangementRepaintsTheBar(): void
    {
        $this->mode = PreviewMode::Split;
        $bar = $this->bar();
        $bar->beforeRender();
        $revision = $bar->getRenderRevision();

        $bar->beforeRender();
        $this->assertSame($revision, $bar->getRenderRevision(), 'nothing changed');

        $this->mode = PreviewMode::Preview;
        $bar->beforeRender();
        $this->assertNotSame($revision, $bar->getRenderRevision(), 'the toggles changed with the key, not only with a crumb');
    }

    public function testTheCaptionsAreTranslated(): void
    {
        $this->mode = PreviewMode::Preview;
        $bar = $this->bar('fr');

        $this->assertStringContainsString('[Aperçu]', self::plain($bar, 84));
    }

    public function testTheHoveredToggleIsDrawnHoveredAndNoOther(): void
    {
        $this->mode = PreviewMode::Split;
        $bar = $this->bar();
        $render = static fn (): string => \implode('', $bar->render(new RenderContext(84, 1)));
        $row = self::plain($bar, 84);
        $rest = $render();

        $bar->hoverAt((int) \strpos($row, needle: 'Preview'));
        $hovered = $render();

        $this->assertNotSame($rest, $hovered, 'the pointer shows on the toggle it is over');
        $this->assertSame(AnsiUtils::stripAnsiCodes($rest), AnsiUtils::stripAnsiCodes($hovered), 'the shading changed, not the text');
        $bar->hoverAt((int) \strpos($row, needle: 'Preview') + 7); // the tag's closing pad: still the tag
        $this->assertSame($hovered, $render());
        $bar->hoverAt((int) \strpos($row, needle: 'Preview') + 8); // the gap after it
        $this->assertSame($rest, $render(), 'off the tag, nothing is hovered');
        $bar->hoverAt((int) \strpos($row, needle: 'Preview') - 1); // the tag's opening pad
        $this->assertSame($hovered, $render(), 'the whole tag is the target, its padding too');
        $bar->hoverAt((int) \strpos($row, needle: 'Preview') - 2); // the gap before it
        $this->assertSame($rest, $render());
    }

    public function testATogglesHoverNeverSharesAnIndexWithACrumb(): void
    {
        $this->mode = PreviewMode::Split;
        $bar = $this->bar();
        $bar->setProvider(static fn (): array => [['label' => 'Intro', 'line' => 3]]);
        $row = self::plain($bar, 60);

        $this->assertTrue($bar->hoverAt(1), 'onto the first crumb');
        $this->assertTrue($bar->hoverAt((int) \strpos($row, needle: 'Split')), 'onto the second toggle: a change, whatever the indices');
    }

    public function testTheTogglesFitExactlyWithOneColumnToSpare(): void
    {
        $this->mode = PreviewMode::Split;
        $bar = $this->bar();

        $this->assertSame('  Editor  [Split]  Preview  ', self::plain($bar, 28), 'the leading space is all there is');
        $this->assertSame(\str_repeat(' ', times: 27), self::plain($bar, 27));
    }

    public function testARenderWithoutTogglesForgetsTheLastOnesColumns(): void
    {
        $this->mode = PreviewMode::Split;
        $bar = $this->bar();
        $bar->setProvider(static fn (): array => [['label' => 'Intro', 'line' => 3]]);
        $at = (int) \strpos(self::plain($bar, 60), needle: 'Split');

        $this->mode = null;
        self::plain($bar, 60);

        $this->assertNull($bar->previewModeAt($at), 'no toggles drawn, none to click');
    }

    public function testHidingTheBreadcrumbsKeepsTheTogglesAndDropsOnlyTheTrail(): void
    {
        $this->mode = PreviewMode::Split;
        $bar = $this->bar();
        $bar->setProvider(static fn (): array => [['label' => 'Intro', 'line' => 3]]);

        $bar->hide();

        $row = self::plain($bar, 60);
        $this->assertStringNotContainsString('Intro', $row, 'the setting is about the trail');
        $this->assertStringEndsWith(' Editor  [Split]  Preview  ', $row, 'the toggles are the way to the preview, and stay');
        $this->mode = null;
        $this->assertSame('', self::plain($bar, 60), 'hidden, with nothing to toggle: no bar');
    }
}
