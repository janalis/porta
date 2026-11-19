<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Desktop;

use PHPUnit\Framework\TestCase;
use Porta\Config\PlatformKeymap;
use Symfony\Component\Tui\Input\KeyParser;

/** What the native hosts send, held against what Porta actually reads. Each host's own tests read the same files. */
final class DesktopContractTest extends TestCase
{
    /** @return list<array{chord: string, sequence: string}> */
    private static function forwarded(): array
    {
        /** @var array{macosForwardedCmdChords: list<array{chord: string, sequence: string}>} $keys */
        $keys = \json_decode((string) \file_get_contents(\dirname(__DIR__, 3).'/desktop/contract/keys.json'), associative: true, flags: \JSON_THROW_ON_ERROR);

        return $keys['macosForwardedCmdChords'];
    }

    public function testTheHostForwardsExactlyTheCmdChordsTheKeymapBinds(): void
    {
        $forwarded = \array_column(self::forwarded(), 'chord');
        \sort($forwarded);
        $bound = PlatformKeymap::cmdChords();
        \sort($bound);

        $this->assertSame($bound, $forwarded);
    }

    public function testEverySequenceParsesAsItsChordWithoutTheKittyProtocol(): void
    {
        $parser = new KeyParser();
        foreach (self::forwarded() as $key) {
            $this->assertSame($key['chord'], $parser->parse($key['sequence'])['key'] ?? null, $key['chord']);
        }
    }
}
