<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class EnsureApiToken
{
    public function handle(Request $request, Closure $next) { return $next($request); }
}
