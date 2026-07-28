<?php

declare(strict_types=1);

namespace Porta\Container;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Marks a service that must be CONSTRUCTED at boot for its constructor's side
 * effect — it registers itself somewhere (attaches to {@see \Porta\Workbench\WorkspaceViews},
 * hooks {@see \Porta\Ui\BidiTui::onBeforeFrame}, …) and has no inbound service
 * reference, so without a forced resolve it would never exist. Extends
 * `#[AutoconfigureTag]`, so the `porta.eager` tag applies natively with no
 * central registration; {@see \Porta\Bootstrap\EagerServices} iterates the tag
 * once at the container ctor tail, which constructs each one.
 *
 * `$priority` fixes construction order (higher first) where it is contractual —
 * e.g. the find/picker/toast presenters must register their per-frame sync in
 * that order so a shared-span-slot hand-over clears before it repaints. The
 * declarative sibling of `#[AsTaggedItem(priority)]` for the command providers.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsEager extends AutoconfigureTag
{
    // Deliberately NOT a promoted property: RegisterAutoconfigureAttributesPass
    // (array)-casts the attribute instance into _instanceof config, where a
    // stray public property is rejected — the priority travels as a tag
    // attribute instead.
    public function __construct(int $priority = 0)
    {
        parent::__construct('porta.eager', ['priority' => $priority]);
    }
}
