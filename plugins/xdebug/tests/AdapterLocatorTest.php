<?php

declare(strict_types=1);

namespace Porta\Xdebug\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\TempProject;
use Porta\Xdebug\AdapterLocator;

/** Where the adapter is found, in the order the plugin promises. */
final class AdapterLocatorTest extends TestCase
{
    private const string SCRIPT = 'out/phpDebug.js';

    public function testTheNewestEditorCopyThenTheInstalledOneThenTheUsersOwn(): void
    {
        $home = new TempProject([
            '.vscode/extensions/xdebug.php-debug-1.9.0/'.self::SCRIPT => '',
            '.cursor/extensions/xdebug.php-debug-1.34.0/'.self::SCRIPT => '',
            '.vscode-oss/extensions/xdebug.php-debug-1.10.1-universal/'.self::SCRIPT => '',
            '.vscode/extensions/someone.php-debug-9.0.0/'.self::SCRIPT => '',
        ]);
        $locator = new AdapterLocator($home->dir, '');

        $this->assertSame(
            $home->path('.cursor/extensions/xdebug.php-debug-1.34.0/'.self::SCRIPT),
            $locator->script(),
            'the newest by version, not by name: 1.34 over 1.10 and 1.9; another publisher\'s is not it',
        );
        $this->assertSame('1.34.0 (.cursor)', $locator->describe());
        $this->assertNull($locator->installed());

        $home->write('.porta/tools/php-debug/'.AdapterLocator::VERSION.'/extension/'.self::SCRIPT, '');
        $this->assertSame($home->path('.porta/tools/php-debug/'.AdapterLocator::VERSION.'/extension/'.self::SCRIPT), $locator->script(), 'the installed copy comes first');
        $this->assertSame(AdapterLocator::VERSION, $locator->describe());

        $own = $home->write('mine/phpDebug.js', '');
        $set = new AdapterLocator($home->dir, $own);
        $this->assertSame($own, $set->script(), 'and the user\'s own before both');
        $this->assertSame($own, $set->describe());
        $this->assertNull(new AdapterLocator($home->dir, $home->path('gone.js'))->script(), 'a path set by hand that is not there finds nothing — not something else');
    }

    public function testNothingWithoutAHome(): void
    {
        $locator = new AdapterLocator(null, '');

        $this->assertNull($locator->installDir());
        $this->assertNull($locator->script());
        $this->assertSame('', $locator->describe());
        $empty = new TempProject();
        $this->assertNull(new AdapterLocator($empty->dir, '')->script(), 'an empty home has none');
    }

    public function testThePinIsTheRelease(): void
    {
        $this->assertSame('https://github.com/xdebug/vscode-php-debug/releases/download/v1.40.2/php-debug-1.40.2.vsix', AdapterLocator::URL);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', AdapterLocator::SHA256);
    }
}
