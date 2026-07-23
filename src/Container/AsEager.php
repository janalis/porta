<?php

declare(strict_types=1);

namespace Porta\Container;

/**
 * Marks a service that must be CONSTRUCTED at boot for its constructor's side
 * effect — it registers itself somewhere (attaches to {@see \Porta\Ui\WorkspaceViews},
 * hooks {@see \Porta\Ui\BidiTui::onBeforeFrame}, …) and has no inbound service
 * reference, so without a forced resolve it would never exist. Autoconfiguration
 * (config/services.php) tags it `porta.eager`; {@see \Porta\Bootstrap\EagerServices}
 * iterates the tag once at the container ctor tail, which constructs each one.
 *
 * `$priority` fixes construction order (higher first) where it is contractual —
 * e.g. the find/picker/toast presenters must register their per-frame sync in
 * that order so a shared-span-slot hand-over clears before it repaints. The
 * declarative sibling of `#[AsTaggedItem(priority)]` for the command providers.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsEager
{
    public function __construct(
        public int $priority = 0,
    ) {}
}
