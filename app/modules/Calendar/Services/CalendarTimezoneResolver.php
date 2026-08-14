<?php

namespace App\Modules\Calendar\Services;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;

/**
 * Whose midnight the grid is drawn against.
 *
 * THE ANSWER IS THE WORKSPACE'S, NOT THE BROWSER'S — an owner decision, taken against the alternative.
 * A shared calendar is a coordination surface: two people discussing "Thursday's post" have to mean the
 * same Thursday, and a per-viewer timezone makes that untrue in a way neither of them can see. The cost
 * is real and accepted (a traveller sees the team's day, not their own), and it is paid once rather
 * than being re-litigated at every layer.
 *
 * The corollary is that there is NO `tz` request parameter. Letting a client name the zone would create
 * a second source of truth for the same question, and the two only have to disagree once — in a saved
 * view, a cached response, a link someone pasted — for the grid to become unexplainable.
 *
 * `workspaces.timezone` is nullable and NULL means "inherit `app.timezone`", so an untouched workspace
 * behaves exactly as it did before the column existed.
 *
 * NOTE ON THE MODULE BOUNDARY: this reads the active workspace through App\Tenancy\TenantContext —
 * shared application infrastructure that every module uses — not through the Workspaces module. The
 * boundary the Calendar defends is against the modules that FEED it (Tasks, Workflows, and whatever
 * comes next); tenancy is the floor everything stands on.
 */
class CalendarTimezoneResolver
{
    public function __construct(private TenantContext $tenant) {}

    public function resolve(): string
    {
        $fallback = (string) config('app.timezone', 'UTC');

        $timezone = $this->tenant->workspace()?->timezone;

        if ($timezone === null || $timezone === '') {
            return $fallback;
        }

        // The write path validates with Laravel's `timezone` rule, so this can only fire for a value
        // that arrived some other way (a console fix, a restored dump). Refusing here would take the
        // whole calendar down for one bad string; naming it in the log and standing on the app default
        // keeps the screen alive and the defect findable.
        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            Log::warning('Workspace has an unusable timezone; falling back to the application timezone.', [
                'workspace_id' => $this->tenant->id(),
                'timezone' => $timezone,
            ]);

            return $fallback;
        }

        return $timezone;
    }
}
