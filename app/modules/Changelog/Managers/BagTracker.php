<?php

namespace App\Modules\Changelog\Managers;

use App\Modules\Changelog\Interfaces\HasChangelog;
use Closure;
use Illuminate\Database\Eloquent\Model;

class BagTracker extends AbstractTracker
{
    protected ?Closure $map = null;
    protected ?string $component = null;
    protected ?string $class = null;

    protected array $attached = [];
    protected array $detached = [];

    /**
     * Dla BagTracker zapisujemy puste, bo trackujemy manualnie przez attach/detach
     */
    public function saveOriginals(Model&HasChangelog $model): void
    {
        $this->savedOriginals = [];
    }

    public function withMap(Closure $map): self
    {
        $this->map = $map;

        return $this;
    }

    public function asComponent(string $component): self
    {
        $this->component = $component;

        return $this;
    }

    public function asClass(string $class): self
    {
        $this->class = $class;

        return $this;
    }

    public function attach(Model|string $modelOrKey)
    {
        $key = is_string($modelOrKey) ? $modelOrKey : $modelOrKey->getKey();

<<<<<<< HEAD:app/modules/Changelog/Managers/BagTracker.php
        if(array_key_exists($key, $this->dettached)) {
            unset($this->dettached[$key]);
=======
        if(array_key_exists($key, $this->detached)) {
            unset($this->detached[$key]);
>>>>>>> main:app/modules/History/Managers/BagLog.php

            return;
        }

        $this->attached[$key] = is_string($modelOrKey) ? $key : $modelOrKey; 
    }

    public function detach(Model|string $modelOrKey)
    {
        $key = is_string($modelOrKey) ? $modelOrKey : $modelOrKey->getKey();

        if(array_key_exists($key, $this->attached)) {
            unset($this->attached[$key]);

            return;
        }

        $this->detached[$key] = is_string($modelOrKey) ? $key : $modelOrKey; 
    }


    public function prepare(Model&HasChangelog $model): ?array
    {
        if(empty($this->attached) AND empty($this->dettached)) {
            return null;
        }

        if($this->class != null) {
            $unLoadedAttachd = collect($this->attached)->filter(function ($value) {
                return is_string($value);
            })->toArray();

            $unLoadedDettached = collect($this->dettached)->filter(function ($value) {
                return is_string($value);
            })->toArray();

            $loadedModels = $this->class::whereIn('id', array_merge($unLoadedAttachd, $unLoadedDettached))->get();
            
            foreach ($loadedModels as $loadedModel) {
                if(array_key_exists($loadedModel->getKey(), $this->attached)) {
                    $this->attached[$loadedModel->getKey()] = $loadedModel;
                } else if(array_key_exists($loadedModel->getKey(), $this->dettached)) {
                    $this->dettached[$loadedModel->getKey()] = $loadedModel;
                }
            }
        }
        
        $map = $this->map;

        return [
            'type' => 'bag',
            'component' => $this->component,
            'attached' => $map == null ? array_values($this->attached) : collect($this->attached)->map(function ($value) use ($map, $model) {
                return $map($value, $model);
            })->values()->toArray(),
            'dettached' => $map == null ? array_values($this->dettached) : collect($this->dettached)->map(function ($value) use ($map, $model) {
                return $map($value, $model);
            })->values()->toArray(),
        ];
    }
}
