<?php

namespace App\Http\Middleware;

use App\Modules\Changelog\Managers\ChangelogManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FlushChangelogMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Flushuj changelog cache na koniec requesta
        app(ChangelogManager::class)->flush();

        return $response;
    }
}
