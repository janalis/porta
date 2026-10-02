<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Config\IdeConfig;
use Porta\Config\KeymapPlatform;

final class KeymapPlatformTest extends TestCase
{
    /** @return iterable<string, array{string, array<string, string>, KeymapPlatform}> */
    public static function hosts(): iterable
    {
        yield 'a Mac' => ['Darwin', [], KeymapPlatform::MacOs];
        yield 'Windows' => ['Windows', [], KeymapPlatform::Windows];
        yield 'plain Linux' => ['Linux', ['TERM' => 'xterm-256color'], KeymapPlatform::Linux];
        // The keyboard is Windows', whatever PHP runs on.
        yield 'Linux inside Windows Terminal' => ['Linux', ['WT_SESSION' => 'f00'], KeymapPlatform::Windows];
        yield 'WSL' => ['Linux', ['WSL_DISTRO_NAME' => 'Ubuntu'], KeymapPlatform::Windows];
        yield 'a BSD' => ['BSD', [], KeymapPlatform::Linux];
        yield 'named in the environment' => ['Linux', ['PORTA_KEYMAP_PLATFORM' => 'macos', 'WT_SESSION' => 'f00'], KeymapPlatform::MacOs];
        yield 'a bad name is ignored' => ['Darwin', ['PORTA_KEYMAP_PLATFORM' => 'amiga'], KeymapPlatform::MacOs];
    }

    /** @param array<string, string> $env */
    #[DataProvider('hosts')]
    public function testDetectsThePlatformOfTheKeyboard(string $os, array $env, KeymapPlatform $expected): void
    {
        $this->assertSame($expected, KeymapPlatform::detect($os, $env));
    }

    public function testTheSettingWinsAndAutoDefersToDetection(): void
    {
        $this->assertSame(KeymapPlatform::MacOs, KeymapPlatform::resolve('macos', KeymapPlatform::Linux), 'over SSH from a Mac');
        $this->assertSame(KeymapPlatform::Windows, KeymapPlatform::resolve(KeymapPlatform::AUTO, KeymapPlatform::Windows));
        $this->assertSame(KeymapPlatform::Linux, KeymapPlatform::resolve('amiga', KeymapPlatform::Linux), 'an unknown value is auto');
    }

    public function testTheSettingOffersAutoAndEveryPlatform(): void
    {
        $this->assertSame(
            [KeymapPlatform::AUTO, ...\array_map(static fn (KeymapPlatform $platform): string => $platform->value, KeymapPlatform::cases())],
            IdeConfig::CHOICE_SETTINGS['keymapPlatform'],
        );
    }
}
