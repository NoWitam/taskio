<?php

namespace App\Modules\Generator\Services;

use App\Modules\Generator\DTOs\TemplateDTO;
use App\Modules\Generator\Models\Template;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;

/**
 * Business logic + persistence for TEMPLATES. Single-row writes (no scheduling, no multi-step
 * orchestration), so there is no transaction: each mutation is one atomic save. Queries run through the
 * model so WorkspaceScope / the tenant connection isolate the active workspace in both db_modes — the
 * SAME isolation the ConstantService / CustomFunctionService rely on.
 */
class TemplateService
{
    public function index(Request $request): CursorPaginator
    {
        return Template::query()
            ->with('creator')
            ->search(['name'], $request->get('search'))
            ->orderBy('name')
            ->cursorPaginate(20);
    }

    public function create(TemplateDTO $dto): Template
    {
        $template = new Template($this->attributes($dto));
        $template->save();

        return $template;
    }

    public function update(Template $template, TemplateDTO $dto): Template
    {
        $template->fill($this->attributes($dto));
        $template->save();

        return $template->refresh();
    }

    public function delete(Template $template): void
    {
        $template->delete();
    }

    /**
     * Map the DTO onto persisted columns. The slots + content ride the model's json cast unchanged.
     *
     * @return array<string, mixed>
     */
    private function attributes(TemplateDTO $dto): array
    {
        return [
            'name' => $dto->name,
            'description' => $dto->description,
            'content_type' => $dto->contentType,
            'slots' => $dto->slots,
            'content' => $dto->content,
        ];
    }
}
