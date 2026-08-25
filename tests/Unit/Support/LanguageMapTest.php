<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Support\LanguageMap;
use Porta\Support\ProjectPaths;
use Porta\Tests\Support\Probe;

/**
 * Pins both columns of {@see LanguageMap} per extension.
 *
 * The highlighting and LSP answers used to live in two separate files; these
 * cases are the behaviour they had at the time they were merged, so the merge
 * is verifiably a refactor rather than a retune. The LSP column especially:
 * it steers `didOpen`, the per-language indent settings and debug-adapter
 * selection, so a silent change there is a real regression.
 */
final class LanguageMapTest extends TestCase
{
    /** @return iterable<string, array{string, string|null, string}> */
    public static function paths(): iterable
    {
        // file, expected highlight grammar, expected LSP languageId
        yield 'php' => ['a.php', 'php', 'php'];
        yield 'phtml' => ['a.phtml', 'php', 'php'];
        yield 'js' => ['a.js', 'javascript', 'javascript'];
        yield 'mjs' => ['a.mjs', 'javascript', 'javascript'];
        yield 'ts' => ['a.ts', 'typescript', 'typescript'];
        yield 'python' => ['a.py', 'python', 'python'];
        yield 'css' => ['a.css', 'css', 'css'];
        yield 'scss' => ['a.scss', 'scss', 'scss'];
        yield 'html' => ['a.html', 'html', 'html'];
        yield 'htm' => ['a.htm', 'html', 'html'];
        yield 'markdown' => ['a.md', 'markdown', 'markdown'];
        yield 'yaml' => ['a.yaml', 'yaml', 'yaml'];
        yield 'yml' => ['a.yml', 'yaml', 'yaml'];
        yield 'twig' => ['a.twig', 'twig', 'twig'];
        yield 'json' => ['a.json', 'json', 'json'];
        yield 'xml' => ['a.xml', 'xml', 'xml'];
        yield 'env ext' => ['a.env', 'dotenv', 'dotenv'];

        // Highlighted, but no language server speaks them — LSP stays plaintext.
        yield 'jsx' => ['a.jsx', 'javascript', 'plaintext'];
        yield 'tsx' => ['a.tsx', 'typescript', 'plaintext'];
        yield 'blade' => ['a.blade', 'blade', 'plaintext'];
        yield 'sql' => ['a.sql', 'sql', 'plaintext'];
        yield 'sh' => ['a.sh', 'bash', 'plaintext'];
        yield 'bash' => ['a.bash', 'bash', 'plaintext'];
        yield 'zsh' => ['a.zsh', 'bash', 'plaintext'];
        yield 'ini' => ['a.ini', 'ini', 'plaintext'];
        yield 'neon' => ['a.neon', 'ini', 'plaintext'];
        yield 'vue' => ['a.vue', 'vue', 'plaintext'];
        yield 'svelte' => ['a.svelte', 'svelte', 'plaintext'];
        yield 'graphql' => ['a.graphql', 'graphql', 'plaintext'];
        yield 'terraform' => ['a.tf', 'terraform', 'plaintext'];
        yield 'diff' => ['a.diff', 'diff', 'plaintext'];
        yield 'patch' => ['a.patch', 'diff', 'plaintext'];

        // Basename-driven, extension absent or misleading.
        yield 'dockerfile' => ['Dockerfile', 'dockerfile', 'plaintext'];
        yield 'dotenv' => ['.env.local', 'dotenv', 'dotenv'];
        yield 'dotenv bare' => ['/proj/.env', 'dotenv', 'dotenv'];
        yield 'dumped env is php' => ['.env.local.php', 'php', 'php'];
        yield 'direnv rc' => ['.envrc', 'dotenv', 'dotenv'];
        yield 'tsconfig' => ['tsconfig.json', 'jsonc', 'json'];
        yield 'jsconfig' => ['jsconfig.json', 'jsonc', 'json'];
        yield 'composer.lock' => ['composer.lock', 'json', 'plaintext'];
        yield 'babelrc' => ['.babelrc', 'json', 'plaintext'];
        yield 'jsonc ext' => ['a.jsonc', 'jsonc', 'json'];
        yield 'json5 ext' => ['a.json5', 'jsonc', 'json'];

        // Named so a backend can claim them; the built-in engine renders them
        // plain, exactly as it did before they were in the map.
        yield 'rust' => ['a.rs', 'rust', 'plaintext'];
        yield 'go' => ['a.go', 'go', 'plaintext'];
        yield 'ruby' => ['a.rb', 'ruby', 'plaintext'];
        yield 'elixir' => ['a.exs', 'elixir', 'plaintext'];
        yield 'c header' => ['a.h', 'c', 'plaintext'];
        yield 'cpp' => ['a.hpp', 'cpp', 'plaintext'];
        yield 'toml' => ['a.toml', 'toml', 'plaintext'];

        yield 'unknown' => ['a.wat', null, 'plaintext'];
        yield 'no extension' => ['README', null, 'plaintext'];
    }

    #[DataProvider('paths')]
    public function testResolvesBothColumns(string $file, ?string $highlight, string $lsp): void
    {
        $this->assertSame($highlight, LanguageMap::highlightForPath($file), "highlight for {$file}");
        $this->assertSame($lsp, LanguageMap::lspForPath($file), "lsp for {$file}");
    }

    /** The LSP facade must keep delegating here. */
    #[DataProvider('paths')]
    public function testFacadesAgreeWithTheMap(string $file, ?string $highlight, string $lsp): void
    {
        $this->assertSame($highlight, LanguageMap::highlightForPath($file));
        $this->assertSame($lsp, ProjectPaths::languageId($file));
    }

    public function testResolutionIsCaseInsensitive(): void
    {
        $this->assertSame('php', LanguageMap::highlightForPath('/tmp/A.PHP'));
        $this->assertSame('php', LanguageMap::lspForPath('/tmp/A.PHP'));
        $this->assertSame('jsonc', LanguageMap::highlightForPath('/x/TSConfig.JSON'));
    }

    public function testLspIdsAreDistinctAndExcludePlaintext(): void
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (array $entry): ?string => $entry[1], Probe::getStatic(LanguageMap::class, 'EXTENSIONS')))));

        $this->assertSame(array_values(array_unique($ids)), $ids, 'no duplicates');
        $this->assertNotContains('plaintext', $ids);
        $this->assertContains('php', $ids);
    }
}
