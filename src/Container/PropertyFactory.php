<?php

declare(strict_types=1);

namespace Porta\Container;

/**
 * A container factory that reads a public property.
 *
 * Symfony's `->factory()` takes a callable, and a service that another object
 * builds and exposes — the decoration store {@see \Porta\Widget\CodeEditorWidget}
 * renders, the plugin manager {@see \Porta\Plugin\Runtime\PluginHost} owns — is
 * exposed as a public readonly property now, not a getter. This is the one
 * callable those definitions share:
 * `->factory([PropertyFactory::class, 'read'])->args([service(Owner::class), 'property'])`.
 * The property name is a string, exactly as the method name it replaced was,
 * and a wrong one fails the container compile the whole suite boots through.
 */
final class PropertyFactory
{
    public static function read(object $owner, string $property): mixed
    {
        // @mago-expect analysis:string-member-selector -- the name comes from a service definition, the same trust a factory method name gets
        // @mago-expect analysis:ambiguous-object-property-access -- any owner; the container compile is what checks the property exists
        return $owner->{$property};
    }
}
