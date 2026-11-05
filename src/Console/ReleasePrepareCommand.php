<?php

declare(strict_types=1);

namespace Porta\Console;

use Porta\Support\Files;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Turns the tree into release X.Y.Z, short of the commit and the tag.
 *
 * The release workflow runs it on main: `CHANGELOG.md`'s `## [Unreleased]`
 * section becomes `## [X.Y.Z] - <date>` under a fresh empty one, its body is
 * written to the release-notes file, and `Ide::VERSION` — what a source
 * checkout's `porta --version` says — is set to X.Y.Z. The workflow then
 * commits, tags and publishes; `version=` and `tag=` on stdout go straight
 * into `$GITHUB_OUTPUT`.
 *
 * The version is given, or bumped from the newest `vX.Y.Z` tag. It refuses
 * rather than guesses: an empty Unreleased section is a release nobody can
 * read the point of, and a version not above the newest tag is one
 * self-update would never offer. Nothing is written until every check passed.
 */
#[AsCommand(
    name: 'release:prepare',
    description: 'Move the changelog\'s Unreleased section to a new version and set Ide::VERSION',
    help: <<<'HELP'
        <info>release:prepare patch notes.md</info> bumps the newest <info>vX.Y.Z</info> tag;
        <info>minor</info>, <info>major</info> or an explicit <info>X.Y.Z</info> work the same way.
        Prints <info>version=X.Y.Z</info> and <info>tag=vX.Y.Z</info>.
        HELP,
)]
final class ReleasePrepareCommand extends Command
{
    use CommandErrorStream;

    private const string UNRELEASED = "## [Unreleased]\n";

    private const string VERSION_LINE = '/(public const string VERSION = \')[^\']*(\';)/';

    /**
     * @param string $rootDir the checkout to prepare — always given, never
     *                        defaulted to this one: a test that reached the
     *                        default would release the repository it runs in
     */
    public function __construct(
        private readonly string $rootDir,
    ) {
        parent::__construct();
    }

    public function __invoke(
        OutputInterface $output,
        #[Argument('X.Y.Z, or patch, minor or major to bump the newest tag')]
        string $version,
        #[Argument('Where to write the release notes')]
        string $notes,
        #[Option('The release date, YYYY-MM-DD; today when omitted')]
        ?string $date = null,
    ): int {
        $errors = self::errorOutput($output);
        $changelogFile = $this->rootDir.'/CHANGELOG.md';
        $ideFile = $this->rootDir.'/src/Ide.php';
        $changelog = Files::read($changelogFile);
        $ide = Files::read($ideFile);

        $tags = $this->tags();
        $next = null === $tags ? null : self::nextVersion($version, $tags);
        $section = self::unreleased($changelog);
        $problem = match (true) {
            null === $tags => 'release:prepare: git could not list the tags of '.$this->rootDir.' — the newest one is what the version must be above',
            null === $next => \sprintf('release:prepare: "%s" is not X.Y.Z, patch, minor or major, or is not above the newest tag', $version),
            null === $section => 'release:prepare: CHANGELOG.md has no "## [Unreleased]" section',
            '' === $section[2] => 'release:prepare: the Unreleased section of CHANGELOG.md is empty — say what this release changes',
            1 !== \preg_match_all(self::VERSION_LINE, $ide) => 'release:prepare: src/Ide.php has no single VERSION constant to set',
            null !== $date && 1 !== \preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) => \sprintf('release:prepare: "%s" is not a YYYY-MM-DD date', $date),
            default => null,
        };
        if (null !== $problem) {
            $errors->writeln($problem);
        }
        if (null === $problem && null !== $next && null !== $section) {
            [$start, $end, $body] = $section;
            $heading = \sprintf('## [%s] - %s', $next, $date ?? \date('Y-m-d'));
            \file_put_contents($changelogFile, \substr($changelog, offset: 0, length: $start).self::UNRELEASED."\n".$heading."\n\n".$body."\n".\substr($changelog, $end));
            \file_put_contents($ideFile, (string) \preg_replace(self::VERSION_LINE, '${1}'.$next.'${2}', $ide));
            \file_put_contents($notes, $body."\n");
            $output->writeln('version='.$next);
            $output->writeln('tag=v'.$next);
        }

        return null === $problem ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Where the Unreleased section starts and its body ends, and that body
     * trimmed; null when the changelog has none.
     *
     * @return array{int, int, string}|null
     */
    private static function unreleased(string $changelog): ?array
    {
        $start = \strpos($changelog, self::UNRELEASED);
        if (false === $start) {
            return null;
        }
        $bodyStart = $start + \strlen(self::UNRELEASED);
        $next = \strpos($changelog, needle: "\n## [", offset: $bodyStart);
        $end = false === $next ? \strlen($changelog) : $next;

        return [$start, $end, \trim(\substr($changelog, $bodyStart, $end - $bodyStart))];
    }

    /**
     * The checkout's `v*` tags; null when git cannot list them, which must not
     * read as "no tag yet" — that would let any version through.
     *
     * @return list<string>|null
     */
    private function tags(): ?array
    {
        $process = new Process(['git', '-C', $this->rootDir, 'tag', '--list', 'v*']);
        $process->run();

        return $process->isSuccessful() ? \explode("\n", $process->getOutput()) : null;
    }

    /**
     * $request as the version to release, or null when it is malformed or not above the newest of $tags.
     *
     * @param list<string> $tags
     */
    private static function nextVersion(string $request, array $tags): ?string
    {
        $latest = [0, 0, 0];
        foreach ($tags as $tag) {
            $parsed = self::parse(\substr($tag, offset: 1));
            if (null !== $parsed && $parsed > $latest) {
                $latest = $parsed;
            }
        }

        [$major, $minor, $patch] = $latest;
        $next = match ($request) {
            'major' => [$major + 1, 0, 0],
            'minor' => [$major, $minor + 1, 0],
            'patch' => [$major, $minor, $patch + 1],
            default => self::parse($request),
        };

        return null !== $next && $next > $latest ? \implode('.', $next) : null;
    }

    /** @return list<int>|null [major, minor, patch] */
    private static function parse(string $version): ?array
    {
        $match = [];

        return 1 === \preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $version, $match) ? [(int) $match[1], (int) $match[2], (int) $match[3]] : null;
    }
}
