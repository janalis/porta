<?php

declare(strict_types=1);

namespace Porta\Desktop;

/**
 * The frames of the window's protocol, on both sides of the IDE's boot: the
 * project picker talks to the window before {@see DesktopProtocol} exists.
 *
 * A frame is `OSC 7777;porta-desktop;<token>;<message> ST`. The whole
 * namespace is the window's, so input that starts with it is never text;
 * only the session's token makes it a request.
 */
final readonly class DesktopFrames
{
    private const string PREFIX = "\x1b]7777;porta-desktop;";

    public function __construct(
        private DesktopSession $session,
    ) {}

    /** Whether $input is in the window's namespace, and so never anyone else's to read. */
    public static function isFrame(string $input): bool
    {
        return \str_starts_with($input, self::PREFIX);
    }

    /** What the window asked, past the token that proves it was the window; null when the token is not the session's. */
    public function request(string $input): ?string
    {
        $own = self::PREFIX.$this->session->token.';';

        return \hash_equals($own, \substr($input, offset: 0, length: \strlen($own))) ? \substr($input, \strlen($own)) : null;
    }

    /** $message as the window reads it. */
    public function frame(string $message): string
    {
        return self::PREFIX.$this->session->token.';'.$message."\x1b\\";
    }

    /** The path an `open` request names; null when $request is not one. */
    public static function openPath(string $request): ?string
    {
        $match = [];
        $path = 1 === \preg_match('/^open;([A-Za-z0-9+\/=]+)\x1b\\\\$/D', $request, $match) ? \base64_decode($match[1], strict: true) : false;

        return false === $path || '' === $path ? null : $path;
    }
}
