<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * A DEVELOPER'S QUERY LOG. Globally prepended, and therefore the first thing every request touches.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT WAS REMOVED FROM IT, AND ON WHOSE AUTHORITY
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * This middleware previously did two things beyond logging, both of which R4 B2 made unsafe:
 *
 *   IT LOGGED THE FULL URL, INCLUDING THE QUERY STRING. B2 added `GET /oauth/{platform}/callback`,
 *   which Google and Meta call as `…?code=<authorization code>&state=<signed state>`. An authorization
 *   code is exchangeable for an access token — for about ten minutes at Google, and for longer if the
 *   exchange it was meant for FAILED, which is exactly the case a developer is reading the log to
 *   understand. So a live credential sat in `storage/logs` next to a state naming the workspace it
 *   belonged to.
 *
 *   IT CALLED `Auth::login()` FOR A HARDCODED USER ID. Prepended, that runs BEFORE `auth:sanctum`,
 *   before `ResolveWorkspace` and before every policy — an authentication bypass for whoever holds
 *   that uuid. It has been inert on this installation because no such row exists, which is a property
 *   of the current database rather than of the code: restore a dump that happens to contain that id and
 *   every request in the application is authenticated as them. B2's callback had to be written to
 *   IGNORE `$request->user()` specifically because of this line.
 *
 * THE DECISION TO CUT BOTH WAS TAKEN BY THE AGENT, IN THE OWNER'S PLACE, AFTER FOUR UNANSWERED
 * REQUESTS FOR A RULING — and it is REVERSIBLE. Restoring the login is one commit; if it was serving a
 * local convenience nobody wrote down, the right shape for that is a documented, environment-gated
 * dev-login rather than a hardcoded id inside a logging middleware.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT IT DOES NOW
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 *   LOCAL ONLY. `toRawSql()` interpolates every binding, so this writes every value the application
 *   reads or stores — password hashes, tokens as ciphertext, personal data — into a plaintext file that
 *   is rotated by nobody. That is a reasonable trade on a developer's machine and is not one to make on
 *   a server, so the whole body is gated on `local` and production keeps an empty middleware.
 *
 *   CREDENTIAL PARAMETERS ARE REDACTED from the logged URL even so. Defence in depth: the gate is one
 *   environment variable away from being wrong, and `APP_ENV=local` on a publicly reachable host is a
 *   mistake somebody makes eventually. The redaction costs a `preg_replace` on one line per request.
 *
 * WHAT IT STILL DOES NOT DO, and is not a defect: the query log itself is not redacted. It cannot
 * usefully be — the values ARE the point — and what protects the credentials this application stores is
 * that they are `encrypted` casts, so the ciphertext is what gets bound and the ciphertext is what gets
 * logged. `PublishingConnectionSecrecyTest` pins exactly that.
 */
class LogMiddleware
{
    /**
     * Query parameters whose VALUE is a credential, or a claim that acts as one.
     *
     * `code` and `state` are the OAuth callback's. `token`, `access_token` and `refresh_token` are here
     * because a URL is a place they have historically ended up in other applications, and the day one of
     * ours does, this file should already have been ready for it.
     *
     * @var array<int, string>
     */
    private const REDACTED_PARAMETERS = [
        'code',
        'state',
        'token',
        'access_token',
        'refresh_token',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!app()->environment('local')) {
            return $next($request);
        }

        $queries = [];

        DB::listen(function ($query) use (&$queries) {
            $queries[] = [
                'query' => $query->toRawSql(),
                'time' => $query->time,
            ];
        });

        $response = $next($request);

        info('---------------------------------');
        info(now()->format('H:i d.m.Y') . ' ' . $this->redact($request->fullUrl()));
        info('Queries: ' . count($queries) . ', time: ' . array_sum(array_column($queries, 'time')));

        foreach ($queries as $q) {
            info($q['query']);
        }

        info('---------------------------------');

        return $response;
    }

    /**
     * The URL with every credential-bearing parameter's VALUE replaced.
     *
     * The parameter NAMES survive, because "this request carried a code" is the useful half for reading
     * a log and the value is the half that must not be there. Rewritten textually rather than by
     * re-encoding the query, so a URL that does not parse the way we expect is still redacted rather
     * than passed through whole.
     */
    private function redact(string $url): string
    {
        return (string) preg_replace(
            '/([?&](?:' . implode('|', self::REDACTED_PARAMETERS) . ')=)[^&#]*/i',
            '$1[redacted]',
            $url,
        );
    }
}
