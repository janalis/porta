<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\PreviewRoutes;
use Porta\Editor\ViewerProviderSource;

final class PreviewRoutesTest extends TestCase
{
    private static function plugins(?string $answer): ViewerProviderSource
    {
        return new readonly class($answer) implements ViewerProviderSource {
            public function __construct(
                private ?string $answer,
            ) {}

            public function viewerFor(string $path): ?string
            {
                return 'never-asked';
            }

            public function previewFor(string $path): ?string
            {
                return $this->answer;
            }
        };
    }

    public function testAPluginClaimWinsOverCores(): void
    {
        $routes = new PreviewRoutes(self::plugins('better-markdown'));
        $routes->claim('markdown', static fn (string $path): bool => true);

        $this->assertSame('better-markdown', $routes->previewFor('/p/README.md'));
    }

    public function testCoresClaimsAnswerWhatNoPluginDid(): void
    {
        $routes = new PreviewRoutes(self::plugins(null));
        $routes->claim('markdown', static fn (string $path): bool => \str_ends_with($path, '.md'));
        $routes->claim('other', static fn (string $path): bool => \str_ends_with($path, '.txt'));

        $this->assertSame('markdown', $routes->previewFor('/p/README.md'));
        $this->assertSame('other', $routes->previewFor('/p/notes.txt'), 'every core claim is asked, not only the first');
        $this->assertNull($routes->previewFor('/p/app.php'));
    }
}
