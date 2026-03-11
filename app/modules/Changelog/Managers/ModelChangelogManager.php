<?php

namespace App\Modules\Changelog\Managers;

use App\Modules\Changelog\Interfaces\HasChangelog;
use Illuminate\Database\Eloquent\Model;

class ModelChangelogManager
{
    protected array $trackers = [];
    protected Model&HasChangelog $model;

    /**
     * @param Model&HasChangelog $model
     * @param array<AbstractTracker> $trackers
     */
    public function __construct(Model&HasChangelog $model, array $trackers = [])
    {
        $this->model = $model;
        $this->trackers = $trackers;
    }

    /**
     * Pobierz tracker po nazwie pola
     */
    public function getTracker(string $field): ?AbstractTracker
    {
        return collect($this->trackers)->first(function (AbstractTracker $tracker) use ($field) {
            return $tracker->getField() === $field;
        });
    }

    /**
     * Pobierz wszystkie trackery
     * 
     * @return array<AbstractTracker>
     */
    public function getTrackers(): array
    {
        return $this->trackers;
    }

    /**
     * Hook wywoływany w updating event (przed zapisem)
     * Opcjonalnie nadpisz w klasach potomnych
     */
    public function onUpdating(): void
    {
        // Override in child classes if needed
    }

    /**
     * Hook wywoływany w updated event (po zapisie)
     * Opcjonalnie nadpisz w klasach potomnych
     */
    public function onUpdated(): void
    {
        // Override in child classes if needed
    }

    /**
     * Pobierz model
     */
    public function getModel(): Model&HasChangelog
    {
        return $this->model;
    }
}
