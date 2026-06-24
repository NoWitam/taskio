<?php

namespace App\Modules\Changelog\Services;

use App\Modules\Changelog\Enums\ChangelogEvent;
use App\Modules\Changelog\Models\Changelog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ChangelogService
{
    protected array $excludedAttributes = [
        'updated_at',
        'created_at',
        'deleted_at',
    ];

    public function log(
        Model $subject,
        ChangelogEvent $event,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $description = null,
        ?string $causerId = null
    ): Changelog {
        // Filtruj excluded attributes
        $oldValues = $oldValues ? $this->filterAttributes($oldValues) : null;
        $newValues = $newValues ? $this->filterAttributes($newValues) : null;

        return Changelog::create([
            'subject_type' => get_class($subject),
            'subject_id' => $subject->getKey(),
            'causer_id' => $causerId ?? Auth::id(),
            'event' => $event,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'description' => $description ?? $event->getDescription(),
        ]);
    }

    public function getChangelogs(Model $subject)
    {
        return Changelog::where('subject_type', get_class($subject))
            ->where('subject_id', $subject->getKey())
            // The causer is a frozen audit fact — keep it resolvable even for a
            // user who has since left the workspace (bypass WorkspaceMemberScope).
            ->with(['causer' => fn ($query) => $query->withoutWorkspaceMemberScope()])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    protected function filterAttributes(array $attributes): array
    {
        return array_filter(
            $attributes,
            fn ($key) => !in_array($key, $this->excludedAttributes),
            ARRAY_FILTER_USE_KEY
        );
    }
}
