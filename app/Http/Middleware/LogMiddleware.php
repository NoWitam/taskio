<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class LogMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($user = User::first()) {
            Auth::login($user);
        }

        $queries = [];

        DB::listen(function ($query) use (&$queries) {
            $queries[] = [
                'query' => $query->toRawSql(),
                'time' => $query->time
            ];
        });

        $response = $next($request);

        info('---------------------------------');
        info(now()->format('H:i d.m.Y') . ' ' .request()->fullUrl());
        info('Queries: ' . count($queries) . ', time: ' . array_sum(array_column($queries, 'time')));

        foreach($queries as $q) {
            info($q['query']);
        }
            
        info('---------------------------------');
        
        return $response;
    }
}
