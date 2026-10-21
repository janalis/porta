<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Tool;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\Tool\GithubAsset;

/** The two asset shapes in use, spelled by each repository's own platform words. */
final class GithubAssetTest extends TestCase
{
    private const array SYMFONY = [
        'asset' => 'symfony-lsp-{tag}-{os}-{arch}.tar.gz',
        'member' => 'symfony-lsp-{tag}-{os}-{arch}/symfony-lsp',
        'os' => ['Darwin' => 'macos', 'Linux' => 'linux'],
        'arch' => ['arm64' => 'arm64', 'aarch64' => 'arm64', 'x86_64' => 'x64'],
    ];

    public function testAnArchiveAssetNamesTheBinaryInsideIt(): void
    {
        $asset = GithubAsset::fromManifest(self::SYMFONY);

        $this->assertInstanceOf(GithubAsset::class, $asset);
        $this->assertSame('symfony-lsp-v0.24.1-macos-arm64.tar.gz', $asset->assetFor('v0.24.1', 'Darwin', 'arm64'));
        $this->assertSame('symfony-lsp-v0.24.1-linux-arm64/symfony-lsp', $asset->memberFor('v0.24.1', 'Linux', 'aarch64'));
        $this->assertSame('SHA256SUMS', $asset->sums);
    }

    public function testABareAssetIsTheBinary(): void
    {
        $asset = GithubAsset::fromManifest(['asset' => 'custos_{version}_{os}_{arch}', 'os' => ['Linux' => 'linux'], 'arch' => ['x86_64' => 'amd64'], 'sums' => 'checksums.txt']);

        $this->assertInstanceOf(GithubAsset::class, $asset);
        $this->assertSame('custos_0.1.7_linux_amd64', $asset->assetFor('v0.1.7', 'Linux', 'x86_64'), '{version} drops the v');
        $this->assertNull($asset->memberFor('v0.1.7', 'Linux', 'x86_64'));
        $this->assertSame('checksums.txt', $asset->sums);
    }

    public function testAHostNeitherMapNamesHasNoAsset(): void
    {
        $asset = GithubAsset::fromManifest(self::SYMFONY);

        $this->assertInstanceOf(GithubAsset::class, $asset);
        $this->assertNull($asset->assetFor('v1', 'Windows', 'x86_64'));
        $this->assertNull($asset->assetFor('v1', 'Darwin', 'riscv64'));
        $this->assertNull($asset->memberFor('v1', 'Windows', 'x86_64'));
    }

    public function testUnusableRulesAreRefused(): void
    {
        $this->assertNull(GithubAsset::fromManifest(['os' => ['Darwin' => 'darwin'], 'arch' => ['arm64' => 'arm64']]), 'no asset name');
        $this->assertNull(GithubAsset::fromManifest(['asset' => 'x', 'os' => [], 'arch' => ['arm64' => 'arm64']]), 'no OS a binary exists for');
        $this->assertNull(GithubAsset::fromManifest(['asset' => 'x', 'os' => ['Darwin' => 'darwin'], 'arch' => 'arm64']), 'not a map');
        $this->assertNull(GithubAsset::fromManifest(['asset' => 'x', 'os' => ['Darwin' => 'darwin'], 'arch' => ['arm64' => 'a'], 'sums' => '']), 'no checksum file');
        $kept = GithubAsset::fromManifest(['asset' => 'x', 'os' => ['Darwin' => 'darwin', 0 => 'bad', 'Linux' => ''], 'arch' => ['arm64' => 'a'], 'member' => '']);
        $this->assertSame(['Darwin' => 'darwin'], $kept?->os, 'unusable map entries go alone');
        $this->assertNull($kept?->member, 'an empty member is the bare shape');
    }

    /** Every name becomes a path under the download's scratch directory, so none may climb out of it. */
    public function testNamesThatWouldLeaveTheScratchDirectoryAreRefused(): void
    {
        $rules = ['asset' => 'x_{os}_{arch}.tar.gz', 'os' => ['Darwin' => 'darwin'], 'arch' => ['arm64' => 'arm64']];

        $this->assertInstanceOf(GithubAsset::class, GithubAsset::fromManifest([...$rules, 'member' => 'x-{tag}/bin/x']));
        $this->assertNull(GithubAsset::fromManifest([...$rules, 'asset' => '../x']), 'an asset is a file name');
        $this->assertNull(GithubAsset::fromManifest([...$rules, 'asset' => 'dir/x']));
        $this->assertNull(GithubAsset::fromManifest([...$rules, 'sums' => '../../SUMS']), 'so is the checksum file');
        $this->assertNull(GithubAsset::fromManifest([...$rules, 'member' => '../x']), 'a member stays inside the archive');
        $this->assertNull(GithubAsset::fromManifest([...$rules, 'member' => '/etc/x']));
        $this->assertNull(GithubAsset::fromManifest([...$rules, 'member' => 'a//x']));
        $this->assertNull(GithubAsset::fromManifest([...$rules, 'os' => ['Darwin' => '../..', 'Linux' => 'a/b']]), 'a platform token is spliced into the name: with none usable, no asset');
    }
}
