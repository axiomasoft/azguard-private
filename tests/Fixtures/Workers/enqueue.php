<?php

declare(strict_types=1);

use App\Providers\WorkerJob;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
for ($i = 1; $i <= (int) $argv[1]; $i++) {
    dispatch(new WorkerJob($i));
}
