<?php

namespace App\Modules\Publishing\Http\Requests;

use App\Modules\Publishing\Models\PlatformConnection;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorises DISCONNECTING an account.
 *
 * The same shape as {@see DestroyPublicationRequest} — no rules, because authorization is the whole of
 * what this needs checking for — but the act behind it is wider than a delete, and the docblock says so
 * because the route's verb does not.
 *
 * Disconnecting does three things, in one transaction
 * ({@see \App\Modules\Publishing\Managers\PlatformConnectionManager::revoke()}):
 *   • every publication SCHEDULED on this connection is put on hold, keeping the moment it was armed
 *     for, under `connection_disconnected`;
 *   • the connection is marked `revoked`, so the row says why it is gone rather than only that it is;
 *   • the row is SOFT-deleted, so published publications keep resolving to the account they went out on.
 *
 * `PlatformConnectionPolicy::delete()` is creator-or-workspace-owner rather than any member, and that is
 * the reason: this is not "remove a record I made", it is "stop the team's queue for this account".
 *
 * There is no state test in the policy, unlike a publication's delete. A connection is disconnectable in
 * every status a route can reach — `needs_reauth` most of all, since "this is broken, take it away" is
 * the likeliest thing somebody wants — and `revoked` never binds, because the row is already trashed.
 */
class DestroyPlatformConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $connection = $this->route('connection');

        if (!$connection instanceof PlatformConnection) {
            return false;
        }

        return $this->user()?->can('delete', $connection) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
