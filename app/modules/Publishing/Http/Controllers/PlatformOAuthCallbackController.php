<?php

namespace App\Modules\Publishing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Publishing\DTOs\OAuthState;
use App\Modules\Publishing\Enums\PublishingPlatform;
use App\Modules\Publishing\Exceptions\OAuthExchangeFailed;
use App\Modules\Publishing\Exceptions\OAuthStateRejected;
use App\Modules\Publishing\Services\OAuthStateService;
use App\Modules\Publishing\Services\PlatformConnectionService;
use App\Modules\Publishing\Support\OAuthCallbackReason;
use App\Modules\Workspaces\Enums\WorkspaceStatus;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WHERE THE PLATFORM SENDS THE BROWSER BACK — the one unauthenticated write path in this module.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * WHY IT CANNOT LIVE IN THE API GROUP, AND WHY THAT IS NOT A COMPROMISE
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * This is not an XHR. It is a top-level browser navigation performed by Google or Meta, so:
 *
 *   THERE IS NO `Authorization` HEADER. The SPA keeps its bearer token in `localStorage` and attaches it
 *   in JavaScript (`resources/js/next/app/lib/token.ts`). None of that runs during a cross-origin
 *   redirect. `auth:sanctum` on this route would 401 every legitimate callback.
 *
 *   THERE IS NO `X-Workspace-Id` HEADER, so `ResolveWorkspace` has nothing to resolve and every
 *   tenant-aware model would query unscoped. The route is therefore deliberately OUTSIDE that
 *   middleware — not because the check is inconvenient, but because the workspace genuinely arrives
 *   somewhere else: inside the signed `state`.
 *
 * So the identity of this request is the state and nothing but the state. Everything below follows from
 * that single fact.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * `$request->user()` IS NEVER CONSULTED. NOT ONCE, NOT AS A FALLBACK.
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * On this route it is nobody. Attributing a connection to `auth()->id()` would therefore stamp a null —
 * or, worse, whoever a future middleware decides to resolve — as the creator of an account they have
 * never seen, and, because `HasCreator` also drives ownership, hand them policy rights over it.
 *
 * That is not hypothetical history: until B2's review, `LogMiddleware` — globally PREPENDED, so ahead of
 * every guard — called `Auth::login()` for a hardcoded user id on every request, which would have made
 * this callback look authenticated as somebody unrelated. The line has been removed. This controller is
 * written not to depend on that removal holding, because it is one revert away from returning and the
 * cost of it returning unnoticed is silent misattribution rather than an error.
 *
 * The creator is taken from the SIGNED state, passed explicitly all the way to the model, where
 * `HasCreator`'s rule 1 (an explicit id wins) keeps it. `PublishingOAuthStateTest` pins it by completing
 * a handshake while authenticated as somebody else entirely.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * THE ORDER OF OPERATIONS IS THE SECURITY PROPERTY
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 *   1. Did the platform report an error? Then there is no code and nothing to do. The state is NOT
 *      consumed — a user who clicked "cancel" and then tries again would otherwise find their nonce
 *      spent, and would be told the link had already been used when they had used nothing.
 *   2. VERIFY AND CONSUME the state. Signature first (so a forgery never reaches a database), expiry
 *      from the signed payload, the BROWSER BINDING cookie, then the single-use ledger. This is also
 *      where the URL's platform is matched against the state's.
 *
 *      THE BINDING CLOSES BOTH DIRECTIONS, and it is the only check here that does. A signed state is
 *      genuine whether it was STOLEN from somebody (finished with the attacker's account, landing their
 *      channel in the victim's workspace) or PLANTED on somebody (minted for the attacker's workspace,
 *      finished with the victim's account, landing the victim's channel in theirs). The cookie carries a
 *      secret the state does not, so it can be neither computed from a stolen state nor set in a victim's
 *      browser. See {@see OAuthStateService} for the version of this that shipped and did not work.
 *   3. RE-CHECK MEMBERSHIP. The state proves who asked ten minutes ago; it cannot prove they are still
 *      a member of that workspace, and a signed claim is not a substitute for a current fact. This is
 *      the check `ResolveWorkspace` would have made, made here by hand because that middleware is not on
 *      this route.
 *   4. ACTIVATE THE TENANT, so the connection is written to the right database — the central one for a
 *      shared workspace, its own for an own-database one. Done by hand, and cleared in a `finally`, for
 *      the same reason: the middleware that normally does it is absent.
 *   5. Exchange, look up the account, store. Then redirect.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT GOES IN THE REDIRECT: AN OUTCOME AND A CODE. NEVER A TOKEN.
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * A query parameter ends up in the browser's history, in the SPA's router state, in any analytics that
 * reads `location.search`, and in a screenshot somebody pastes into a support ticket. So what travels is
 * `connection=connected|failed`, the platform, and — on failure — a STABLE REASON CODE the frontend
 * translates. No token, no account id, no display name, and no prose from a platform.
 *
 * THE REASONS ARE NAMED IN ONE PLACE: {@see OAuthCallbackReason}. They used to be fourteen codes split
 * across two exception classes and five bare literals here, of which the language files knew four — so
 * ten of them would have rendered as a raw key on the screen a person lands on when connecting an
 * account has just failed. `PublishingConnectionVocabularyTest` now holds that list and the two
 * translation catalogs in exact parity.
 */
