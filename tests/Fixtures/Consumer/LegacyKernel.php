<?php

declare(strict_types=1);

namespace App\Smoke;

use Illuminate\Foundation\Http\Kernel;

final class LegacyKernel extends Kernel
{
    protected $middleware = [];

    protected $middlewareGroups = ['web' => []];
}
