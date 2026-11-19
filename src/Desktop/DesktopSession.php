<?php

declare(strict_types=1);

namespace Porta\Desktop;

use Porta\Container\AsIdeService;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * Whether a native desktop host started this Porta, and the secret it shares
 * with it. Without both, every desktop behaviour stays off and Porta is the
 * terminal program it always was.
 *
 * The token is what tells the host's window-close request from bytes that only
 * look like one: a pasted `ESC[201~` ends a bracketed paste early, so whatever
 * follows it in a clipboard arrives as live input.
 */
#[AsIdeService]
#[Autoconfigure(constructor: 'current')]
final readonly class DesktopSession
{
    public const string FLAG = 'PORTA_DESKTOP';

    // @mago-expect lint:no-literal-password -- the variable's name; the secret is generated per launch by the host.
    public const string TOKEN = 'PORTA_DESKTOP_TOKEN';

    /** Set by a host whose operating system shows application menus itself. */
    public const string MENU = 'PORTA_DESKTOP_MENU';

    /** 128 bits, hex: long enough that it cannot be guessed from inside a paste. */
    private const string TOKEN_SHAPE = '/^[0-9a-f]{32,}$/D';

    public function __construct(
        public bool $active,
        #[\SensitiveParameter]
        public string $token,
        public bool $systemMenu = false,
    ) {}

    public static function current(): self
    {
        return self::fromEnvironment(\getenv());
    }

    /** @param array<string, string|false> $environment */
    public static function fromEnvironment(array $environment): self
    {
        $token = $environment[self::TOKEN] ?? '';
        if ('1' !== ($environment[self::FLAG] ?? '') || !\is_string($token) || 1 !== \preg_match(self::TOKEN_SHAPE, $token)) {
            return new self(active: false, token: '');
        }

        return new self(active: true, token: $token, systemMenu: 'system' === ($environment[self::MENU] ?? ''));
    }
}
