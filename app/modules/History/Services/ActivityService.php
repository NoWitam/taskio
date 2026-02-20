<?php

namespace App\Modules\History\Services;

use App\Modules\History\Enums\ActivityEvent;
use App\Modules\History\Models\Activity;
use Illuminate\Database\Eloquent\Model;

class ActivityService
{
    protected array $excludedAttributes = [
        'updated_at',
        'created_at',
        'deleted_at'
    ];

    public function log(
        Model $subject,
        ActivityEvent $event,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $description = null,
        ?string $causerId = null
    ): Activity {
        // Filtruj excluded attributes
        $oldValues = $oldValues ? $this->filterAttributes($oldValues) : null;
        $newValues = $newValues ? $this->filterAttributes($newValues) : null;

        return Activity::create([
            'subject_type' => get_class($subject),
            'subject_id' => $subject->getKey(),
            'causer_id' => $causerId ?? auth()->id(),
            'event' => $event,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'description' => $description ?? $event->getDescription()
        ]);
    }

    public function getHistory(Model $subject)
    {
        return Activity::where('subject_type', get_class($subject))
            ->where('subject_id', $subject->getKey())
            ->with('causer')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    protected function filterAttributes(array $attributes): array
    {
        return array_filter(
            $attributes,
            fn($key) => !in_array($key, $this->excludedAttributes),
            ARRAY_FILTER_USE_KEY
        );
    }
}
