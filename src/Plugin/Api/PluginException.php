<?php

declare(strict_types=1);

namespace Porta\Plugin\Api;

/**
 * Thrown by {@see PluginApi} on misuse: an action method called during
 * `activate()` (before the IDE is up), or an invalid/duplicate command id.
 * The loader catches it per plugin and reports a boot warning.
 */
final class PluginException extends \RuntimeException {}
