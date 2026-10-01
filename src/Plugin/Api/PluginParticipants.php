<?php

declare(strict_types=1);

namespace Porta\Plugin\Api;

use Porta\Presence\Beacon;
use Porta\Presence\Participant;
use Porta\Presence\Participants;

/**
 * A plugin's say in core's roster of who else is at work on the project —
 * the status bar's participant count, their names on hover, their file on
 * click, {@see \Porta\Event\ParticipantJoined} and
 * {@see \Porta\Event\ParticipantLeft} — for the people it brought: a
 * session's guests and host, say. From {@see PluginApi::participants()}.
 */
final readonly class PluginParticipants
{
    public function __construct(
        private string $plugin,
        private Participants $participants,
    ) {}

    /**
     * This plugin's people, now. Each list REPLACES the last whole; an empty
     * one takes them all out, as removing the plugin does. Names are cleaned
     * like any name from another machine: no control characters, 40
     * characters at most — and one left empty is no one.
     *
     * @param array<array-key, string> $people id => name — a numeric id is a key PHP made an int
     */
    public function set(array $people): void
    {
        $list = [];
        foreach ($people as $id => $name) {
            $name = Beacon::clean($name);
            if ('' !== $name) {
                $list[] = new Participant((string) $id, $name, $this->plugin);
            }
        }
        $this->participants->set(Participants::pluginSource($this->plugin), $list);
    }

    /**
     * The link to them is lost: they stay as last seen, marked so, for
     * {@see Participants::GRACE} seconds — {@see set()} in that time puts
     * them back, and after it they are taken out.
     */
    public function outOfTouch(): void
    {
        $this->participants->outOfTouch(Participants::pluginSource($this->plugin));
    }

    /** The user's name, as the others see it: the `displayName` setting, else the login name. */
    public function yourName(): string
    {
        return $this->participants->yourName();
    }
}
