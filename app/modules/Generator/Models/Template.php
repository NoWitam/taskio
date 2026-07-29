<?php

namespace App\Modules\Generator\Models;

use App\Models\AbstractModel;
use App\Modules\Generator\Services\ContentTypeRegistry;
use App\Modules\Generator\Support\ContentTypeDefinition;
use App\Traits\HasCreator;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * A generation TEMPLATE: a user-created, workspace-scoped reusable RECIPE for a finished post (a content
 * factory). It is authored AS THE POST, not as a prompt:
 *   - `content_type`  the recipe SHAPE (a {@see ContentTypeRegistry} id — post / post_with_image /
 *                     video_script); its PARTS drive the editor sections, validation and render.
 *   - `content`       a per-part MAP keyed by the type's part keys (D3). Each part's value shape follows
 *                     its {@see \App\Modules\Generator\Enums\PartKind}: a text_body/script part = `{markdown}`
 *                     (the post body, carrying the SAME `@[variable]` / `@[ai-text]` directives); an
 *                     image_plan part = `{base, filters[]}` (a base + an ordered filter chain); a scene_plan
 *                     part = `{scenes:[{narration, image_plan?}]}`.
 *   - `slots`         the DECLARED typed inputs ({name, description?, descriptor}) the content references.
 *
 * Like a Constant / CustomFunction it is ALWAYS user-created (no engine authors one), so the HasCreator
 * system-record fallback never applies; identity is the uuid and `name` is a user-facing label only (NOT
 * unique). `slots` + `content` ride the json 'array' cast unchanged.
 *
 * The Generator module CONSUMES the Variables module (the type system, catalog composition, and the
 * shared interpolation engine) and imports NOTHING from Workflows — a one-way boundary mirroring
 * Variables → (nothing). In this sub-stage a template is a DEFINITION only: no generation Session runs.
 */
class Template extends AbstractModel
{
    use HasCreator, HasFactory, HasUuids, TenantAware;

    protected $table = 'templates';

    protected $fillable = [
        'name',
        'description',
        'content_type',
        'slots',
        'content',
        'creator_id',
    ];

    protected $casts = [
        // The declared slots ({name, description?, descriptor} list) and the per-part `content` map are
        // always JSON objects/arrays.
        'slots' => 'array',
        'content' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** The content-type DEFINITION this template is a recipe for (null when the stored id is unknown). */
    public function definition(): ?ContentTypeDefinition
    {
        return app(ContentTypeRegistry::class)->find((string) $this->content_type);
    }

    /**
     * The authored content of one PART (by its key), or null when the part is absent — a per-part getter
     * over the `content` map so callers read a part without re-deriving the cast shape.
     */
    public function partContent(string $key): mixed
    {
        $content = is_array($this->content) ? $this->content : [];

        return $content[$key] ?? null;
    }

    protected static function newFactory()
    {
        return \Database\Factories\TemplateFactory::new();
    }
}
