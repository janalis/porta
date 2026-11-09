<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Store;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\Store\ReadmeImages;

/** The pictures a README shows: its own, never a badge or someone else's. */
final class ReadmeImagesTest extends TestCase
{
    private const string AT = 'https://raw.githubusercontent.com/acme/porta-rector/abc123/docs/README.md';

    public function testEachKindOfPictureInTheOrderItIsShown(): void
    {
        $readme = <<<'MD'
            # Rector

            <p align="center"><img alt="logo" width="96" src="logo.png"></p>

            ![Screenshot](./shots/one.png "The first")
            ![Second][two]
            ![third]
            ![Same](logo.png)

            [two]: <https://cdn.test/two.png>
            [THIRD]: /media/three.png 'Third'
            MD;

        $this->assertSame(
            [
                'https://raw.githubusercontent.com/acme/porta-rector/abc123/docs/logo.png',
                'https://raw.githubusercontent.com/acme/porta-rector/abc123/docs/shots/one.png',
                'https://cdn.test/two.png',
                'https://raw.githubusercontent.com/acme/porta-rector/abc123/media/three.png',
            ],
            ReadmeImages::in($readme, self::AT),
        );
    }

    public function testBadgesAvatarsAndThanksAreLeftOut(): void
    {
        $readme = <<<'MD'
            ![CI](https://github.com/acme/rector/actions/workflows/ci.yml/badge.svg)
            ![Version](https://img.shields.io/packagist/v/acme/rector.svg)
            <img src="https://poser.pugx.org/acme/rector/downloads">
            ![Coverage](https://codecov.io/gh/acme/rector/branch/main/graph/badge.svg?token=abc)
            ![Me](https://avatars.githubusercontent.com/u/1?v=4)
            ![Status](https://ci.test/badges/main)
            ![Kept](https://cdn.test/kept.png)

            ## Sponsors

            ![Acme Corp](https://cdn.test/sponsor.png)

            ### Gold

            ![Gold](https://cdn.test/gold.png)

            ## Usage

            ![Usage](https://cdn.test/usage.png)

            <h2>Thanks to our backers</h2>

            ![Backer](https://cdn.test/backer.png)
            MD;

        $this->assertSame(['https://cdn.test/kept.png', 'https://cdn.test/usage.png'], ReadmeImages::in($readme, self::AT));
    }

    public function testOnlyAHeadingStartsOrEndsASection(): void
    {
        $readme = <<<'MD'
            Built with help from ## sponsors, see below.

            ![Kept](https://cdn.test/one.png)

            <H2>Our Sponsors</H2>

            ![Sponsor](https://cdn.test/sponsor.png)

            Text with <h2> inline, still the sponsors' section.

            ![Sponsor](https://cdn.test/sponsor2.png)

            # Screens

            ![Screen](https://cdn.test/screen.png)
            MD;

        $this->assertSame(['https://cdn.test/one.png', 'https://cdn.test/screen.png'], ReadmeImages::in($readme, self::AT));
    }

    public function testTheFirstDefinitionOfAReferenceIsTheOne(): void
    {
        $readme = "![Logo][Émoi]\n\n[émoi]: https://cdn.test/first.png\n[ÉMOI]: https://cdn.test/second.png\n";
        $this->assertSame(['https://cdn.test/first.png'], ReadmeImages::in($readme, self::AT));
        $this->assertSame(
            ['https://raw.githubusercontent.com/acme/porta-rector/abc123/docs/logo.png'],
            ReadmeImages::in('<img src=" logo.png ">', self::AT),
            'the space around an address is not part of it',
        );
    }

    public function testTheMastheadIsWhatComesBeforeTheFirstSection(): void
    {
        $readme = "# Tool\n\n<img src=\"https://cdn.test/logo.png\">\n\n## Install\n\n![shot](https://cdn.test/shot.png)\n";
        $this->assertSame(['https://cdn.test/logo.png'], ReadmeImages::masthead($readme, self::AT));
        $this->assertSame(['https://cdn.test/logo.png'], ReadmeImages::masthead("<img src=\"https://cdn.test/logo.png\">\n<h2>Install</h2>\n![x](https://cdn.test/x.png)", self::AT));
        $this->assertSame(['https://cdn.test/logo.png'], ReadmeImages::masthead('<img src="https://cdn.test/logo.png">', self::AT), 'no section: the whole of it');
    }

    public function testOfALightAndDarkPairTheDarkOneIsKept(): void
    {
        $readme = "![logo](https://cdn.test/logo.png#gh-light-mode-only)\n![logo](https://cdn.test/logo-dark.png#gh-dark-mode-only)\n";
        $this->assertSame(['https://cdn.test/logo-dark.png#gh-dark-mode-only'], ReadmeImages::masthead($readme, self::AT));
    }

    public function testAddressesAreMadeHttpsOrDropped(): void
    {
        $this->assertSame('https://cdn.test/a.png', ReadmeImages::resolve('//cdn.test/a.png', self::AT));
        $this->assertNull(ReadmeImages::resolve('http://cdn.test/a.png', self::AT), 'never plain http');
        $this->assertNull(ReadmeImages::resolve('data:image/png;base64,AAAA', self::AT));
        $this->assertNull(ReadmeImages::resolve('', self::AT));
        $this->assertNull(ReadmeImages::resolve('#anchor', self::AT));
        $this->assertNull(ReadmeImages::resolve("a\x1b.png", self::AT));
        $this->assertNull(ReadmeImages::resolve('a b.png', self::AT));
        $this->assertSame('https://raw.githubusercontent.com/acme/porta-rector/abc123/img/a.png', ReadmeImages::resolve('../img/./a.png', self::AT));
        $this->assertNull(ReadmeImages::resolve('../../../../../../a.png', self::AT), 'no climbing out of the host');
        $this->assertSame('https://raw.githubusercontent.com/a.png', ReadmeImages::resolve('../../../../a.png', self::AT), 'up to it');
        $this->assertNull(ReadmeImages::resolve('../../../../../a.png', self::AT), 'and not one step past it');
        $this->assertSame('https://raw.githubusercontent.com/acme/porta-rector/abc123/docs/img/a:b.png', ReadmeImages::resolve('img/a:b.png', self::AT), 'a colon past the first step is no scheme');
        $this->assertSame(
            'https://raw.githubusercontent.com/acme/rector/main/docs/a.png',
            ReadmeImages::resolve('https://github.com/acme/rector/blob/main/docs/a.png?raw=true', self::AT),
            'a GitHub page of a file is the file',
        );
        $this->assertSame('https://raw.githubusercontent.com/acme/rector/main/a.png', ReadmeImages::resolve('https://github.com/acme/rector/raw/main/a.png', self::AT));
        $this->assertSame('https://cdn.test/a.png?w=2', ReadmeImages::resolve('https://cdn.test/a.png?w=2', self::AT));
        $this->assertSame('https://cdn.test/root.png', ReadmeImages::resolve('/root.png', 'https://cdn.test/docs/README.md'), 'outside GitHub, the host is the root');
        $this->assertSame('https://cdn.test/docs/a.png', ReadmeImages::resolve('a.png', 'https://cdn.test/docs/README.md?x=1'));
        $this->assertNull(ReadmeImages::resolve('a.png', 'no-scheme'));
    }

    public function testAtMostAFewPicturesAndEachOnce(): void
    {
        $readme = '';
        for ($i = 0; $i < 10; ++$i) {
            $readme .= "![x](https://cdn.test/{$i}.png)\n![again](https://cdn.test/{$i}.png)\n";
        }
        $found = ReadmeImages::in($readme, self::AT);
        $this->assertCount(ReadmeImages::MAX, $found);
        $this->assertSame('https://cdn.test/5.png', $found[5]);
        $this->assertSame(
            ['https://cdn.test/html.png?a=1&b=2', 'https://cdn.test/html.png'],
            ReadmeImages::in('<img src="https://cdn.test/html.png?a=1&amp;b=2" hidden><img src="https://cdn.test/html.png">', self::AT),
            'an HTML attribute is read as HTML',
        );
    }
}
