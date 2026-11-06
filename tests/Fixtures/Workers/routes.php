<?php

declare(strict_types=1);

use App\Providers\WorkerProbe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/worker-probe/{number}', static fn (Request $request, int $number) => response()->json(WorkerProbe::run($number)));
