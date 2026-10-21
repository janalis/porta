<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Tool;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\Tool\GithubAsset;
use Porta\Plugin\Tool\LatestVersions;
use Porta\Plugin\Tool\ToolManager;
use Porta\Plugin\Tool\ToolRequirement;
use Porta\Plugin\Tool\ToolSource;

/** Where each source's newest version is read from, fed recorded answers. */
final class LatestVersionsTest extends TestCase
{
    /** @var list<list<string>> */
    private array $ran = [];

    private function versions(int $code, string $answer): LatestVersions
    {
        return new LatestVersions(function (array $argv, string $cwd, \Closure $onExit) use ($code, $answer): void {
            $this->ran[] = $argv;
            $onExit($code, $answer);
        });
    }

    private function latest(LatestVersions $versions, ToolSource $source): ?string
    {
        $found = 'never answered';
        $versions->of(new ToolRequirement('p', 'x', 'x', sources: [$source]), $source, static function (?string $version) use (&$found): void {
            $found = $version;
        });
        $this->assertNotSame('never answered', $found, 'exactly one answer');

        return $found;
    }

    public function testEachSourceAsksItsOwnIndex(): void
    {
        $this->assertSame('2.1.17', $this->latest(
            $this->versions(0, '{"packages":{"phpstan/phpstan":[{"version":"2.1.17"},{"version":"2.1.16"}]}}'),
            new ToolSource(ToolManager::Composer, 'phpstan/phpstan'),
        ));
        $this->assertStringEndsWith('https://repo.packagist.org/p2/phpstan/phpstan.json', \implode(' ', $this->ran[0]));

        $this->assertSame('16.1.0', $this->latest($this->versions(0, '{"formulae":[{"versions":{"stable":"16.1.0"}}],"casks":[]}'), new ToolSource(ToolManager::Brew, 'graphviz')));
        $this->assertSame(['brew', 'info', '--json=v2', '--formula', 'graphviz'], $this->ran[1]);

        $this->assertSame('0.1.7', $this->latest($this->versions(0, '{"formulae":[],"casks":[{"version":"0.1.7"}]}'), new ToolSource(ToolManager::BrewCask, 'janalis/tap/custos')));
        $this->assertSame(['brew', 'info', '--json=v2', '--cask', 'janalis/tap/custos'], $this->ran[2]);

        $this->assertSame('0.5.0', $this->latest($this->versions(0, '{"crate":{"max_stable_version":"0.5.0","max_version":"0.6.0-rc.1"}}'), new ToolSource(ToolManager::Cargo, 'phpantom_lsp')));
        $this->assertContains('-A', $this->ran[3], 'crates.io refuses a request without a User-Agent');
        $this->assertStringEndsWith('https://crates.io/api/v1/crates/phpantom_lsp', \implode(' ', $this->ran[3]));

        $this->assertSame('11.4.0', $this->latest($this->versions(0, '{"version":"v11.4.0"}'), new ToolSource(ToolManager::Npm, '@mermaid-js/mermaid-cli')));
        $this->assertStringEndsWith('https://registry.npmjs.org/@mermaid-js%2Fmermaid-cli/latest', \implode(' ', $this->ran[4]), 'a scoped name keeps its scope in one segment');
    }

    public function testAGithubSourceReadsTheReleaseRedirect(): void
    {
        $source = new ToolSource(ToolManager::Github, 'janalis/custos', new GithubAsset('custos_{os}_{arch}', ['Darwin' => 'darwin'], ['arm64' => 'arm64']));

        $this->assertSame('0.1.7', $this->latest($this->versions(0, "HTTP/2 302\r\nlocation: https://github.com/janalis/custos/releases/tag/v0.1.7\r\n\r\n"), $source));
    }

    public function testAFailureOrAnUnusableAnswerIsNull(): void
    {
        $composer = new ToolSource(ToolManager::Composer, 'a/b');

        $this->assertNull($this->latest($this->versions(6, '{"packages":{"a/b":[{"version":"1.0.0"}]}}'), $composer), 'the network was not there');
        $this->assertNull($this->latest($this->versions(0, 'not json'), $composer));
        $this->assertNull($this->latest($this->versions(0, '{"packages":{"a/b":[]}}'), $composer));
        $this->assertNull($this->latest($this->versions(0, '{"packages":{"a/b":[{"version":"dev-main"}]}}'), $composer), 'not a version');
        $this->assertNull($this->latest($this->versions(0, ''), new ToolSource(ToolManager::Plugin, 'install')), 'a plugin\'s own installer has no index');
        $this->assertSame([], \array_slice($this->ran, offset: 5), 'and asks nothing');
    }

    public function testNewerComparesVersionsNotStrings(): void
    {
        $this->assertTrue(LatestVersions::newer('1.9.0', '1.10.0'));
        $this->assertTrue(LatestVersions::newer('v1.0.0', '1.0.1'), 'a v on either side is not a difference');
        $this->assertFalse(LatestVersions::newer('1.10.0', '1.9.0'));
        $this->assertFalse(LatestVersions::newer('1.0.0', '1.0.0'));
        $this->assertFalse(LatestVersions::newer('', '1.0.0'), 'an unknown installed version is never "out of date"');
        $this->assertFalse(LatestVersions::newer('1.0.0', null));
        $this->assertFalse(LatestVersions::newer('1.0.0', ''));
    }
}
