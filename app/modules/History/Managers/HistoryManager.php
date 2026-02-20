<?php

namespace App\Modules\History\Managers;

use App\Modules\History\Enums\ActivityEvent;
use App\Modules\History\Interfaces\HasHistory;
use App\Modules\History\Models\Activity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use RuntimeException;

class HistoryManager
{
    protected array $cache = [];

    public function log(Model&HasHistory $subject, ActivityEvent $event): void
    {
        if($event != ActivityEvent::UPDATED) { 
            $this->storeLog($subject, $event);

            return;
        }

        $details = [];

        foreach($subject::getHistoryOptions() as $manager)
        {
            $data = $manager->prepare($subject);

            if($data) {
                $details[$manager->getField()] = $data;
            }
        }

        $this->storeLog($subject, $event, $details);
    }

    public function manual(Model&HasHistory $subject, string $field, callable $call): void
    {
        dump('manual');
        $log = collect($subject->getHistoryOptions())->first(function (AbstractLog $log) use ($field) {
            return $log->getField() == $field;
        });

        if($log == null) {
            throw new RuntimeException($subject::class . " has not implemented history manager for {$field} field.");
        }

        $call($log);

        $details = $log->prepare($subject);
        dump($details);
        if(!empty($details)) {
            $this->storeLog($subject, ActivityEvent::UPDATED, $details);
        }
    }

    protected function storeLog(Model&HasHistory $subject, ActivityEvent $event, array $details = []): void
    {
        $key = implode("_", [
            Relation::getMorphAlias($subject::class),
            $subject->getKey(),
            $event->value
        ]);

        if(array_key_exists($key, $this->cache)) {
            $oldDetails = $this->cache[$key];
            $this->cache[$key] = array_merge($oldDetails, $details);
        } else {
            $this->cache[$key] = $details;
        }
    }

    function __destruct()
    {
        dump($this->cache);
        if(!empty($this->cache)) {
            $cache = $this->cache;
            $causer = auth()->id();

            // defer(function () use ($cache, $causer) {
                foreach($cache as $key => $details) {
                    [$type, $id, $event] = explode("_", $key);

                    Activity::create([
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
