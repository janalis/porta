<?php

declare(strict_types=1);

namespace Porta\Plugin\Api;

/**
 * What a language-server resolver answers when it would serve the language
 * and cannot, because its binary is not there — as opposed to null, "not
 * mine to serve here". The status bar then names the server as not found
 * instead of pointing at Settings to enable one, which it already is.
 *
 * {@see PluginApi::registerLanguageServer()}
 */
final readonly class ServerNotFound {}
