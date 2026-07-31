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
 *
 * `status` is the enum's WIRE value: the vocabulary stays exactly `queued|processing|done|failed`,
 * because clients settle their polling on those and an unknown terminal value would hang them.
 * A failure that has a MACHINE-READABLE reason adds `error_code` alongside the human `error` —
 * additive, absent otherwise, so telling "the provider's safety system refused this" apart from
 * "the provider broke" costs no consumer anything.
 */
class DiskAiEditResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->wireStatus(),
            'image' => $this->when($this->status === DiskAiEditStatus::Done, fn () => $this->result_image),
            'mime' => $this->when($this->status === DiskAiEditStatus::Done, 'image/png'),
            'error' => $this->when($this->status->isFailure(), fn () => $this->error),
            'error_code' => $this->when($this->status->errorCode() !== null, fn () => $this->status->errorCode()),
        ];
    }
}
