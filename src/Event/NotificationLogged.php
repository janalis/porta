<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * A status-bar flash was appended to the notification log. WARNING: this
 * dispatches from inside Notifier::flash — a listener must never flash
 * back, or it recurses.
 */
final readonly class NotificationLogged {}
