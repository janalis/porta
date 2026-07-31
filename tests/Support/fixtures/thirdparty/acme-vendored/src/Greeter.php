<?php

declare(strict_types=1);

namespace Acme\Vendored;

use Vend\Lib\Helper;
use Vend\Mapped\Thing;

final class Greeter
{
    public static function greet(): string
    {
        return 'acme '.Helper::text().' '.Thing::text();
    }
}
