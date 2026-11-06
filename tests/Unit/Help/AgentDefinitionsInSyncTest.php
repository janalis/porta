<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Help;

use PHPUnit\Framework\TestCase;

/**
 * The agent tooling is kept once, in `.agents/`, and each tool's folder points
 * at it. Two copies of the same six skills had drifted — one carried a rule
 * the other had lost — before they were merged.
 *
 * Skills are shared by a symlink: `.claude/skills` → `.agents/skills`, which
 * Codex reads directly. A subagent cannot be: Claude Code reads Markdown with
 * frontmatter from `.claude/agents/`, Codex reads TOML from `.codex/agents/`.
 * Those two files hold one prompt, and this test is what keeps it one.
 */
final class AgentDefinitionsInSyncTest extends TestCase
{
    public function testClaudeSkillsIsALinkToTheSharedFolder(): void
    {
        $root = self::root();
        $link = $root.'/.claude/skills';

        $this->assertTrue(\is_link($link), '.claude/skills must be a symlink to .agents/skills, not a copy that drifts');
        $this->assertSame('../.agents/skills', \readlink($link), 'a relative link survives a clone anywhere');
        $this->assertSame(\realpath($root.'/.agents/skills'), \realpath($link));
    }

    public function testEveryClaudeAgentHasTheSameCodexAgent(): void
    {
        $root = self::root();
        $claude = (array) \glob($root.'/.claude/agents/*.md');
        $codex = (array) \glob($root.'/.codex/agents/*.toml');

        $this->assertNotSame([], $claude, 'no agent definitions found — did the folders move?');
        $this->assertSame(
            \array_map(static fn (string $path): string => \basename($path, suffix: '.md'), $claude),
            \array_map(static fn (string $path): string => \basename($path, suffix: '.toml'), $codex),
            'every subagent is defined for both tools',
        );

        foreach ($claude as $path) {
            $name = \basename($path, suffix: '.md');
            $this->assertSame(
                self::fromMarkdown((string) \file_get_contents($path)),
                self::fromToml((string) \file_get_contents($root.'/.codex/agents/'.$name.'.toml')),
                $name.': .claude/agents and .codex/agents hold one prompt — change both',
            );
        }
    }

    /**
     * @return array{name: string, description: string, prompt: string}
     */
    private static function fromMarkdown(string $text): array
    {
        self::assertSame(1, \preg_match('/\A---\n(.*?)\n---\n\n(.*)\z/s', $text, $parts), 'frontmatter, a blank line, then the prompt');
        self::assertSame(1, \preg_match('/^name: (.+)$/m', $parts[1], $name));
        self::assertSame(1, \preg_match('/^description: (.+)$/m', $parts[1], $description));

        return ['name' => $name[1], 'description' => $description[1], 'prompt' => \rtrim($parts[2], characters: "\n")];
    }

    /**
     * @return array{name: string, description: string, prompt: string}
     */
    private static function fromToml(string $text): array
    {
        self::assertSame(1, \preg_match('/^name = "(.+)"$/m', $text, $name));
        self::assertSame(1, \preg_match('/^description = "(.+)"$/m', $text, $description));
        self::assertSame(1, \preg_match("/^developer_instructions = '''\\n(.*?)'''\$/ms", $text, $prompt));

        return ['name' => $name[1], 'description' => $description[1], 'prompt' => \rtrim($prompt[1], characters: "\n")];
    }

    private static function root(): string
    {
        return \dirname(__DIR__, levels: 3);
    }
}
