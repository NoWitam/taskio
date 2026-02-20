<?php

namespace App\Modules\Changelog\Managers;

use App\Modules\Changelog\Enums\ChangelogEvent;
use App\Modules\Changelog\Interfaces\HasChangelog;
use App\Modules\Changelog\Models\Changelog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class ChangelogManager
{
    protected array $cache = [];
    protected array $originals = [];
    protected array $managers = []; // Cache'owane managery z trackerami per model

    /**
     * Pobierz lub utwórz manager z trackerami dla danego modelu
     */
    protected function getOrCreateManager(Model&HasChangelog $subject): ModelChangelogManager
    {
        $modelKey = get_class($subject) . '_' . $subject->getKey();
        
        if (!isset($this->managers[$modelKey])) {
            $this->managers[$modelKey] = $subject->getChangelogManager();
        }
        
        return $this->managers[$modelKey];
    }

    /**
     * Obsługa updating event - zapisuje oryginalne wartości dla trackerów z opt-in
     */
    public function handleUpdating(Model&HasChangelog $subject): void
    {
        $manager = $this->getOrCreateManager($subject);
        
        // Wywołaj hook onUpdating w managerze encji (opcjonalnie)
        $manager->onUpdating();

        $key = $this->getCacheKey($subject, ChangelogEvent::UPDATED);

        // Iteruj przez trackery które mają opt-in dla zapisywania oryginalnych wartości
        foreach ($manager->getTrackers() as $tracker) {
            if ($tracker->shouldTrackOriginalInUpdating()) {
                $tracker->saveOriginals($subject);
            }
        }
    }

    /**
     * Główna metoda logowania zmian
     */
    public function log(Model&HasChangelog $subject, ChangelogEvent $event): void
    {
        // Dla eventów innych niż UPDATED - zapisz z pustym details, bez wywoływania managera encji
        if($event != ChangelogEvent::UPDATED) { 
            $this->storeLog($subject, $event);
            return;
        }

        // Dla UPDATED - wywołaj manager encji i zbierz details z trackerów
        $manager = $this->getOrCreateManager($subject);
        $manager->onUpdated(); // Hook opcjonalny

        $details = [];

        foreach($manager->getTrackers() as $tracker)
        {
            // Pomiń trackery oznaczone jako manualOnly
            if ($tracker->isManualOnly()) {
                continue;
            }

            $data = $tracker->prepare($subject);

            if($data) {
                $details[$tracker->getField()] = $data;
            }
        }

        $this->storeLog($subject, $event, $details);
    }

    /**
     * Ręczne logowanie zmian (np. dla relacji many-to-many)
     */
    public function manual(Model&HasChangelog $subject, string $field, callable $call): void
    {
        $manager = $this->getOrCreateManager($subject);
        $tracker = $manager->getTracker($field);

        if($tracker == null) {
            throw new RuntimeException($subject::class . " has not implemented changelog tracker for {$field} field.");
        }

        $call($tracker);

        $details = $tracker->prepare($subject);
        
        if(!empty($details)) {
            $this->storeLog($subject, ChangelogEvent::UPDATED, [$field => $details]);
        }
    }

    /**
     * Pobierz tracker dla danego modelu i pola (publiczny dostęp)
     */
    public function getTracker(Model&HasChangelog $subject, string $field): ?AbstractTracker
    {
        $manager = $this->getOrCreateManager($subject);
        return $manager->getTracker($field);
    }

    /**
     * Obsługa customowych eventów
     */
    public function handleCustomEvent(Model&HasChangelog $subject, ChangelogEvent $event, array $details = []): void
    {
        $this->storeLog($subject, $event, $details);
    }

    /**
     * Zapisz log do cache (zostanie zapisany w destruktorze)
     */
    protected function storeLog(Model&HasChangelog $subject, ChangelogEvent $event, array $details = []): void
    {
        $key = $this->getCacheKey($subject, $event);

        if(array_key_exists($key, $this->cache)) {
            $oldDetails = $this->cache[$key];
            $this->cache[$key] = array_merge($oldDetails, $details);
        } else {
            $this->cache[$key] = $details;
        }
    }

    /**
     * Generuj klucz cache dla danego modelu i eventu
     */
    protected function getCacheKey(Model&HasChangelog $subject, ChangelogEvent $event): string
    {
        return implode("_", [
            Relation::getMorphAlias($subject::class),
            $subject->getKey(),
            $event->value
        ]);
    }

    /**
     * Destruktor - zapisz wszystkie logi z cache do bazy danych
     */
    function __destruct()
    {
        if(!empty($this->cache)) {
            $cache = $this->cache;
            $causer = Auth::id();

            // defer(function () use ($cache, $causer) {
                foreach($cache as $key => $details) {
                    [$type, $id, $event] = explode("_", $key);

                    Changelog::create([
                        'subject_type' => $type,
                        'subject_id' => $id,
                        'causer_id' => $causer,
                        'event' => $event,
                        'details' => $details
                    ]);
                }
            // });
        }
    }
}
