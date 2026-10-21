<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Tool;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Plugin\Tool\GithubAsset;
use Porta\Plugin\Tool\GithubRelease;
use Porta\Plugin\Tool\VersionText;

/** One repository's release naming, URLs and checksum file — symfony/language-tools' here. */
final class GithubReleaseTest extends TestCase
{
    public static function symfony(): GithubRelease
    {
        return new GithubRelease('symfony/language-tools', new GithubAsset(
            'symfony-lsp-{tag}-{os}-{arch}.tar.gz',
            ['Darwin' => 'macos', 'Linux' => 'linux'],
            ['arm64' => 'arm64', 'aarch64' => 'arm64', 'x86_64' => 'x64', 'amd64' => 'x64'],
            'symfony-lsp-{tag}-{os}-{arch}/symfony-lsp',
        ));
    }

    /** @return iterable<string, array{string, string, string|null}> */
    public static function hosts(): iterable
    {
        yield 'mac arm' => ['Darwin', 'arm64', 'symfony-lsp-v1-macos-arm64.tar.gz'];
        yield 'mac intel' => ['Darwin', 'x86_64', 'symfony-lsp-v1-macos-x64.tar.gz'];
        yield 'linux arm' => ['Linux', 'aarch64', 'symfony-lsp-v1-linux-arm64.tar.gz'];
        yield 'linux x64' => ['Linux', 'x86_64', 'symfony-lsp-v1-linux-x64.tar.gz'];
        yield 'linux amd64 alias' => ['Linux', 'amd64', 'symfony-lsp-v1-linux-x64.tar.gz'];
        yield 'windows has its own zip, not handled' => ['Windows', 'x86_64', null];
        yield 'exotic arch' => ['Linux', 'riscv64', null];
    }

    #[DataProvider('hosts')]
    public function testTheAssetMapsOsAndMachine(string $os, string $machine, ?string $asset): void
    {
        $this->assertSame($asset, self::symfony()->asset->assetFor('v1', $os, $machine));
    }

    public function testLatestTagReadsTheReleaseJsonDefensively(): void
    {
        $release = self::symfony();

        $this->assertSame('v0.16.0', $release->latestTag('{"tag_name":"v0.16.0","name":"x"}'));
        $this->assertSame('v1.0.0-rc.1', $release->latestTag('{"tag_name":"v1.0.0-rc.1"}'), 'pre-release tags are still tags');
        $this->assertNull($release->latestTag('not json'));
        $this->assertNull($release->latestTag('{"name":"no tag"}'));
        $this->assertNull($release->latestTag('{"tag_name":""}'));
        $this->assertNull($release->latestTag('{"tag_name":42}'));
        $this->assertNull($release->latestTag('{"tag_name":"../../evil"}'), 'the tag becomes a URL segment and a filename — traversal dies here');
        $this->assertNull($release->latestTag('{"tag_name":"v1/../x"}'));
    }

    public function testUrlsAndAssetNamesComposeFromTagAndPlatform(): void
    {
        $release = self::symfony();

        $this->assertSame('https://api.github.com/repos/symfony/language-tools/releases/latest', $release->apiUrl());
        $this->assertSame('https://github.com/symfony/language-tools/releases/latest', $release->latestUrl());
        $this->assertSame('https://github.com/symfony/language-tools/releases/download/v0.16.0/symfony-lsp-v0.16.0-macos-arm64.tar.gz', $release->assetUrl(
            'v0.16.0',
            'symfony-lsp-v0.16.0-macos-arm64.tar.gz',
        ));
        $this->assertSame('https://github.com/symfony/language-tools/releases/download/v0.16.0/SHA256SUMS', $release->sumsUrl('v0.16.0'));
        $custom = new GithubRelease('o/r', new GithubAsset('x', ['Linux' => 'linux'], ['x86_64' => 'amd64'], sums: 'checksums.txt'));
        $this->assertSame('https://github.com/o/r/releases/download/v1/checksums.txt', $custom->sumsUrl('v1'), 'the checksum file is the repository\'s own');
    }

    public function testExpectedShaFindsTheAssetLineAndRejectsTheRest(): void
    {
        $release = self::symfony();
        $sha = \str_repeat('ab', times: 32);
        $sums = "{$sha}  one.tar.gz\n".\str_repeat('cd', times: 32)."  *two.tar.gz\nnot a sums line\nshort0  three.tar.gz\n";

        $this->assertSame($sha, $release->expectedSha($sums, 'one.tar.gz'));
        $this->assertSame(\str_repeat('cd', times: 32), $release->expectedSha($sums, 'two.tar.gz'), 'the binary-mode * marker is stripped');
        $this->assertNull($release->expectedSha($sums, 'three.tar.gz'), 'a malformed hash is not trusted');
        $this->assertNull($release->expectedSha($sums, 'absent.tar.gz'));
        $this->assertNull($release->expectedSha('', 'one.tar.gz'));
    }

    /**
     * The `--version` line is the only record of what is installed — there is
     * no sidecar file and no setting, because either would be a second source
     * of truth that desyncs when someone swaps the binary by hand.
     */
    public function testVersionTextReadsWhatTheBinaryCallsItself(): void
    {
        $this->assertSame('0.21.0', VersionText::of("Symfony Language Tools 0.21.0\n"));
        $this->assertSame('1.0.0-beta.2', VersionText::of('Symfony Language Tools 1.0.0-beta.2'), 'a pre-release still names itself');
        $this->assertNull(VersionText::of('Symfony Language Tools'), 'a line with no version is not a version');
        $this->assertNull(VersionText::of(''), 'a binary that printed nothing leaves the version unknown');
        $this->assertNull(VersionText::of('v9.9.9'), 'a TAG is not a --version line; versionOfTag() is that direction');
    }

    /**
     * A tag and a `--version` line must compare as one spelling, or an update
     * check answers "0.21.0 is not v0.21.0" and downloads 9MB on every run.
     */
    public function testVersionOfTagStripsTheTagSpelling(): void
    {
        $release = self::symfony();

        $this->assertSame('0.21.0', $release->versionOfTag('v0.21.0'));
        $this->assertSame('1.0.0-rc.1', $release->versionOfTag('v1.0.0-rc.1'));
        $this->assertNull($release->versionOfTag('0.21.0'), 'a tag without its v is not a tag this repo publishes');
        $this->assertNull($release->versionOfTag('../../evil'), 'the same guard the resolvers share');
        $this->assertNull($release->versionOfTag('v'), 'a bare v carries no version to compare');
    }
}
