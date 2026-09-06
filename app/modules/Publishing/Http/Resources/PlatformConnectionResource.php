<?php

namespace App\Modules\Publishing\Http\Resources;

use App\Http\Resources\CreatorResource;
use App\Modules\Publishing\Models\PlatformConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ONE CONNECTED ACCOUNT, as far as any client is ever allowed to see it.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * THE FIELD LIST IS THE SECURITY BOUNDARY. IT IS EXHAUSTIVE ON PURPOSE.
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * `access_token` and `refresh_token` are not here, and no future field may carry them, be derived from
 * them, or be a signed URL containing one. This is not a matter of taste about API surface: these
 * tokens let this application post publicly as somebody else's channel, and a single line spreading the
 * model into the payload would put them into a browser, a browser cache, and whatever proxy is between.
 *
 * The resource is one of TWO independent guards, and keeping both is deliberate. `PlatformConnection`
 * also declares them `$hidden`, which covers every path that turns a model into text WITHOUT passing
 * through here — a log context, an exception's data, a `dd()`, an endpoint written in a hurry. A
 * whitelist here and a blacklist there fail in different directions, so a mistake has to be made twice.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT IS PUBLISHED AND WHY IT IS SAFE
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 *   external_account_id  The platform's public identifier for the account — a channel id is printed on
 *                        the channel's own page. It is here because it is how a person tells two
 *                        connections to the same platform apart, which a display name alone cannot do.
 *   scopes               What the platform GRANTED, not what we asked for. The two differ when somebody
 *                        unticks a permission on the consent screen, and that difference is the only
 *                        available explanation for a publish that later fails on permissions.
 *   expires_at           Metadata about a credential, not the credential. It is what lets a screen say
 *                        "renews in three days" instead of nothing.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * `credentials_readable` — A HEALTH FLAG THAT EXISTS FOR EXACTLY ONE INCIDENT
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * APP_KEY rotation, or a database restored beside a different application. Every stored token becomes
 * unopenable at once, and the connections screen is where somebody would go to fix it. Without this the
 * resource would have to decrypt to answer anything — and a screen that 500s is a screen nobody can use
 * to repair the problem it is reporting.
 *
 * It answers a BOOLEAN, computed by attempting the decryption and discarding the result. Nothing about
 * the ciphertext, its length or its shape crosses this boundary.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * THE STATUS TRAVELS AS A CODE AND AS PROSE
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * The same contract `PublicationResource` states: clients BRANCH on `status`, never on the wording, and
 * `status_label`/`status_tone` are the server's to own so a connection chip and a publication chip
 * cannot drift apart. `failure_code` is likewise a stable code the client translates — never the
 * platform's own prose, which its servers compose in whatever language they like.
 */
class PlatformConnectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var PlatformConnection $connection */
        $connection = $this->resource;

        return [
            'id' => $connection->id,

            'platform' => $connection->platform->value,
            'platform_label' => $connection->platform->label(),

            // The platform's public identifier for the account. See the class docblock.
            'external_account_id' => $connection->external_account_id,
            'account_name' => $connection->account_name,

            // Branch on the code; render the label.
            'status' => $connection->status->value,
            'status_label' => $connection->status->label(),
            'status_tone' => $connection->status->tone(),
            'needs_attention' => $connection->status->needsAttention(),
            'can_publish' => $connection->status->canPublish(),

            // What was GRANTED, which is not always what was asked for.
            'scopes' => $connection->scopes ?? [],

            'expires_at' => $connection->expires_at?->toISOString(),
            'last_refreshed_at' => $connection->last_refreshed_at?->toISOString(),
            'failure_code' => $connection->failure_code,

            // The APP_KEY-rotation flag. A boolean, and nothing about the ciphertext.
            'credentials_readable' => $connection->hasReadableCredentials(),

            'creator' => CreatorResource::make($this->whenLoaded('creator')),

            // `is_owner` is HUMAN authorship only; `can_be_disconnected` routes through the policy and
            // therefore includes the workspace-owner fallback. Gate UI actions on the latter.
            'is_owner' => $connection->isOwnedBy($request->user()),
            'can_be_disconnected' => $request->user()?->can('delete', $connection) ?? false,

            'created_at' => $connection->created_at?->toISOString(),
            'updated_at' => $connection->updated_at?->toISOString(),
        ];
    }
}
