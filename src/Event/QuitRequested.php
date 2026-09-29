<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * Quit the IDE, the way Ctrl+Q does — unsaved buffers still ask first.
 *
 * A command, so exactly one listener performs it
 * ({@see \Porta\Workbench\WorkbenchActions}). Observable so a plugin can
 * {@see \Porta\Plugin\Api\PluginApi::emit()} it: the git plugin ends a difftool
 * session this way when its comparison is closed, since git is waiting for the
 * process to exit before it moves to the next file.
 */
final readonly class QuitRequested implements PluginObservable {}
