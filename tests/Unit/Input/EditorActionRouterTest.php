<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Input;

use PHPUnit\Framework\TestCase;
use Porta\Config\ActionCatalog;
use Porta\Input\EditorActionRouter;
use Porta\Tests\Support\Probe;
use Porta\Widget\BufferNavigationHandler;
use Porta\Widget\EditorCallbacks;
use Porta\Widget\EditorFileActionHandler;
use Porta\Widget\EditorNavigationHandler;
use Porta\Widget\EditorRefactorHandler;
use Symfony\Component\Tui\Input\Keybindings;

/**
 * The single-source contract of the router's two handler maps: their KEYS
 * drive both membership and dispatch, so every key must name a catalog action
 * — an id in a map but not in {@see ActionCatalog::ACTIONS} would be
 * unrebindable (and invisible to the keymap page), while the docblock's
 * promise that "an id can never be routable-but-unhandled" was, until now,
 * enforced by nothing.
 */
final class EditorActionRouterTest extends TestCase
{
    public function testEveryHandlerMapKeyIsACatalogAction(): void
    {
        $router = new EditorActionRouter(
            static fn (): EditorCallbacks => new EditorCallbacks(),
            static fn (): array => [0, 0],
        );

        $maps = [];
        foreach (['simpleHandlers', 'positionalHandlers'] as $method) {
            $maps[$method] = Probe::invoke($router, $method);
        }

        $overlap = array_intersect(array_keys($maps['simpleHandlers']), array_keys($maps['positionalHandlers']));
        $this->assertSame([], $overlap, 'an action id lives in exactly one of the two maps');

        foreach ($maps as $method => $handlers) {
            $this->assertNotSame([], $handlers, $method.' is non-empty');
            foreach (array_keys($handlers) as $action) {
                $this->assertArrayHasKey($action, ActionCatalog::ACTIONS, $method.': "'.$action.'" is a catalog action (rebindable, visible in the keymap page)');
            }
        }
    }

    /** @return array{recording: list<string>, file: EditorFileActionHandler, navigation: EditorNavigationHandler, refactor: EditorRefactorHandler, buffer: BufferNavigationHandler} */
    private function fakes(): array
    {
        $recording = [];
        $file = new class($recording) implements EditorFileActionHandler {
            /** @param list<string> $recording */
            public function __construct(
                private array &$recording,
            ) {}

            public function save(): void
            {
                $this->recording[] = 'save';
            }

            public function saveAll(): void
            {
                $this->recording[] = 'save_all';
            }

            public function copy(string $text): void {}

            public function openFind(): void
            {
                $this->recording[] = 'find';
            }

            public function openFindReplace(): void
            {
                $this->recording[] = 'replace';
            }

            public function openGotoFile(): void
            {
                $this->recording[] = 'goto_file';
            }
        };
        $navigation = new class($recording) implements EditorNavigationHandler {
            /** @param list<string> $recording */
            public function __construct(
                private array &$recording,
            ) {}

            public function requestDefinition(int $line, int $utf16Col): void
            {
                $this->recording[] = "goto_definition:{$line}:{$utf16Col}";
            }

            public function requestImplementation(int $line, int $utf16Col): void
            {
                $this->recording[] = "goto_implementation:{$line}:{$utf16Col}";
            }

            public function requestTypeDefinition(int $line, int $utf16Col): void
            {
                $this->recording[] = "goto_type_definition:{$line}:{$utf16Col}";
            }

            public function navigateBack(): void
            {
                $this->recording[] = 'navigate_back';
            }

            public function requestHover(int $line, int $col): void
            {
                $this->recording[] = "quick_doc:{$line}:{$col}";
            }

            public function requestReferences(int $line, int $col): void
            {
                $this->recording[] = "find_usages:{$line}:{$col}";
            }

            public function requestFileStructure(): void
            {
                $this->recording[] = 'file_structure';
            }

            public function requestCallHierarchy(int $line, int $col): void
            {
                $this->recording[] = "call_hierarchy:{$line}:{$col}";
            }

            public function requestPeek(int $line, int $col): void
            {
                $this->recording[] = "peek_definition:{$line}:{$col}";
            }
        };
        $refactor = new class($recording) implements EditorRefactorHandler {
            /** @param list<string> $recording */
            public function __construct(
                private array &$recording,
            ) {}

            public function requestPrepareRename(int $line, int $col, string $word): void {}

            public function requestCodeActions(int $line, int $col): void
            {
                $this->recording[] = "code_action:{$line}:{$col}";
            }

            public function requestRefactorings(int $line, int $col): void
            {
                $this->recording[] = "refactor_this:{$line}:{$col}";
            }

            public function requestInline(int $line, int $col): void
            {
                $this->recording[] = "inline_refactoring:{$line}:{$col}";
            }

            public function requestReformat(): void
            {
                $this->recording[] = 'reformat';
            }
        };
        $buffer = new class($recording) implements BufferNavigationHandler {
            /** @param list<string> $recording */
            public function __construct(
                private array &$recording,
            ) {}

            public function cycleBuffer(int $offset): void
            {
                $this->recording[] = "cycle:{$offset}";
            }

            public function openRecentFiles(): void
            {
                $this->recording[] = 'recent_files';
            }

            public function closeBuffer(): void
            {
                $this->recording[] = 'close_buffer';
            }
        };

        return ['recording' => &$recording, 'file' => $file, 'navigation' => $navigation, 'refactor' => $refactor, 'buffer' => $buffer];
    }

