<?php

namespace App\Modules\Publishing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Publishing\Http\Requests\AuthorizePlatformConnectionRequest;
use App\Modules\Publishing\Http\Requests\DestroyPlatformConnectionRequest;
use App\Modules\Publishing\Http\Resources\PlatformAuthorizationResource;
use App\Modules\Publishing\Http\Resources\PlatformConnectionResource;
use App\Modules\Publishing\Managers\PlatformConnectionManager;
use App\Modules\Publishing\Models\PlatformConnection;
use App\Modules\Publishing\Services\OAuthStateService;
use App\Modules\Publishing\Services\PlatformConnectionService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * THE AUTHENTICATED HALF OF THE CONNECTIONS SURFACE: list, begin a handshake, disconnect.
 *
 * The other half — the callback — is a different controller on a different route group for a reason
 * that is the central design problem of this batch: it arrives with no authentication and no workspace
 * header, and mixing it in here would put an unauthenticated action in a class every other method of
 * which is authorized. See {@see PlatformOAuthCallbackController}.
 *
 * Thin by construction, as everywhere: request → service → resource. Authorization lives in the
 * FormRequests via `PlatformConnectionPolicy`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHY `authorize` IS A POST AND RETURNS A URL RATHER THAN A REDIRECT
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A POST because it MINTS something with a side effect — a single-use nonce is written to the ledger —
 * and a GET that changes state is a GET a browser may make on its own, which would spend nonces on
 * prefetch.
 *
 * A URL rather than a 302 because the caller is an XHR from a single-page app carrying a bearer token.
 * A redirect answered to `fetch` is followed by the browser WITHOUT that header, against a third-party
 * origin, and what comes back is a consent page the SPA then tries to parse as JSON. The client
 * navigates the window itself, which is the only thing that can start an OAuth flow correctly from an
 * SPA.
 */
class PlatformConnectionController extends Controller
{
    public function __construct(
        private PlatformConnectionService $service,
        private PlatformConnectionManager $manager,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PlatformConnection::class);

        return PlatformConnectionResource::collection($this->service->index($request));
    }

    /**
     * BEGIN a handshake for one destination.
     *
     * The workspace comes from {@see TenantContext}, which `ResolveWorkspace` filled from the header and
     * `RequireWorkspace` has already refused the absence of. It is read here — rather than trusted from
     * anything the client sends — because it is about to be SIGNED into a state that a later,
     * unauthenticated request will use to choose a database.
     *
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * IT ANSWERS WITH A COOKIE AS WELL AS A BODY, AND THAT IS WHY THE RETURN TYPE IS A RESPONSE
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * The cookie binds the handshake to THIS browser, so that a `state` read off somebody's screen
     * cannot be walked through a consent screen elsewhere and land an attacker-controlled channel in
     * this workspace's destination picker. See {@see OAuthStateService}.
     *
     * It is attached here rather than queued because `AddQueuedCookiesToResponse` is a `web`-group
     * middleware and this route is in `api` — a queued cookie would be dropped silently, and the symptom
     * would be every real connect failing at the callback with `oauth_browser_mismatch`.
     */
    public function authorizeConnection(
        AuthorizePlatformConnectionRequest $request,
        TenantContext $context,
    ): JsonResponse {
        $handshake = $this->service->beginAuthorization(
            user: $request->user(),
            workspace: $context->workspace(),
            platform: $request->resolvedPlatform(),
        );

        return PlatformAuthorizationResource::make($handshake)
            ->response()
            ->withCookie($handshake->handshakeCookie);
    }

    /**
     * DISCONNECT.
     *
     * 204, like every other delete in the product — and, as with a publication's, the row survives. What
     * the Manager does besides the soft delete (holding the scheduled queue, stamping `revoked`) is the
     * whole of the operation and is deliberately not restated here; a controller that listed those steps
     * would be a second description of them.
     */
    public function destroy(
        DestroyPlatformConnectionRequest $request,
        PlatformConnection $connection,
    ): JsonResponse {
        $this->manager->revoke($connection);

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }
}
