<?php

namespace App\Modules\Changelog\Managers;

use App\Modules\Changelog\Enums\ChangelogEvent;
use App\Modules\Changelog\Interfaces\HasChangelog;
use App\Modules\Tasks\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Model;

class StatusTracker extends AbstractTracker
{
    public function __construct(string $field = 'status')
    {
        parent::__construct($field);
        $this->event = ChangelogEvent::CHANGE_STATUS;
    }

    /**
     * Zapisz oryginalną wartość statusu
     */
    public function saveOriginals(Model&HasChangelog $model): void
    {
        $this->savedOriginals = [
            'original' => $model->getOriginal('status'),
        ];
    }

    /**
     * Przygotuj dane zmian statusu
     */
    public function prepare(Model&HasChangelog $model): ?array
    {
        $original = $this->savedOriginals['original'] ?? null;
        $current = $model->getAttribute('status');

        // Jeśli status się nie zmienił, nie loguj
        if ($original === $current) {
            return null;
        }

        $beforeStatus = $original instanceof TaskStatus ? $original : TaskStatus::from($original);
        $afterStatus = $current instanceof TaskStatus ? $current : TaskStatus::from($current);

        return [
            'type' => 'status_change',
            'before' => [
                'label' => $beforeStatus->label(),
                'tone' => $beforeStatus->tone(),
                'icon' => $beforeStatus->icon(),
            ],
            'after' => [
                'label' => $afterStatus->label(),
                'tone' => $afterStatus->tone(),
                'icon' => $afterStatus->icon(),
            ]
        ];
    }
}
