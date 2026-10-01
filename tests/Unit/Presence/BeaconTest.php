<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Presence;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Presence\Beacon;

/** A beacon comes from another person's machine: reading one trusts nothing in it. */
final class BeaconTest extends TestCase
{
    private const string ID = '0123456789abcdef';

    /** @return array<string, mixed> */
    private static function fields(array $override = []): array
    {
        return $override + ['id' => self::ID, 'name' => 'Ada', 'via' => 'ssh', 'path' => 'src/A.php', 'line' => 3, 'col' => 4, 'anchorLine' => 1, 'anchorCol' => 2, 'beat' => 1_700_000_000];
    }

    public function testItSurvivesTheRoundTrip(): void
    {
        $beacon = new Beacon(self::ID, 'Ada Lövelace', 'local', 'src/Été.php', 3, 4, -1, 0, 1_700_000_000);

        $this->assertEquals($beacon, Beacon::fromJson($beacon->toJson()));
        $this->assertStringContainsString('"src/Été.php"', $beacon->toJson(), 'slashes and accents written as they are');
        $this->assertEquals(new Beacon(self::ID, 'Ada', 'ssh', null, 3, 4, 1, 2, 1_700_000_000), Beacon::fromJson((string) \json_encode(self::fields(['path' => null]))));
    }

    /** @return iterable<string, array{array<string, mixed>|string}> */
    public static function notBeacons(): iterable
    {
        yield 'not json' => ['{'];
        yield 'not an object' => ['"ada"'];
        yield 'an id that would leave the board' => [self::fields(['id' => '../../etc/passwd'])];
        yield 'an id too short' => [self::fields(['id' => 'abc'])];
        yield 'no id' => [self::fields(['id' => 7])];
        yield 'a name of nothing but control characters' => [self::fields(['name' => "\e\x07"])];
        yield 'no name' => [self::fields(['name' => null])];
        yield 'an unknown way in' => [self::fields(['via' => 'carrier-pigeon'])];
        yield 'a line that is not a number' => [self::fields(['line' => '3'])];
        yield 'no column' => [\array_diff_key(self::fields(), ['col' => 0])];
        yield 'an anchor line that is a float' => [self::fields(['anchorLine' => 1.5])];
        yield 'no anchor column' => [self::fields(['anchorCol' => null])];
        yield 'no beat' => [self::fields(['beat' => false])];
    }

    /** @param array<string, mixed>|string $json */
    #[DataProvider('notBeacons')]
    public function testWhatIsNotABeaconIsNone(array|string $json): void
    {
        $this->assertNull(Beacon::fromJson(\is_string($json) ? $json : (string) \json_encode($json)));
    }

    /** @return iterable<string, array{mixed}> */
    public static function notPaths(): iterable
    {
        yield 'absolute' => ['/etc/passwd'];
        yield 'climbing out' => ['src/../../secret'];
        yield 'a dot' => ['./src/A.php'];
        yield 'an empty segment' => ['src//A.php'];
        yield 'an escape in a name' => ["src/\e[2JA.php"];
        yield 'not a string' => [['src']];
    }

    #[DataProvider('notPaths')]
    public function testAPathOutsideTheProjectIsNoPath(mixed $path): void
    {
        $beacon = Beacon::fromJson((string) \json_encode(self::fields(['path' => $path])));

        $this->assertInstanceOf(Beacon::class, $beacon, 'the rest of the beacon still says who is here');
        $this->assertNull($beacon->path);
    }

    public function testANameIsCleanedAndCut(): void
    {
        $beacon = Beacon::fromJson((string) \json_encode(self::fields(['name' => "  \u{202E}Ada\e[31m".\str_repeat('é', times: 100)])));

        $this->assertInstanceOf(Beacon::class, $beacon);
        $this->assertSame('Ada[31m'.\str_repeat('é', Beacon::MAX_NAME - 7), $beacon->name, 'no control or format character, 40 characters at most');
        $this->assertSame('x', Beacon::clean("\x00x\u{200B}"));
    }

    public function testAnIdIsSixteenHexDigits(): void
    {
        $this->assertTrue(Beacon::isId(Beacon::newId()));
        $this->assertNotSame(Beacon::newId(), Beacon::newId());
        $this->assertFalse(Beacon::isId(self::ID.'0'));
        $this->assertFalse(Beacon::isId('0123456789ABCDEF'));
    }
}
