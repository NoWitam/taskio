<?php

namespace App\Modules\Publishing\Http\Resources;

use App\Modules\Publishing\DTOs\StartedHandshake;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WHERE TO SEND THE BROWSER, AND HOW LONG THE INVITATION IS GOOD FOR.
 *
 * A Resource over an array rather than a bare `response()->json()`, following
 * {@see PublicationCountsResource}: every endpoint in this module answers under a `data` envelope, and
 * one that did not would be the exception a client has to special-case.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE URL CONTAINS A SIGNED `state`. IT IS STILL NOT A SECRET, AND THE DISTINCTION MATTERS.
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The state encodes a user id, a workspace id, a platform and a binding digest, signed with a key derived
 * from APP_KEY. It is a CLAIM, not a credential, which is why it may travel in a URL at all, as the OAuth
 * specification intends.
 *
 * WHAT MUST NOT BE REPEATED HERE is the reading this file used to carry — that holding a state is a
 * "narrow and self-limiting power" because "the attacker would be donating their own account". It is not.
 * A channel connected into somebody else's workspace appears in that workspace's destination picker, and
 * everything scheduled to it publishes to the attacker. `OAuthStateService` names that as the reason the
 * browser binding exists.
 *
 * What keeps a leaked state narrow is stated there too: it expires in minutes, is redeemable once, and is
 * redeemable only alongside a cookie secret that is NOT in this URL.
 *
 * `expires_in` is here so a client can say "this link is good for ten minutes" rather than hardcoding a
 * number it would have to keep in step with `publishing.oauth.state_ttl`. It is computed from the
 * state's own signed expiry, so the two cannot disagree.
 *
 * NOT here: the state on its own, the nonce, the redirect URI, or anything about the credential that the
 * handshake will eventually produce.
 *
 * NOR THE BROWSER BINDING. {@see StartedHandshake} also carries the cookie that ties this handshake to
 * the browser asking for it, and it is deliberately not a field: a binding readable by script is a
 * binding an attacker's script can read, which is the whole reason it is `HttpOnly`. The controller
 * attaches it to the RESPONSE; the body says nothing about it.
 */
class PlatformAuthorizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var StartedHandshake $handshake */
        $handshake = $this->resource;

        return [
            'authorize_url' => $handshake->authorizeUrl,
            'expires_in' => $handshake->expiresIn,
        ];
    }
}
