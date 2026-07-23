<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Container;

use PHPUnit\Framework\TestCase;
use Porta\Config\IdeConfig;
use Porta\Container\Ansi;
use Porta\Container\IdeContainerFactory;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * The `porta.ide` ServiceLocator is the single public boundary, hand-listed in
 * config/services.php. Every type `Ide` resolves through `$this->service(X::class)`
 * must be a member — this test tokenizes `src/Ide.php` for those targets and
 * asserts each is present, so a new `service()` call for an unlisted class fails
 * here (a loud compile-adjacent failure) instead of at the first real boot. It
 * replaces the manual "remember to add it to the locator's entry list" chore.
 */
final class IdeLocatorMembershipTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (['PORTA_CACHE_DIR', 'PORTA_CONTAINER_CACHE'] as $key) {
            $this->savedEnv[$key] = getenv($key);
        }
        putenv('PORTA_CONTAINER_CACHE=off'); // build the container deterministically, no dump
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            putenv(false === $value ? $key : $key.'='.$value);
        }
    }

    public function testEveryIdeServiceTargetIsALocatorMember(): void
    {
        $ideSource = (string) file_get_contents(\dirname(__DIR__, levels: 3).'/src/Ide.php');
        // Resolve every `use A\B\C;` (and `use A\B\C as D;`) so `service(C::class)`
        // maps to its real FQCN, whatever namespace it comes from.
        $imports = [];
        preg_match_all('/^use ([\w\\\\]+)(?: as (\w+))?;/m', $ideSource, $useMatches, \PREG_SET_ORDER);
        foreach ($useMatches as $u) {
            $fqcn = $u[1];
            $short = $u[2] ?? substr((string) strrchr('\\'.$fqcn, '\\'), 1);
            $imports[$short] = $fqcn;
        }
        preg_match_all('/->service\((\w+)::class\)/', $ideSource, $calls);
        $targets = array_unique($calls[1]);
        self::assertNotEmpty($targets, 'Ide resolves services through the locator');

        $container = new IdeContainerFactory()->build('/tmp/porta-locator-membership', IdeConfig::load('/tmp/porta-locator-membership'), Ansi::Enabled);
        $locator = $container->get('porta.ide');
        \assert($locator instanceof ServiceLocator, description: 'the public boundary is a ServiceLocator');

        $missing = [];
        foreach ($targets as $short) {
            $fqcn = $imports[$short] ?? 'Porta\\'.$short;
            if (!$locator->has($fqcn)) {
                $missing[] = $fqcn;
            }
        }
        self::assertSame([], $missing, 'every Ide::service(X) target must be a porta.ide locator member');
    }
}
