<?php

namespace App\Modules\Forms\Models;

use App\Models\AbstractModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FormContentVersion extends AbstractModel
{
    use HasUuids;

    protected $table = 'form_content_versions';

    public $timestamps = false;

    protected $fillable = [
        'form_id',
        'parent_id',
        'version',
        'content',
        'json_schema',
        'created_at',
    ];

    protected $casts = [
        'content' => 'array',
        'json_schema' => 'array',
        'version' => 'integer',
        'created_at' => 'datetime',
    ];

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class, 'form_content_version_id');
    }
}