class PlatformOAuthCallbackController extends Controller
{
    /** The query parameters the SPA reads. Named here so the frontend contract is one list. */
    private const RESULT_KEY = 'connection';

    private const RESULT_CONNECTED = 'connected';

    private const RESULT_FAILED = 'failed';

    public function __construct(
        private OAuthStateService $states,
        private PlatformConnectionService $service,
        private TenantContext $context,
        private TenantManager $tenants,
    ) {}

    public function __invoke(Request $request, string $platform): RedirectResponse
    {
        $destination = PublishingPlatform::tryFrom($platform);

        if ($destination === null) {
            // The route constrains the segment, so this is unreachable in practice. Answered as a
            // redirect rather than a 404 anyway: whoever is looking at this is a person in a browser at
            // the end of a consent flow, and a framework error page is not an answer for them.
            return $this->failure(null, OAuthCallbackReason::UNKNOWN_PLATFORM);
        }

        // STEP 1. The user declined, or the platform refused before issuing a code. `error` is a
        // documented enumeration on both platforms (`access_denied` being the one that matters), so it
        // is passed through as a code — bounded in length, because it is going into a URL.
        if ($request->filled('error')) {
            return $this->failure($destination, OAuthCallbackReason::forPlatformError(
                (string) $request->string('error')->value(),
            ));
        }

        $code = (string) $request->string('code')->value();

        if ($code === '') {
            return $this->failure($destination, OAuthCallbackReason::MISSING_CODE);
        }

        // STEP 2. Verified and SPENT together — see OAuthStateService::consume() for why those cannot be
        // two calls. The cookie is the BROWSER BINDING: it carries a secret the `state` does not, so a
        // state alone proves it was minted by us and NOT that the party redeeming it is the party it was
        // minted for.
        //
        // Cleared WHENEVER A STATE IS REDEEMED OR REFUSED — which is not the same as "on every request to
        // this route", and the difference is deliberate. The early exits above (an unknown platform, a
        // declined consent screen, a missing code) return BEFORE this line and leave the binding intact,
        // because none of them ends the handshake: a person who clicks cancel and then changes their mind
        // still holds a spendable state, and clearing their cookie would refuse the retry as
        // `oauth_browser_mismatch`. Past this point the handshake IS over, successfully or not, and a
        // binding outliving it would be a second chance for a state somebody else is holding.
        $handshake = $request->cookie(OAuthStateService::HANDSHAKE_COOKIE);

        Cookie::queue(Cookie::forget(OAuthStateService::HANDSHAKE_COOKIE, '/'));

        try {
            $state = $this->states->consume(
                (string) $request->string('state')->value(),
                $destination,
                is_string($handshake) ? $handshake : null,
            );
        } catch (OAuthStateRejected $e) {
            return $this->failure($destination, $e->reason);
        }

        // STEP 3.
        $workspace = $this->resolveWorkspace($state);

        if ($workspace === null) {
            return $this->failure($destination, OAuthCallbackReason::WORKSPACE_UNAVAILABLE);
        }

        try {
            // STEP 4. By hand, because ResolveWorkspace is not on this route.
            $this->activate($workspace);

            // STEP 5.
            $this->service->completeAuthorization($state, $code);
        } catch (OAuthExchangeFailed $e) {
            Log::warning('A publishing OAuth handshake could not be completed.', [
                'platform' => $destination->value,
                'workspace_id' => $workspace->id,
                // Codes and a status. The provider already reduced the platform's answer to these at its
                // own boundary, which is the only place that reduction is safe to make.
            ] + $e->context());

            // Mapped rather than passed straight through, so the redirect can only ever carry a code
            // this application has a sentence for. Byte-identical today — every failure a callback can
            // reach is already in the catalog — and a guard against the next one that is not.
            return $this->failure($destination, OAuthCallbackReason::forExchange($e->failureCode));
        } catch (Throwable $e) {
            // CLASS ONLY, never the message. This catch sits directly above a layer that was holding a
            // live credential when it threw, and an exception message is the shortest path from a held
            // string to a log file.
            Log::error('A publishing OAuth handshake failed unexpectedly.', [
                'platform' => $destination->value,
                'workspace_id' => $workspace->id,
                'exception' => $e::class,
            ]);

            return $this->failure($destination, OAuthCallbackReason::CONNECTION_FAILED);
        } finally {
            // ALWAYS. A tenant connection left configured on this request would be inherited by whatever
            // the process handles next under a queue worker or an Octane-style long-lived runtime.
            $this->context->clear();
            $this->tenants->forget();
        }

        return $this->success($destination);
    }

