<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Ui\Role;

/**
 * What a {@see FieldBox} says around its value: the $label (the legend in the
 * top border — always on screen, so the placeholder never has to stand in for
 * it), the $placeholder shown while the value is empty, a $trailing hint set
 * into the bottom border, and the $surface role the field sits on (null for
 * the host row's own background).
 */
final readonly class FieldSpec
{
    public function __construct(
        public string $label,
        public string $placeholder = '',
        public string $trailing = '',
        public ?Role $surface = null,
    ) {}
}
