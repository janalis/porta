<?php

declare(strict_types=1);

namespace Porta\Plugin\Api;

use Porta\Config\PortaHome;
use Porta\Filesystem\ProjectLocation;
use Porta\Filesystem\RemoteSpec;
use Porta\Install\PortaBinary;
use Porta\Onboarding\OnboardingState;
use Porta\Support\ProcessEnvironment;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `porta <scheme> <location>` — open a project on a server, for a
 * {@see RemoteProjectPlugin} that ships one.
 *
 * What only a terminal can do happens here, before the IDE takes the
 * terminal over: the plugin's {@see connect()} answers a host key or a
 * password once, and keeps whatever lets the IDE reach the server again
 * without asking. Then the project's cache is made — or found, so what was
 * fetched last time is still there — and Porta opens on it.
 */
abstract class OpenRemoteCommand extends PluginCommand
{
    /**
     * @param (\Closure(string): int)|null $launch opens the IDE on a directory and waits for it; null runs this Porta
     * @param string|null                  $home   where caches are kept; null is the user's home
     */
    public function __construct(
        private readonly ?\Closure $launch = null,
        private readonly ?string $home = null,
    ) {
        parent::__construct();
    }

    /** @return list<string> the schemes this command opens; the first is what a bare `host:path` means to it */
    abstract protected function schemes(): array;

    /**
     * Get ready to reach $spec — whatever needs a person at a terminal — and
     * return null, or why it cannot be reached.
     */
    abstract protected function connect(RemoteSpec $spec, InputInterface $input, OutputInterface $output): ?string;

    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        #[Argument('Where the project is: user@host:/path, or a URL')]
        string $location,
    ): int {
        $errors = self::errors($output);
        $spec = RemoteSpec::parse($location);
        if (null !== $spec && !\str_contains($location, '://')) {
            $spec = new RemoteSpec($this->schemes()[0], $spec->host, $spec->path, $spec->user, $spec->port);
        }
        $known = null !== $spec && \in_array($spec->scheme, $this->schemes(), strict: true);
        $home = $known ? $this->home ?? PortaHome::base() : null;

        return match (true) {
            null === $spec || !$known => self::fail($errors, \sprintf('Not a %s location: %s', \implode('/', $this->schemes()), OutputFormatter::escape($location))),
            null === $home => self::fail($errors, 'No home directory to keep the project in (set HOME)'),
            default => $this->connectAndOpen($spec, $home, $input, $output, $errors),
        };
    }

    /** Say why on $errors, and fail. */
    private static function fail(OutputInterface $errors, string $message): int
    {
        $errors->writeln('<error>'.$message.'</error>');

        return self::FAILURE;
    }

    /** Get ready to reach $spec, make or find its cache under $home, and open Porta on it. */
    private function connectAndOpen(RemoteSpec $spec, string $home, InputInterface $input, OutputInterface $output, OutputInterface $errors): int
    {
        $failure = $this->connect($spec, $input, $output);
        if (null !== $failure) {
            return self::fail($errors, OutputFormatter::escape($failure));
        }
        $root = ProjectLocation::prepare($spec, $home);
        // The first-run card walks through setting a project up on this
        // machine; this one is the server's, set up there.
        new OnboardingState($root)->markWelcomeSeen();
        $output->writeln(\sprintf('Opening <info>%s</info>…', OutputFormatter::escape($spec->label())));

        return ($this->launch ?? self::launchPorta(...))($root);
    }

    /**
     * This Porta, on the terminal this command runs in, until it quits. The
     * tests hand the command a launcher of their own: a real IDE needs a
     * real terminal.
     */
    // @codeCoverageIgnoreStart
    private static function launchPorta(string $root): int
    {
        $pipes = [];
        $process = \proc_open([\PHP_BINARY, PortaBinary::path(), $root], [\STDIN, \STDOUT, \STDERR], $pipes, env_vars: ProcessEnvironment::withoutRestart(\getenv()));

        return false === $process ? 1 : \proc_close($process);
    }

    // @codeCoverageIgnoreEnd
}
