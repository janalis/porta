<?php

declare(strict_types=1);

namespace Porta\FtpPlugin\Console;

use Porta\Config\PortaHome;
use Porta\Filesystem\RemoteSpec;
use Porta\FtpPlugin\Credentials;
use Porta\FtpPlugin\FtpTransport;
use Porta\Plugin\Api\OpenRemoteCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Process\Process;

/**
 * `porta ftp ftp://user@host/path` — open a project on an FTP server.
 *
 * Asks for the password here, hidden, keeps it where only this user can
 * read it ({@see Credentials}), and logs in once to be sure of it before the
 * IDE opens — a wrong password is said here, not as a failed listing later.
 * A URL with no user logs in anonymously.
 */
#[AsCommand(
    name: 'ftp',
    description: 'Open a project on an FTP or FTPS server',
)]
final class OpenCommand extends OpenRemoteCommand
{
    /**
     * @param (\Closure(string): int)|null                           $launch opens the IDE on a directory and waits for it; null runs this Porta
     * @param string|null                                            $home   where caches and passwords are kept; null is the user's home
     * @param (\Closure(list<string>): array{int, string})|null $curl   runs curl and answers its exit code and stderr; null runs it
     */
    public function __construct(
        ?\Closure $launch = null,
        private readonly ?string $home = null,
        private readonly ?\Closure $curl = null,
    ) {
        parent::__construct($launch, $home);
        $this->setHelp(<<<'HELP'
            Opens a project that lives on an FTP server:

              <info>porta ftp ftp://me@example.org/public_html</info>
              <info>porta ftp ftps://me@example.org:2121/site</info>

            <info>ftps://</info> is FTP with TLS required on the FTP port (explicit TLS).
            The password is asked for here, and kept in <info>~/.porta/ftp/</info> where only
            you can read it; a URL without a user logs in anonymously.

            The project is kept under <info>~/.porta/remote/</info>: a file is fetched the
            first time you open it, and every save is sent back to the server.
            HELP);
    }

    #[\Override]
    protected function schemes(): array
    {
        return ['ftp', 'ftps'];
    }

    #[\Override]
    protected function connect(RemoteSpec $spec, InputInterface $input, OutputInterface $output): ?string
    {
        $home = (string) ($this->home ?? PortaHome::base());
        $netrc = null;
        if (null !== $spec->user) {
            $question = new Question(\sprintf('Password for %s@%s: ', $spec->user, $spec->host))
                ->setHidden(true)
                ->setHiddenFallback(false);
            // @mago-expect analysis:mixed-assignment -- whatever was typed: narrowed below
            $password = new QuestionHelper()->ask($input, $output, $question);
            $netrc = Credentials::store($home, $spec, \is_string($password) ? $password : '');
            if (null === $netrc) {
                return \sprintf('Cannot keep the password in %s', \dirname(Credentials::file($home, $spec)));
            }
        }
        $transport = new FtpTransport($spec, $netrc);
        [$code, $error] = ($this->curl ?? self::login(...))($transport->command(['--list-only', '--output', '/dev/null'], $transport->directoryUrl('')));
        if (0 === $code) {
            return null;
        }
        Credentials::forget($home, $spec); // a password that did not log in is not worth keeping

        return \sprintf('Cannot log in to %s: %s', $spec->host, '' === \trim($error) ? 'curl exited with '.$code : \trim($error));
    }

    /**
     * @param list<string> $command
     *
     * @return array{int, string}
     */
    // @codeCoverageIgnoreStart
    private static function login(array $command): array
    {
        $process = new Process($command, timeout: 60.0);
        $process->run();

        return [(int) $process->getExitCode(), $process->getErrorOutput()];
    }

    // @codeCoverageIgnoreEnd
}
