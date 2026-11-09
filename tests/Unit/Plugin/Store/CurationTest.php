<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Store;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\Store\Curated;
use Porta\Plugin\Store\Curation;
use Porta\Plugin\Store\StoreCategory;

/** `store/plugins.json` off the network: what is shaped right stands, the rest is dropped. */
final class CurationTest extends TestCase
{
    public function testEntriesAreReadAndWhatIsMisshapenIsDropped(): void
    {
        $curation = Curation::parse((string) \json_encode(
            [
                'acme/porta-mago' => ['category' => 'linters', 'featured' => true, 'replaces' => 'mago'],
                'acme/porta-plain' => [],
                'acme/porta-odd' => ['category' => 'cooking', 'featured' => 'yes', 'replaces' => '../x'],
                'Not A Package' => [],
                'acme/porta-scalar' => 'yes',
            ],
            \JSON_THROW_ON_ERROR,
        ));

        $mago = $curation->of('acme/porta-mago');
        $this->assertInstanceOf(Curated::class, $mago);
        $this->assertSame(StoreCategory::Linters, $mago->category);
        $this->assertTrue($mago->featured);
        $this->assertSame('mago', $mago->replaces);

        $plain = $curation->of('acme/porta-plain');
        $this->assertInstanceOf(Curated::class, $plain, 'listed at all is verified');
        $this->assertNull($plain->category, 'no category: the package keeps its own');
        $this->assertFalse($plain->featured);

        $odd = $curation->of('acme/porta-odd');
        $this->assertSame(StoreCategory::Other, $odd?->category);
        $this->assertFalse($odd->featured, 'only true features');
        $this->assertNull($odd->replaces, 'a name no plugin may have replaces nothing');

        $this->assertNull($curation->of('Not A Package'));
        $this->assertNull($curation->of('acme/porta-scalar'));
        $this->assertNull($curation->of('acme/porta-unlisted'));

        $this->assertSame('acme/porta-mago', $curation->replacing('mago'));
        $this->assertNull($curation->replacing('git'));
    }

    public function testWhatIsNotJsonIsNoCuration(): void
    {
        $this->assertNull(Curation::parse('nope')->of('acme/porta-mago'));
        $this->assertNull(Curation::parse('"a string"')->replacing('mago'));
    }

    public function testTheShippedFileIsValidCuration(): void
    {
        $json = (string) \file_get_contents(\dirname(__DIR__, levels: 4).'/store/plugins.json');
        $this->assertIsArray(\json_decode($json, associative: true, flags: \JSON_THROW_ON_ERROR), 'store/plugins.json is one JSON object');
    }
}
