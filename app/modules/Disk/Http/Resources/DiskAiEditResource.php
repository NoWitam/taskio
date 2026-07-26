<?php

namespace App\Modules\Disk\Http\Resources;

use App\Modules\Disk\Enums\DiskAiEditStatus;
use App\Modules\Disk\Models\DiskAiEdit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DiskAiEdit
 *
 * The async AI image edit's status shape. `id` + `status` are always present; `image` (base64 PNG)
 * and `mime` appear only once done, and `error` only once failed — so the dispatch response
 * (202, queued) and every subsequent poll share ONE resource.
 */
class DiskAiEditResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'image' => $this->when($this->status === DiskAiEditStatus::Done, fn () => $this->result_image),
            'mime' => $this->when($this->status === DiskAiEditStatus::Done, 'image/png'),
            'error' => $this->when($this->status === DiskAiEditStatus::Failed, fn () => $this->error),
        ];
    }
}
