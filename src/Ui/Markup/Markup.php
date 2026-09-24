<?php

declare(strict_types=1);

namespace Porta\Ui\Markup;

/**
 * Text that means its tags: the opt-in a message takes to be read as
 * {@see MarkupParser} markup. A bare string stays literal wherever a Markup is
 * also accepted, so a file name or a server's message can never open a tag —
 * only code that says {@see of()} does, and it escapes what it splices in.
 */
final readonly class Markup
{
    private function __construct(
        public string $source,
    ) {}

    public static function of(string $markup): self
    {
        return new self($markup);
    }

    /** $text shown exactly as written, whatever `<` it holds. */
    public static function literal(string $text): self
    {
        return new self(MarkupParser::escape($text));
    }

    /** A bare string read as literal text, a Markup as itself. */
    public static function from(string|self $message): self
    {
        return \is_string($message) ? self::literal($message) : $message;
    }

    /** This markup followed by $more — a string read as literal text, as in {@see from()}. */
    public function append(string|self $more): self
    {
        return new self($this->source.self::from($more)->source);
    }

    /** The text alone, for a log, a width or a plain-text surface. */
    public function plain(): string
    {
        return MarkupParser::strip($this->source);
    }
}
