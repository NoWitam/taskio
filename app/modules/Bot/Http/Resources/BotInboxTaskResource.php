<?php

namespace App\Modules\Bot\Http\Resources;

use App\Modules\Tasks\Http\Resources\TaskListResource;
use Illuminate\Http\Request;

/**
 * A Bot Inbox task row: the standard TaskListResource fields plus the derived
 * `inbox_state` (set on the task model by BotInboxService). Keeps TaskListResource
 * unchanged for every other list screen.
 *
 * @mixin \App\Modules\Tasks\Models\Task
 */
class BotInboxTaskResource extends TaskListResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'inbox_state' => $this->getAttribute('inbox_state'),
        ]);
    }
}
