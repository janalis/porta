<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Tool;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\Api\BinarySearch;
use Porta\Plugin\Tool\ToolManager;
use Porta\Plugin\Tool\ToolRequirement;
use Porta\Plugin\Tool\ToolRole;
use Porta\Plugin\Tool\ToolSource;

/** What `extra.porta.tools` declares, and what an entry Porta cannot use turns into. */
final class ToolRequirementTest extends TestCase
{
    public function testAFullEntryKeepsEveryField(): void
    {
        $tools = ToolRequirement::fromManifest('graph-viewer', [
            'tools' => [[
                'name' => 'mermaid',
                'binary' => 'mmdc',
                'role' => 'suggested',
                'purpose' => 'renders Mermaid',
                'setting' => 'mermaidPath',
                'sources' => [
                    ['npm',  '@mermaid-js/mermaid-cli'],
                    ['brew', 'mermaid-cli'],
                ],
                'version' => ['-V'],
            ]],
        ]);

        $this->assertCount(1, $tools);
        $tool = $tools[0];
        $this->assertSame('graph-viewer', $tool->plugin);
        $this->assertSame('mermaid', $tool->name);
        $this->assertSame('mmdc', $tool->binary);
        $this->assertSame(ToolRole::Suggested, $tool->role);
        $this->assertSame('renders Mermaid', $tool->purpose);
        $this->assertSame('mermaidPath', $tool->setting);
        $this->assertSame([ToolManager::Npm, ToolManager::Brew], \array_map(static fn (ToolSource $source): ToolManager => $source->manager, $tool->sources));
        $this->assertSame('@mermaid-js/mermaid-cli', $tool->sources[0]->package);
        $this->assertSame(['-V'], $tool->versionArgs);
    }

    public function testABareNameTakesEveryDefault(): void
    {
        $tool = ToolRequirement::fromManifest('p', ['tools' => [['name' => 'chroma']]])[0];

        $this->assertSame('chroma', $tool->binary, 'the binary is the name');
        $this->assertSame(ToolRole::Required, $tool->role);
        $this->assertSame('', $tool->purpose);
        $this->assertNull($tool->setting);
        $this->assertSame([], $tool->sources);
        $this->assertSame(['--version'], $tool->versionArgs);
    }

    public function testUnusableFieldsFallBackRatherThanSink(): void
    {
        $tool = ToolRequirement::fromManifest('p', [
            'tools' => [[
                'name' => 'x',
                'binary' => '',
                'role' => 'mandatory',
                'purpose' => 3,
                'setting' => '',
                'version' => [],
            ]],
        ])[0];

        $this->assertSame('x', $tool->binary);
        $this->assertSame(ToolRole::Required, $tool->role, 'an unknown role is required');
        $this->assertSame('', $tool->purpose);
        $this->assertNull($tool->setting);
        $this->assertSame(['--version'], $tool->versionArgs, 'an empty version argv keeps the default');

        $mixed = ToolRequirement::fromManifest('p', ['tools' => [['name' => 'x', 'version' => [3, '', '-v']]]])[0];
        $this->assertSame(['-v'], $mixed->versionArgs, 'only the usable words are kept');
    }

    public function testAnEntryWithoutANameIsDropped(): void
    {
        $tools = ToolRequirement::fromManifest('p', ['tools' => [['binary' => 'x'], ['name' => '  '], 'dot', ['name' => 'kept']]]);

        $this->assertSame(['kept'], \array_map(static fn (ToolRequirement $tool): string => $tool->name, $tools));
        $this->assertSame([], ToolRequirement::fromManifest('p', []), 'no tools key, no tools');
        $this->assertSame([], ToolRequirement::fromManifest('p', ['tools' => 'dot']));
    }

