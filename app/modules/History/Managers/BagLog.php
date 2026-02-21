<?php

namespace App\Modules\History\Managers;

use App\Modules\History\Interfaces\HasHistory;
use Closure;
use Illuminate\Database\Eloquent\Model;

class BagLog extends AbstractLog
{
    protected ?Closure $map = null;
    protected ?string $component = null;
    protected ?string $class = null;

    protected array $attached = [];
    protected array $detached = [];

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

        if(array_key_exists($key, $this->detached)) {
            unset($this->detached[$key]);

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


    public function prepare(Model&HasHistory $model): ?array
    {
        if(empty($this->attached) AND empty($this->dettached)) {
            dump('prepare null');
            return null;
        }

        if($this->class != null) {
            $unLoadedAttachd = collect($this->attached)->filter(function ($value) {
                return is_string($value);
            })->toArray();

            $unLoadedDettached = collect($this->dettached)->filter(function ($value) {
                return is_string($value);
            })->toArray();

            $this->class::whereIn('id', array_merge($unLoadedAttachd, $unLoadedDettached))
                ->get()
                ->map(function ($model) {
                    if(array_key_exists($model->getKey(), $this->attached)) {
                        $this->attached[$model->getKey()] = $model;
                    } else if(array_key_exists($model->getKey(), $this->dettached)) {
                        $this->dettached[$model->getKey()] = $model;
                    }
                });
        }
        
        $map = $this->map;

        return [
            'type' => 'bag',
            'component' => $this->component,
            'attached' => $map == null ? $this->attached : collect($this->attached)->map($map)->toArray(),
            'dettached' => $map == null ? $this->dettached : collect($this->dettached)->map($map)->toArray(),
        ];
    }
}
