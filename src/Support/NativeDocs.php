<?php

declare(strict_types=1);

namespace Porta\Support;

/**
 * Official-documentation URLs for native symbols, keyed by the buffer's
 * language. PHP is decided by reflection in the IDE's own runtime — internal
 * functions/classes cannot be redeclared in userland, so a positive answer is
 * authoritative. The other languages carry curated built-in maps (extendable;
 * bash/sql/yaml have none yet). Hyphenated CSS properties are absent because
 * the editor's word extraction stops at `[\w$]`.
 */
final class NativeDocs
{
    private const string MDN = 'https://developer.mozilla.org/en-US/docs/';

    /** JS/TS globals and common Web APIs → MDN path suffix. */
    private const array JS_BUILTINS = [
        'Array' => 'Web/JavaScript/Reference/Global_Objects/Array',
        'Object' => 'Web/JavaScript/Reference/Global_Objects/Object',
        'JSON' => 'Web/JavaScript/Reference/Global_Objects/JSON',
        'Math' => 'Web/JavaScript/Reference/Global_Objects/Math',
        'Promise' => 'Web/JavaScript/Reference/Global_Objects/Promise',
        'Map' => 'Web/JavaScript/Reference/Global_Objects/Map',
        'Set' => 'Web/JavaScript/Reference/Global_Objects/Set',
        'WeakMap' => 'Web/JavaScript/Reference/Global_Objects/WeakMap',
        'WeakSet' => 'Web/JavaScript/Reference/Global_Objects/WeakSet',
        'Symbol' => 'Web/JavaScript/Reference/Global_Objects/Symbol',
        'Proxy' => 'Web/JavaScript/Reference/Global_Objects/Proxy',
        'Reflect' => 'Web/JavaScript/Reference/Global_Objects/Reflect',
        'Date' => 'Web/JavaScript/Reference/Global_Objects/Date',
        'RegExp' => 'Web/JavaScript/Reference/Global_Objects/RegExp',
        'Error' => 'Web/JavaScript/Reference/Global_Objects/Error',
        'TypeError' => 'Web/JavaScript/Reference/Global_Objects/TypeError',
        'Number' => 'Web/JavaScript/Reference/Global_Objects/Number',
        'String' => 'Web/JavaScript/Reference/Global_Objects/String',
        'Boolean' => 'Web/JavaScript/Reference/Global_Objects/Boolean',
        'BigInt' => 'Web/JavaScript/Reference/Global_Objects/BigInt',
        'Intl' => 'Web/JavaScript/Reference/Global_Objects/Intl',
        'ArrayBuffer' => 'Web/JavaScript/Reference/Global_Objects/ArrayBuffer',
        'DataView' => 'Web/JavaScript/Reference/Global_Objects/DataView',
        'globalThis' => 'Web/JavaScript/Reference/Global_Objects/globalThis',
        'parseInt' => 'Web/JavaScript/Reference/Global_Objects/parseInt',
        'parseFloat' => 'Web/JavaScript/Reference/Global_Objects/parseFloat',
        'isNaN' => 'Web/JavaScript/Reference/Global_Objects/isNaN',
        'isFinite' => 'Web/JavaScript/Reference/Global_Objects/isFinite',
        'encodeURIComponent' => 'Web/JavaScript/Reference/Global_Objects/encodeURIComponent',
        'decodeURIComponent' => 'Web/JavaScript/Reference/Global_Objects/decodeURIComponent',
        'structuredClone' => 'Web/API/Window/structuredClone',
        'queueMicrotask' => 'Web/API/Window/queueMicrotask',
        'console' => 'Web/API/console',
        'fetch' => 'Web/API/Window/fetch',
        'setTimeout' => 'Web/API/Window/setTimeout',
        'setInterval' => 'Web/API/Window/setInterval',
        'clearTimeout' => 'Web/API/Window/clearTimeout',
        'clearInterval' => 'Web/API/Window/clearInterval',
        'localStorage' => 'Web/API/Window/localStorage',
        'document' => 'Web/API/Document',
        'window' => 'Web/API/Window',
    ];

    /** Single-token CSS properties (hyphenated ones do not survive word extraction). */
    private const array CSS_PROPERTIES = [
        'display',
        'position',
        'color',
        'margin',
        'padding',
        'border',
        'width',
        'height',
        'top',
        'right',
        'bottom',
        'left',
        'opacity',
        'transform',
        'transition',
        'overflow',
        'cursor',
        'float',
        'clear',
        'content',
        'gap',
        'flex',
        'grid',
        'animation',
        'filter',
        'visibility',
        'resize',
        'rotate',
        'scale',
        'translate',
    ];

    /** Python builtin functions (docs.python.org/3/library/functions.html anchors). */
    private const array PYTHON_BUILTINS = [
        'len',
        'print',
        'range',
        'dict',
        'list',
        'set',
        'tuple',
        'str',
        'int',
        'float',
        'bool',
        'type',
        'isinstance',
        'enumerate',
        'zip',
        'map',
        'filter',
        'sorted',
        'reversed',
        'sum',
        'min',
        'max',
        'abs',
        'round',
        'open',
        'input',
        'super',
        'id',
        'hash',
        'iter',
        'next',
        'repr',
        'format',
        'vars',
        'getattr',
        'setattr',
        'hasattr',
        'callable',
        'any',
        'all',
    ];

    /** The official docs URL for a native (language, word) pair, or null. */
    public static function urlFor(?string $language, string $word): ?string
    {
        if (null === $language || '' === $word || \str_starts_with($word, '$')) {
            return null;
        }

        $mdnSuffix = self::JS_BUILTINS[$word] ?? null;

        return match ($language) {
            'php' => self::phpUrl($word),
            'javascript', 'typescript' => null === $mdnSuffix ? null : self::MDN.$mdnSuffix,
            'css', 'scss' => \in_array($word, self::CSS_PROPERTIES, strict: true) ? self::MDN.'Web/CSS/'.$word : null,
            'python' => \in_array($word, self::PYTHON_BUILTINS, strict: true) ? 'https://docs.python.org/3/library/functions.html#'.$word : null,
            default => null,
        };
    }

    /**
     * php.net manual URL for an internal function or class. Reflection runs in
     * the IDE's own runtime; class lookup skips autoloading so the IDE's vendor
     * classes never answer (internal classes are always loaded).
     */
    private static function phpUrl(string $word): ?string
    {
        return match (true) {
            \function_exists($word) && new \ReflectionFunction($word)->isInternal() => 'https://www.php.net/manual/en/function.'.\str_replace('_', replace: '-', subject: \strtolower($word)).'.php',
            \class_exists($word, false) && new \ReflectionClass($word)->isInternal() => 'https://www.php.net/manual/en/class.'.\strtolower($word).'.php',
            default => null,
        };
    }
}