    public function testASourceOnAnUnknownManagerIsSkippedAlone(): void
    {
        $tool = ToolRequirement::fromManifest('p', [
            'tools' => [[
                'name' => 'x',
                'sources' => [
                    ['apt', 'x'],
                    ['brew', ''],
                    ['brew'],
                    'brew',
                    ['cargo', 'x-cli'],
                ],
            ]],
        ])[0];

        $this->assertCount(1, $tool->sources);
        $this->assertSame(ToolManager::Cargo, $tool->sources[0]->manager);
    }

    public function testAGithubSourceNeedsARepoAndUsableAssetRules(): void
    {
        $rules = ['asset' => 'x_{os}_{arch}', 'os' => ['Darwin' => 'darwin'], 'arch' => ['arm64' => 'arm64']];
        $tool = ToolRequirement::fromManifest('p', [
            'tools' => [[
                'name' => 'x',
                'sources' => [
                    ['github', 'owner/repo'],
                    ['github', 'owner/repo', ['asset' => '']],
                    ['github', '../../etc', $rules],
                    ['github', 'owner/repo/extra', $rules],
                    ['github', 'janalis/custos', $rules],
                ],
            ]],
        ])[0];

        $this->assertCount(1, $tool->sources, 'only the last has a repo and rules a URL can be built from');
        $this->assertSame('janalis/custos', $tool->sources[0]->package);
        $this->assertSame('x_darwin_arm64', $tool->sources[0]->asset?->assetFor('v1.0.0', 'Darwin', 'arm64'));
    }

    public function testTheSearchFollowsTheSources(): void
    {
        $composer = ToolRequirement::fromManifest('p', ['tools' => [['name' => 'phpstan', 'sources' => [['composer', 'phpstan/phpstan']]]]])[0];
        $brew = ToolRequirement::fromManifest('p', ['tools' => [['name' => 'dot', 'sources' => [['brew', 'graphviz']]]]])[0];

        $this->assertSame(BinarySearch::VendorBinThenPath, $composer->search(), 'composer puts a shim in vendor/bin');
        $this->assertSame(BinarySearch::PathOnly, $brew->search(), 'brew never does');
        $this->assertSame(BinarySearch::VendorBinThenPath, new ToolRequirement('p', 'x', 'x', lookup: BinarySearch::VendorBinThenPath)->search(), 'an explicit lookup wins');
    }

    public function testSourceFindsTheDeclaredManager(): void
    {
        $tool = ToolRequirement::fromManifest('p', ['tools' => [['name' => 'x', 'sources' => [['brew', 'a'], ['cargo', 'b']]]]])[0];

        $this->assertSame('b', $tool->source(ToolManager::Cargo)?->package);
        $this->assertNull($tool->source(ToolManager::Npm));
    }

    /** A binary is a file name looked up, never a path a manifest picks. */
    public function testABinaryThatIsNotAPlainFileNameDropsTheEntry(): void
    {
        foreach (['../../bin/sh', '/bin/sh', '.hidden', 'a b'] as $binary) {
            $this->assertSame([], ToolRequirement::fromManifest('p', ['tools' => [['name' => 'x', 'binary' => $binary]]]), $binary);
        }
        $this->assertSame([], ToolRequirement::fromManifest('p', ['tools' => [['name' => '../x']]]), 'the name stands in for a missing binary, and is held to the same');
        $this->assertCount(1, ToolRequirement::fromManifest('p', ['tools' => [['name' => 'x', 'binary' => 'php-cs-fixer.phar']]]));
    }

    /** A package is one argument to the manager, never an option it would read. */
    public function testAPackageThatReadsAsAnOptionIsSkipped(): void
    {
        $tool = ToolRequirement::fromManifest('p', [
            'tools' => [[
                'name' => 'x',
                'sources' => [
                    ['brew', '--force'],
                    ['brew', 'a b'],
                    ['npm',  '@scope/pkg'],
                    ['brew', 'janalis/tap/x'],
                ],
            ]],
        ])[0];

        $this->assertSame(['@scope/pkg', 'janalis/tap/x'], \array_map(static fn (ToolSource $source): string => $source->package, $tool->sources));
    }
}
