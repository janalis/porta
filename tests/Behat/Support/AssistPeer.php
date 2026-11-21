<?php

declare(strict_types=1);

namespace Porta\Tests\Behat\Support;

use Janalis\Tuink\Session;
use PHPUnit\Framework\Assert;
use Porta\AssistPlugin\Net\Rendezvous\Code;
use Porta\AssistPlugin\Net\Rendezvous\Invite;
use Porta\AssistPlugin\Net\Wire;
use Porta\AssistPlugin\Session\GuestSession;
use Porta\AssistPlugin\Tests\Support\TalksAssist;

/**
 * The other end of an assist session: a guest of the IDE under test, or the
 * host an IDE opened on a guest's copy joins — meeting it through the assist
 * plugin's own fake magic-wormhole, speaking the wire with the plugin's E2e
 * trait, waiting through tuink so the in-process IDE's loop runs meanwhile.
 */
final class AssistPeer extends Assert
{
    // @mago-expect analysis:non-existent-class-like -- the assist plugin's E2e support, which the analyzer leaves out with the plugin's tests
    use TalksAssist;

    /** The ticket a guest's copy is let back in with. */
    private const string TICKET = '0123456789abcdef0123456789abcdef';

    private ?Invite $invite = null;

    /** @var list<mixed> the connections of guests who came before the one talking now, kept open */
    private array $earlier = [];

    public function __construct(
        private readonly Session $session,
    ) {}

    public function __destruct()
    {
        $this->hangUp();
        $this->forgetCertificate();
    }

    public function mailbox(): string
    {
        return $this->fake()->mailbox;
    }

    public function transit(): string
    {
        return $this->fake()->transit;
    }

    /** @return object{mailbox: string, transit: string} the trait's fake magic-wormhole */
    private function fake(): object
    {
        // @mago-expect lint:inline-variable-return -- the @var is the only type the analyzer gets: the trait's class is hidden from it
        /** @var object{mailbox: string, transit: string} $wormhole */
        $wormhole = $this->wormhole();

        return $wormhole;
    }

    /** Be a guest named $name: redeem $code with the host, as `porta assist <code>` does, and ask in. */
    public function askToJoin(string $code, string $name): void
    {
        if (null === $this->invite) {
            /** @var mixed $invite */
            $invite = $this->redeem(Code::parse($code));
            \assert($invite instanceof Invite, description: 'the host answered the code');
            $this->invite = $invite;
        }
        $this->askIn($this->invite, $name);
    }

    /**
     * Another guest at the door the first one opened, saying hello; the
     * first one's connection is kept open, so it stays in.
     */
    public function knock(string $name): void
    {
        \assert(null !== $this->invite, description: 'a guest asked to join first');
        $this->earlier[] = $this->peer;
        $this->dial($this->invite);
        $this->say(['t' => 'hello', 'v' => Wire::VERSION, 'name' => $name, 'token' => $this->invite->token]);
    }

    /**
     * Be the host a guest's copy joins: wait at the relay, and store in
     * $root the invite `porta assist` would have stored there for "Bo".
     */
    public function hostFor(string $root): void
    {
        /** @var mixed $invite */
        $invite = $this->listen();
        \assert($invite instanceof Invite, description: 'the trait makes one');
        GuestSession::store($root, ['invite' => $invite, 'ticket' => self::TICKET, 'name' => 'Bo', 'languages' => []]);
    }

    /** As the host: take the guest IDE's connection, hear its hello, and let it in as $role. */
    public function letIn(string $role, string $hostName): void
    {
        $this->acceptGuest();
        $this->expect('hello');
        $this->say(['t' => 'welcome', 'peer' => 'g1', 'role' => $role, 'ticket' => self::TICKET, 'peers' => [['id' => 'host', 'name' => $hostName, 'role' => 'host']]]);
    }

    /** @return array<array-key, mixed> the next message of $type the other end sends */
    public function hear(string $type): array
    {
        /** @var mixed $message */
        $message = $this->expect($type);
        \assert(\is_array($message), description: 'a message is an object on the wire');

        return $message;
    }

    /** @param array<array-key, mixed> $message */
    public function tell(array $message): void
    {
        $this->say($message);
    }

    private function await(callable $done, float $timeout = 10.0, string $what = 'condition'): void
    {
        $this->session->waitUntil(static fn (): bool => true === $done(), \sprintf('Waited in vain for %s.', $what), $timeout);
    }
}