    /**
     * The workspace named by the state, IF it is still usable and the user is still in it.
     *
     * Three refusals, all of them answering "no" the same way, because telling a browser which of them
     * happened would answer questions about workspaces the holder of this state may no longer be
     * entitled to ask:
     *
     *   THE WORKSPACE IS GONE. Deleted, or never existed. (The signature makes the latter implausible,
     *   but a check that relies on a signature to be unreachable is a check that stops holding when the
     *   signature does.)
     *
     *   IT IS NOT READY. An own-database workspace is `provisioning` until its database exists. Writing a
     *   connection into it then would aim the tenant connection at the SHARED database while own-mode
     *   leaves `WorkspaceScope` inert — the exact unscoped-write hazard `ResolveWorkspace` refuses with a
     *   409, restated here because that middleware is absent.
     *
     *   THE USER IS NO LONGER A MEMBER. The signed state proves who asked ten minutes ago. Membership
     *   can be revoked in ten minutes, and a signed claim about the past is not a current permission.
     */
    private function resolveWorkspace(OAuthState $state): ?Workspace
    {
        $workspace = Workspace::find($state->workspaceId);

        if ($workspace === null || $workspace->status !== WorkspaceStatus::Ready) {
            return null;
        }

        // Read UNSCOPED by `hasMember`'s own contract — with no active workspace the User scope is inert
        // anyway, and this runs before any tenant is activated on purpose.
        $user = User::find($state->userId);

        if ($user === null || !$workspace->hasMember($user)) {
            return null;
        }

        return $workspace;
    }

    /** What `ResolveWorkspace` does, done by hand because it is not on this route. */
    private function activate(Workspace $workspace): void
    {
        $this->context->set($workspace);

        if ($this->context->isOwn()) {
            $this->tenants->configure($workspace);
        }
    }

    private function success(PublishingPlatform $platform): RedirectResponse
    {
        return redirect()->to($this->returnUrl([
            self::RESULT_KEY => self::RESULT_CONNECTED,
            'platform' => $platform->value,
        ]));
    }

    private function failure(?PublishingPlatform $platform, string $reason): RedirectResponse
    {
        return redirect()->to($this->returnUrl(array_filter([
            self::RESULT_KEY => self::RESULT_FAILED,
            'platform' => $platform?->value,
            // A stable code. The wording stays the frontend's, per the app-wide rule that a client never
            // branches on the server's sentence.
            'reason' => $reason,
        ])));
    }

    /**
     * The SPA URL to land on, with the outcome attached.
     *
     * The path comes from configuration and is used as a PATH, never as a full URL: `redirect()->to()`
     * on a config-supplied absolute URL would be an open redirect the day somebody sets that value from
     * an environment variable they do not control. A leading slash is enforced for the same reason.
     *
     * @param  array<string, string>  $parameters
     */
    private function returnUrl(array $parameters): string
    {
        $path = (string) config('publishing.oauth.return_path', '/next/publishing/connections');

        return url('/' . ltrim($path, '/')) . '?' . http_build_query($parameters);
    }
}