    public function testDispatchKeyFiresEverySimpleHandlerAndReportsHandled(): void
    {
        $f = $this->fakes();
        $callbacks = new EditorCallbacks($f['file'], $f['navigation'], null, $f['refactor'], $f['buffer']);
        $router = new EditorActionRouter(static fn (): EditorCallbacks => $callbacks, static fn (): array => [0, 0]);
        $kb = new Keybindings([
            'recent_files' => ['a'],
            'close_buffer' => ['b'],
            'goto_file' => ['c'],
            'reformat' => ['d'],
            'file_structure' => ['e'],
            'save_all' => ['f'],
            'save' => ['g'],
            'navigate_back' => ['h'],
        ]);

        foreach ([
            'a' => 'recent_files',
            'b' => 'close_buffer',
            'c' => 'goto_file',
            'd' => 'reformat',
            'e' => 'file_structure',
            'f' => 'save_all',
            'g' => 'save',
            'h' => 'navigate_back',
        ] as $key => $expected) {
            $this->assertTrue($router->dispatchKey($key, $kb), $expected.' reports it consumed the key');
        }
        $this->assertSame(['recent_files', 'close_buffer', 'goto_file', 'reformat', 'file_structure', 'save_all', 'save', 'navigate_back'], $f['recording']);
    }

