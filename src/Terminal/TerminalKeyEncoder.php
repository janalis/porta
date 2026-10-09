<?php

declare(strict_types=1);

namespace Porta\Terminal;

use Symfony\Component\Tui\Input\KeyParser;
use Symfony\Component\Tui\Tui;

/**
 * Translates the IDE's keyboard input into the legacy terminal bytes a shell
 * expects, so keystrokes forwarded to the integrated terminal's PTY are clean.
 *
 * When the kitty keyboard protocol is active the host terminal sends key-release
 * events and CSI-u sequences that a plain PTY does not understand; forwarding
 * them verbatim shows up as garbage. This drops release events and re-encodes
 * each key as its legacy byte sequence. When the protocol is inactive (the common
 * case) input already is legacy and is forwarded untouched.
 */
final readonly class TerminalKeyEncoder
{
    /** @var array<string, string> arrow / home / end → final CSI letter */
    private const array CSI_LETTER = ['up' => 'A', 'down' => 'B', 'right' => 'C', 'left' => 'D', 'home' => 'H', 'end' => 'F'];

    /** @var array<string, int> editing keys → CSI "~" number */
    private const array CSI_TILDE = ['insert' => 2, 'delete' => 3, 'page_up' => 5, 'page_down' => 6];

    public function __construct(
        private Tui $tui,
        private KeyParser $parser = new KeyParser(),
    ) {
        $this->parser->setKittyProtocolActive(true);
    }

    /** Legacy bytes to forward to the PTY for $data, or '' to drop it. */
    public function encode(string $data): string
    {
        return match (true) {
            !$this->tui->getTerminal()->isKittyProtocolActive() => $data, // legacy terminal already sends legacy bytes
            $this->parser->isKeyRelease($data) => '', // a shell has no use for key-release events
            default => $this->translate($data),
        };
    }

    /** Legacy bytes for a kitty-protocol key press. */
    private function translate(string $data): string
    {
        $parsed = $this->parser->parse($data);
        if (null === $parsed) {
            // Drop unrecognised CSI-u (media/function keys); forward plain text and
            // legacy sequences untouched.
            return \str_starts_with($data, "\x1b[") && \str_ends_with($data, 'u') ? '' : $data;
        }

        return $this->toLegacyBytes($parsed['key']);
    }

    private function toLegacyBytes(string $name): string
    {
        $parts = \explode('+', $name);
        $base = \array_pop($parts);
        $shift = \in_array('shift', $parts, strict: true);
        $alt = \in_array('alt', $parts, strict: true);
        $ctrl = \in_array('ctrl', $parts, strict: true);
        $modifier = 1 + ($shift ? 1 : 0) + ($alt ? 2 : 0) + ($ctrl ? 4 : 0);

        $simple = match ($base) {
            'enter' => "\r",
            'space' => $ctrl ? "\x00" : ' ',
            'escape' => "\x1b",
            'backspace' => "\x7f",
            default => null,
        };

        return match (true) {
            \array_key_exists($base, self::CSI_LETTER) => $modifier > 1 ? "\x1b[1;{$modifier}".self::CSI_LETTER[$base] : "\x1b[".self::CSI_LETTER[$base],
            \array_key_exists($base, self::CSI_TILDE) => $modifier > 1 ? "\x1b[".self::CSI_TILDE[$base].";{$modifier}~" : "\x1b[".self::CSI_TILDE[$base].'~',
            'tab' === $base && $shift => "\x1b[Z",
            'tab' === $base => $alt ? "\x1b\t" : "\t",
            null !== $simple => $alt ? "\x1b".$simple : $simple,
            default => $this->printable($base, $parts),
        };
    }

    /**
     * A printable character key, under its modifiers.
     *
     * @param list<string> $modifiers
     */
    private function printable(string $base, array $modifiers): string
    {
        $char = \in_array('shift', $modifiers, strict: true) && \ctype_alpha($base) ? \strtoupper($base) : $base;
        $ctrl = \in_array('ctrl', $modifiers, strict: true);
        if ($ctrl) {
            $char = $this->control($char);
        }

        // ctrl+<key> with no control-byte equivalent is dropped: '' either way.
        return ($ctrl && '' === $char) || !\in_array('alt', $modifiers, strict: true) ? $char : "\x1b".$char;
    }

    /** The control byte for Ctrl+$char, or '' when there is none. */
    private function control(string $char): string
    {
        $upper = \strtoupper($char);
        if ($upper >= 'A' && $upper <= 'Z') {
            return \chr(\ord($upper[0]) - 64);
        }

        return match ($char) {
            '@' => "\x00",
            '[' => "\x1b",
            '\\' => "\x1c",
            ']' => "\x1d",
            '^' => "\x1e",
            '_' => "\x1f",
            '?' => "\x7f",
            default => '',
        };
    }
}
