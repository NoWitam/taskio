<?php

namespace App\Modules\Publishing\Http\Requests;

use App\Modules\Publishing\Enums\PublishingPlatform;
use App\Modules\Publishing\Models\PlatformConnection;
use App\Modules\Publishing\Services\OAuthProviderRegistry;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates and authorizes the request that STARTS an OAuth handshake.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE DESTINATION IS A ROUTE SEGMENT, SO THERE ARE TWO REFUSALS AND THEY ARE DIFFERENT
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The route constrains `{platform}` to the enum's values, so an unrecognised word is a 404 before this
 * class is constructed — the right answer for a URL that names nothing.
 *
 * What is left for here is the destination that EXISTS and cannot be connected. `dry_run` publishes
 * nothing, has no account behind it and never will; asking to authorize it is a caller mistake with an
 * obvious explanation, so it is a 422 with a sentence rather than a 404 that says the URL is wrong.
 *
 * And separately: a real destination this installation has no CREDENTIALS for. That is the shipped state
 * of every platform here — the applications with Google and Meta do not exist yet — and it must not
 * become a redirect. Sending somebody to a consent screen with an empty `client_id` produces the
 * platform's own error page, in the platform's language, with no way back into our flow and nothing on
 * our side that noticed. Refused at the door, with prose that says what is missing.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * AUTHORIZATION DELEGATES TO THE POLICY, AS EVERYWHERE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `create` on {@see PlatformConnectionPolicy}, which today answers "any member" and carries the argument
 * for and against in its own docblock. The check is here rather than in the controller or the service so
 * that tightening it is one edit in one file.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THERE IS NO BODY
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Nothing a client could send is used. In particular a client may NOT choose a redirect URI or supply a
 * state: the redirect must match one registered with the platform (an attacker-chosen one is the classic
 * open-redirect leg of an OAuth code theft), and a state minted anywhere but the server would be a claim
 * about a workspace with no signature behind it. Both are the server's, absolutely.
 */
class AuthorizePlatformConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', PlatformConnection::class) ?? false;
    }

    public function rules(): array
    {
        return [];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $platform = $this->resolvedPlatform();

            if (!app(OAuthProviderRegistry::class)->has($platform)) {
                $validator->errors()->add('platform', __('publishing.validation.platform_not_connectable'));

                return;
            }

            if (!app(OAuthProviderRegistry::class)->resolve($platform)->isConfigured()) {
                $validator->errors()->add('platform', __('publishing.validation.platform_not_configured'));
            }
        });
    }

    /**
     * The destination named by the URL.
     *
     * `from()` rather than `tryFrom()` is safe because the route constrains the segment to the enum's
     * values — an unmatched word never reaches a controller, let alone this. Written as the strict form
     * on purpose: if that route constraint is ever loosened, this throws instead of silently reading the
     * segment as some default.
     */
    public function resolvedPlatform(): PublishingPlatform
    {
        return PublishingPlatform::from((string) $this->route('platform'));
    }
}
