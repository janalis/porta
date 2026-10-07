<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * Whether the argument a parameter-name hint sits before is a literal — the
 * rule PhpStorm shows parameter names by: `f(true, 3, 'x', null, [])` says
 * nothing about what its arguments are for, `f($user, count($rows))` names
 * them already.
 *
 * Read from the line as it is now, at the hint's column: a number (signed or
 * not), a string or heredoc, an array, or null / true / false in any case and
 * fully qualified or not. `new Foo` is not a literal, as in PhpStorm.
 */
final class InlayArgument
{
    private const string LITERAL = '/^(?:[-+]?\.?\d|[\'"]|<<<|\[|array\s*\(|\\\\?(?:null|true|false)(?![\w\\\\]))/i';

    public static function isLiteral(string $line, int $byteCol): bool
    {
        return 1 === \preg_match(self::LITERAL, \ltrim(\substr($line, $byteCol), characters: " \t"));
    }
}
