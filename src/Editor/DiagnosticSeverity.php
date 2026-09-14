<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Ui\Glyphs;
use Porta\Ui\Role;

/**
 * An LSP DiagnosticSeverity, once it is inside the editor.
 *
 * The ints stay at the edges: a server sends them, a plugin passes them to
 * {@see \Porta\Plugin\Api\PluginApi::setDiagnostics()}, and a code-action
 * request echoes them back ({@see \Porta\Lsp\RefactorController}). Everything
 * between holds a case, which is what retires the three conventions the ints
 * needed — 0 for "none", `min()` for "worst", and a clamp every reader had to
 * remember to apply.
 *
 * Unlike {@see \Porta\Lsp\DiagnosticTag}, whose unknown values are kept as
 * ints because an unrecognised tag means "draw it normally", an unknown
 * severity has always been DRAWN as a hint. Converting once at ingestion keeps
 * that meaning and stops asking every reader to re-derive it.
 */
enum DiagnosticSeverity: int
{
    case Error = 1;
    case Warning = 2;
    case Information = 3;
    case Hint = 4;

    /**
     * The severity a server or plugin sent. Absent means an error — the LSP
     * default, and what an analyzer that names no severity has always meant
     * here; anything outside 1..4, or not a number at all, is a hint.
     */
    public static function fromWire(mixed $raw): self
    {
        if (null === $raw) {
            return self::Error;
        }

        if (!\is_int($raw)) {
            $raw = \is_numeric($raw) ? (int) $raw : 0;
        }

        return self::tryFrom($raw) ?? self::Hint;
    }

    /** The more severe of the two (an error outranks everything). */
    public function worse(self $other): self
    {
        return $this->value <= $other->value ? $this : $other;
    }

    /** Whether this is strictly more severe than $other. */
    public function outranks(self $other): bool
    {
        return $this->value < $other->value;
    }

    /**
     * The glyph in the gutter marker column and before the inline message, so
     * severity reads without color (matches the status bar's ✗/▲ counts). One
     * display column wide.
     */
    public function glyph(): string
    {
        return match ($this) {
            self::Error => Glyphs::SEVERITY_ERROR,
            self::Warning => Glyphs::SEVERITY_WARNING,
            self::Information => Glyphs::SEVERITY_INFO,
            self::Hint => Glyphs::SEVERITY_HINT,
        };
    }

    /** The right-stripe marker: block density mirrors severity without color. */
    public function mark(): string
    {
        return match ($this) {
            self::Error => Glyphs::STRIPE_ERROR,
            self::Warning => Glyphs::STRIPE_WARNING,
            self::Information => Glyphs::STRIPE_INFO,
            self::Hint => Glyphs::STRIPE_HINT,
        };
    }

    /** The foreground role (gutter line number, stripe mark). */
    public function role(): Role
    {
        return match ($this) {
            self::Error => Role::DiagError,
            self::Warning => Role::DiagWarn,
            self::Information => Role::DiagInfo,
            self::Hint => Role::DiagHint,
        };
    }

    /** The dimmer foreground role (inline end-of-line message). */
    public function dimRole(): Role
    {
        return match ($this) {
            self::Error => Role::DiagErrorDim,
            self::Warning => Role::DiagWarnDim,
            self::Information => Role::DiagInfoDim,
            self::Hint => Role::DiagHintDim,
        };
    }
}
