<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Settings\CrumbHeader;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class CrumbHeaderTest extends TestCase
{
    /** @return list<array{label: string, id: ?string}> */
    private static function trail(): array
    {
        return [['label' => 'Settings', 'id' => 'general'], ['label' => 'Languages', 'id' => null], ['label' => 'PHP', 'id' => 'lang:php']];
    }

    public function testOnlyACrumbNamingAnotherPageIsALink(): void
    {
        $opened = [];
        $header = new CrumbHeader();
        $header->set(self::trail());
        $header->onCrumb(static function (string $id) use (&$opened): void {
            $opened[] = $id;
        });
        $header->render(60, Styles::dark(), '1 setting'); // ' Settings › Languages › PHP ' — [0,10) [11,22) [23,28)

        $this->assertFalse($header->click(15), 'a heading that is no page');
        $this->assertFalse($header->click(25), 'the page shown');
        $this->assertFalse($header->click(10), 'a separator');
        $this->assertTrue($header->click(3));
        $this->assertSame(['general'], $opened);
    }

    public function testAClickWithNoListenerStillCountsAsALink(): void
    {
        $header = new CrumbHeader();
        $header->set(self::trail());
        $header->render(60, Styles::dark(), '');

        $this->assertTrue($header->click(3));
    }

    public function testOnlyALinkTintsUnderThePointer(): void
    {
        $header = new CrumbHeader();
        $header->set(self::trail());
        $header->render(60, Styles::dark(), '');

        $this->assertFalse($header->hover(15), 'the heading is no link: nothing to light');
        $this->assertStringNotContainsString("\x1b[48;5;240m", $header->render(60, Styles::dark(), ''));
        $this->assertTrue($header->hover(3));
        $this->assertStringContainsString("\x1b[48;5;240m", $header->render(60, Styles::dark(), ''));
        $this->assertFalse($header->hover(5), 'the same link');
        $this->assertTrue($header->hover(null), 'the pointer left the row');
    }

    public function testSetReportsAChange(): void
    {
        $header = new CrumbHeader();

        $this->assertTrue($header->set(self::trail()));
        $this->assertFalse($header->set(self::trail()));
    }

    /** The trail says which page this is; on a narrow pane it is the hint that gives way. */
    public function testTheHintGivesWayToTheTrail(): void
    {
        $header = new CrumbHeader();
        $header->set(self::trail());

        $line = AnsiUtils::stripAnsiCodes($header->render(34, Styles::dark(), '12 settings'));

        $this->assertSame(' Settings › Languages › PHP 12 se…', $line);
        $this->assertSame(' Settings › Languages › PHP ', AnsiUtils::stripAnsiCodes($header->render(28, Styles::dark(), '12 settings')), 'no room: no hint at all');
    }
}
