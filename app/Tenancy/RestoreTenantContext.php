<?php

namespace App\Tenancy;

/**
 * Job middleware that re-applies the captured workspace context before the job
 * runs. Use on jobs that mutate tenant data: `public function middleware(): array
 * { return [new RestoreTenantContext]; }`.
 */
class RestoreTenantContext
{
    public function handle(object $job, callable $next): mixed
    {
        if (method_exists($job, 'restoreTenant')) {
            $job->restoreTenant();
        }

        return $next($job);
    }
}