    public function testDispatchKeyFiresEveryPositionalHandlerWithTheCaret(): void
    {
        $f = $this->fakes();
        $callbacks = new EditorCallbacks(null, $f['navigation'], null, $f['refactor'], null);
        $router = new EditorActionRouter(static fn (): EditorCallbacks => $callbacks, static fn (): array => [3, 7]);
        $kb = new Keybindings([
            'peek_definition' => ['a'],
            'code_action' => ['b'],
            'quick_doc' => ['c'],
            'find_usages' => ['d'],
            'call_hierarchy' => ['e'],
            'goto_definition' => ['f'],
            'goto_implementation' => ['g'],
            'goto_type_definition' => ['h'],
        ]);

        foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'] as $key) {
            $this->assertTrue($router->dispatchKey($key, $kb));
        }
        $this->assertSame(
            [
                'peek_definition:3:7',
                'code_action:3:7',
                'quick_doc:3:7',
                'find_usages:3:7',
                'call_hierarchy:3:7',
                'goto_definition:3:7',
                'goto_implementation:3:7',
                'goto_type_definition:3:7',
            ],
            $f['recording'],
        );
    }

    public function testDispatchKeyCyclesToTheNextAndPreviousBuffer(): void
    {
        $f = $this->fakes();
        $callbacks = new EditorCallbacks(null, null, null, null, $f['buffer']);
        $router = new EditorActionRouter(static fn (): EditorCallbacks => $callbacks, static fn (): array => [0, 0]);
        $kb = new Keybindings(['next_buffer' => ['n'], 'prev_buffer' => ['p']]);

        $this->assertTrue($router->dispatchKey('n', $kb));
        $this->assertTrue($router->dispatchKey('p', $kb));
        $this->assertSame(['cycle:1', 'cycle:-1'], $f['recording']);
    }

    public function testDispatchKeyOpensFindAndFindReplace(): void
    {
        $f = $this->fakes();
        $callbacks = new EditorCallbacks($f['file'], null, null, null, null);
        $router = new EditorActionRouter(static fn (): EditorCallbacks => $callbacks, static fn (): array => [0, 0]);
        $kb = new Keybindings(['find' => ['n'], 'replace' => ['r']]);

        $this->assertTrue($router->dispatchKey('n', $kb));
        $this->assertTrue($router->dispatchKey('r', $kb));
        $this->assertSame(['find', 'replace'], $f['recording']);
    }

    public function testDispatchKeyIgnoresAnUnboundKey(): void
    {
        $router = new EditorActionRouter(static fn (): EditorCallbacks => new EditorCallbacks(), static fn (): array => [0, 0]);

        $this->assertFalse($router->dispatchKey('z', new Keybindings([])));
    }

    /**
     * Every hook in the two handler tables is nullsafe: "a bare editor fires
     * nothing" (per the class docblock) must hold for every single action,
     * not just the ones an existing scenario happens to wire a fake for.
     */
    public function testDispatchKeyIsInertForEveryActionWhenNoCallbacksAreWired(): void
    {
        $callbacks = new EditorCallbacks();
        $router = new EditorActionRouter(static fn (): EditorCallbacks => $callbacks, static fn (): array => [0, 0]);
        $kb = new Keybindings([
            'recent_files' => ['a'],
            'close_buffer' => ['b'],
            'goto_file' => ['c'],
            'reformat' => ['d'],
            'file_structure' => ['e'],
            'save_all' => ['f'],
            'save' => ['g'],
            'navigate_back' => ['h'],
            'peek_definition' => ['i'],
            'code_action' => ['j'],
            'quick_doc' => ['k'],
            'find_usages' => ['l'],
            'call_hierarchy' => ['m'],
            'goto_definition' => ['o'],
            'goto_implementation' => ['q'],
            'goto_type_definition' => ['s'],
            'next_buffer' => ['t'],
            'prev_buffer' => ['u'],
            'find' => ['v'],
            'replace' => ['w'],
        ]);

        foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'o', 'q', 's', 't', 'u', 'v', 'w'] as $key) {
            $this->assertTrue($router->dispatchKey($key, $kb), "key '{$key}' must still report handled even with no callbacks wired");
        }
    }

    public function testRunFiresASimpleActionByIdAndReportsHandled(): void
    {
        $f = $this->fakes();
        $callbacks = new EditorCallbacks($f['file'], null, null, null, null);
        $router = new EditorActionRouter(static fn (): EditorCallbacks => $callbacks, static fn (): array => [0, 0]);

        $this->assertTrue($router->run('save'));
        $this->assertSame(['save'], $f['recording']);
    }

    public function testRunFiresAPositionalActionByIdWithTheCaret(): void
    {
        $f = $this->fakes();
        $callbacks = new EditorCallbacks(null, $f['navigation'], null, null, null);
        $router = new EditorActionRouter(static fn (): EditorCallbacks => $callbacks, static fn (): array => [2, 9]);

        $this->assertTrue($router->run('quick_doc'));
        $this->assertSame(['quick_doc:2:9'], $f['recording']);
    }

    public function testRunReturnsFalseForAnUnknownId(): void
    {
        $router = new EditorActionRouter(static fn (): EditorCallbacks => new EditorCallbacks(), static fn (): array => [0, 0]);

        $this->assertFalse($router->run('not_a_real_action'));
    }

    public function testRunIsInertForANullWiredHandlerGroup(): void
    {
        $router = new EditorActionRouter(static fn (): EditorCallbacks => new EditorCallbacks(), static fn (): array => [0, 0]);

        $this->assertTrue($router->run('reformat'));
        $this->assertTrue($router->run('code_action'));
    }
}
