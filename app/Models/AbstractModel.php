<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AbstractModel extends Model
{
    public function scopeFilterByDate(Builder $query, string $column, ?Request $request = null, string $queryParam = 'date'): void
    {
        $request ??= request();

        $preset = $request->string($queryParam . '_preset')->value;
        
        [$from, $to] = match($preset)
        {
            'today' => [now(), now()],
            'this_week' => [now()->startOfWeek(), now()->endOfWeek()],
            'last_week' => [now()->subWeek()->startOfWeek(), now()->subWeek()->endOfWeek()],
            'this_month' => [now()->startOfMonth(), now()->endOfMonth()],
            default => [$request->date($queryParam . '_from'), $request->date($queryParam . '_from')]
        };
    
        if(!is_null($from)) {
            $query->where($column, '>=', $from->startOfDay());
        }

        if(!is_null($to)) {
            $query->where($column, '<=', $to->startOfDay());
        }
    }
}
