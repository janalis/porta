<?php

declare(strict_types=1);

namespace Porta\Plugin\Api;

/**
 * What calling a tool does to the user's world — declared with
 * {@see PluginApi::registerTool()} so whatever serves the tool can tell its
 * caller. An agent's client uses it to decide what to run without asking:
 * reading, yes; running the tests, only once the user said so.
 *
 * One answer per tool, the strongest thing it may do. A tool registered
 * without one says nothing, and a careful client treats that as the worst.
 */
enum ToolEffect
{
    /** Only reads — the IDE, the project, a forge. Calling it twice changes nothing. */
    case Reads;

    /**
     * Changes the IDE and nothing on disk — opens a tab, shows a message,
     * edits a buffer the user then keeps with a save or takes back with an undo.
     */
    case Edits;

    /** Changes the machine — writes a file, runs a process — and nothing takes it back. */
    case Writes;

    /** Says something where other people see it: a comment on a forge, a message sent. */
    case Publishes;
}
