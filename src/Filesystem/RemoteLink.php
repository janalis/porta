<?php

declare(strict_types=1);

namespace Porta\Filesystem;

use Porta\Support\Backoff;
use Revolt\EventLoop;

/**
 * Whether a server is reachable, as far as one project knows — and what
 * waits until it is again.
 *
 * A server that stops answering is not a server that said no. When a call
 * fails, the project's root is listed: if that fails too, the link is DOWN —
 * what failed waits here, and the root is listed again after a backoff until
 * it answers, when everything that waited goes again. Only when the root
 * still lists was the failure the server's refusal. A refused LOGIN is asked
 * again only after a long pause, or when told to: a server that counts
 * refused logins may lock the account out, and a lock-out is worse than a wait.
 */
final class RemoteLink
{
    /** Seconds before a server that refused the login is asked again unprompted. */
    public const float LOGIN_PAUSE = 300.0;

    /** Why the server cannot be reached, while it cannot. */
    private ?string $down = null;
    /** How many times in a row the root has been listed and failed. */
    private int $tries = 0;
    private bool $loginRefused = false;
    /** The timer that lists the root again, while the link is down. */
    private ?string $probe = null;

    /** Whether the root is being listed right now: never twice at once — each is a login. */
    private bool $probing = false;
    /** @var list<\Closure(): void> what waits for the link: each runs again once it is back */
    private array $waiting = [];
    /**
     * Failures being checked against the root's listing: the link, or the server's refusal?
     *
     * @var list<array{retry: \Closure(): void, refused: \Closure(): void}>
     */
    private array $checks = [];

    /**
     * @param \Closure(string): void            $lost    the link just went down, and why
     * @param \Closure(list<RemoteEntry>): void $back    it is back — with the root's entries, as just listed
     * @param \Closure(): void                  $changed what the link's state looks like moved
     */
    public function __construct(
        private readonly \Closure $lost,
        private readonly \Closure $back,
        private readonly \Closure $changed,
    ) {}

    /** Why the server cannot be reached, or null while it can. */
    public function down(): ?string
    {
        return $this->down;
    }

    /** Whether what keeps it down is the server refusing the login. */
    public function loginRefused(): bool
    {
        return $this->loginRefused;
    }

    /**
     * A call to the server failed with $why. Down already: $retry waits.
     * Else the root is listed — once, however many failed together — and the
     * link is down ($retry waits) or the server meant it ($refused runs).
     *
     * @param \Closure(): void $retry
     * @param \Closure(): void $refused
     */
    public function failed(RemoteTransport $transport, string $why, \Closure $retry, \Closure $refused): void
    {
        if (null !== $this->down) {
            $this->waiting[] = $retry;

            return;
        }
        $this->checks[] = ['retry' => $retry, 'refused' => $refused];
        if (\count($this->checks) > 1) {
            return; // the root is being listed already
        }
        $transport->list('', function (array|string $answer) use ($transport, $why): void {
            $checks = $this->checks;
            $this->checks = [];
            if (\is_string($answer)) {
                foreach ($checks as $check) {
                    $this->waiting[] = $check['retry'];
                }
                $this->lose($transport, $why);

                return;
            }
            foreach ($checks as $check) {
                $check['refused']();
            }
        });
    }

    /** List the root now rather than when the backoff says: asked to, or what kept it down may be gone. */
    public function reconnect(RemoteTransport $transport): void
    {
        if (null === $this->down) {
            return;
        }
        $this->cancel();
        $this->probe($transport);
    }

    /** The project is closing: nothing waits, and nothing is listed again. */
    public function close(): void
    {
        $this->cancel();
        $this->down = null;
        $this->waiting = [];
        $this->checks = [];
    }

    private function lose(RemoteTransport $transport, string $why): void
    {
        $this->down = $why;
        $this->tries = 0;
        $this->loginRefused = $transport->refusesLogin($why);
        ($this->lost)($why);
        ($this->changed)();
        $this->later($transport);
    }

    private function later(RemoteTransport $transport): void
    {
        $delay = $this->loginRefused ? self::LOGIN_PAUSE : Backoff::after($this->tries);
        $this->probe = EventLoop::unreference(EventLoop::delay($delay, function () use ($transport): void {
            $this->probe = null;
            $this->probe($transport);
        }));
    }

    private function probe(RemoteTransport $transport): void
    {
        if ($this->probing) {
            return; // already asking: its answer is the one this would get
        }
        $this->probing = true;
        $transport->list('', function (array|string $answer) use ($transport): void {
            $this->probing = false;
            if (null === $this->down) {
                return; // closed meanwhile, or another listing got there first
            }
            if (\is_string($answer)) {
                ++$this->tries;
                $this->down = $answer;
                $this->loginRefused = $transport->refusesLogin($answer);
                ($this->changed)();
                $this->later($transport);

                return;
            }
            $this->down = null;
            $this->loginRefused = false;
            /** @var list<RemoteEntry> $answer */
            ($this->back)($answer);
            ($this->changed)();
            $waiting = $this->waiting;
            $this->waiting = [];
            foreach ($waiting as $retry) {
                $retry();
            }
        });
    }

    private function cancel(): void
    {
        if (null !== $this->probe) {
            EventLoop::cancel($this->probe);
            $this->probe = null;
        }
    }
}
