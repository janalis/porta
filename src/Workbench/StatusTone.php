<?php

declare(strict_types=1);

namespace Porta\Workbench;

/**
 * How prominent a status segment is — the contributor's whole say in how it
 * looks.
 *
 * Deliberately not a {@see \Porta\Ui\Role}. Core maps a tone onto a role, so
 * the three themes keep deciding the colour and a contributor cannot invent
 * one, which is what the theme lock exists to prevent. Three cases because three
 * are used; a tone nothing asks for is a colour nobody chose.
 */
enum StatusTone
{
    /** Ambient, low-contrast — the default for anything reporting background state. */
    case Subtle;

    /** Picked out from the prefix: state the user acts ON, and can click. */
    case Accent;

    /** A session is live — the colour that says something is running right now. */
    case Active;
}
