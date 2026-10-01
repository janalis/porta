<?php

declare(strict_types=1);

namespace Porta\Presence;

/**
 * What one Porta says about itself to the others on the same project: one
 * small JSON file each, on the board they share ({@see PresenceBoard}).
 *
 * Everything in one may come from another person's machine, so reading one
 * trusts nothing: an id is a fixed shape (it names a file), a name is cut to
 * {@see self::MAX_NAME} characters with no control or format characters (it
 * is drawn in the terminal — an escape in it would be written to the
 * screen), a path is relative to the project with no `..` in it (each reader
 * puts it under its own root: the roots differ from one machine to the
 * next), and anything else malformed makes no beacon at all.
 *
 * The position is the editor's: a 0-based line, negative for a file with no
 * caret (an image), and byte column; the anchor is where a selection began,
 * negative for none. `$beat` is when it was written, by the writer's clock.
 */
final readonly class Beacon
{
    public const int MAX_NAME = 40;

    /** How another Porta can reach the same files. */
    public const array VIAS = ['local', 'ssh', 'ftp'];

    private const string ID = '/^[0-9a-f]{16}$/';

    public function __construct(
        public string $id,
        public string $name,
        public string $via,
        public ?string $path,
        public int $line,
        public int $column,
        public int $anchorLine,
        public int $anchorColumn,
        public int $beat,
    ) {}

    /** A fresh id, for one Porta's whole run. */
    public static function newId(): string
    {
        return \bin2hex(\random_bytes(8));
    }

    /** Whether $id is the shape of one — what keeps a file name built from it inside the board. */
    public static function isId(string $id): bool
    {
        return 1 === \preg_match(self::ID, $id);
    }

    /** $text with no control or format characters, trimmed and cut to $max characters. */
    public static function clean(string $text, int $max = self::MAX_NAME): string
    {
        return \mb_substr(\trim((string) \preg_replace('/[\p{Cc}\p{Cf}]+/u', replacement: '', subject: $text)), start: 0, length: $max);
    }

    public function toJson(): string
    {
        return \json_encode(
            [
                'id' => $this->id,
                'name' => $this->name,
                'via' => $this->via,
                'path' => $this->path,
                'line' => $this->line,
                'col' => $this->column,
                'anchorLine' => $this->anchorLine,
                'anchorCol' => $this->anchorColumn,
                'beat' => $this->beat,
            ],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
    }

    /** The beacon $json is, or null when it is not one. */
    public static function fromJson(string $json): ?self
    {
        // @mago-expect analysis:mixed-assignment -- another machine's file: every field is narrowed below
        $data = \json_decode($json, associative: true);
        if (!\is_array($data)) {
            return null;
        }
        $strings = \array_map(static fn (mixed $value): ?string => \is_string($value) ? $value : null, \array_intersect_key($data, ['id' => 0, 'name' => 0, 'via' => 0, 'path' => 0]));
        $ints = [];
        foreach (['line', 'col', 'anchorLine', 'anchorCol', 'beat'] as $key) {
            // @mago-expect analysis:mixed-assignment -- narrowed on the next line
            $value = $data[$key] ?? null;
            if (!\is_int($value)) {
                return null;
            }
            $ints[$key] = $value;
        }
        $id = $strings['id'] ?? '';
        $name = self::clean($strings['name'] ?? '');
        $via = $strings['via'] ?? '';
        $path = $strings['path'] ?? null;
        if (!self::isId($id) || '' === $name || !\in_array($via, self::VIAS, strict: true)) {
            return null;
        }

        return new self($id, $name, $via, null === $path ? null : self::relative($path), $ints['line'], $ints['col'], $ints['anchorLine'], $ints['anchorCol'], $ints['beat']);
    }

    /** $path when it names a file inside a project — relative, every segment a name — else null. */
    private static function relative(string $path): ?string
    {
        foreach (\explode('/', $path) as $segment) {
            if (\in_array($segment, ['', '.', '..'], strict: true) || $segment !== self::clean($segment, \PHP_INT_MAX)) {
                return null;
            }
        }

        return $path;
    }
}
