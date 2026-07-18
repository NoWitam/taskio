<?php

namespace Database\Factories;

use App\Modules\Disk\Models\Folder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Folder>
 */
class FolderFactory extends Factory
{
    protected $model = Folder::class;

    /**
     * A root folder by default. `path` is intentionally NOT set here: the model computes it
     * on creating (it has to contain the folder's own id), which also keeps factory-made rows
     * honest about the materialized-path invariant.
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'parent_id' => null,
        ];
    }

    /** A child of $parent (its path is derived from the parent's on save). */
    public function childOf(Folder $parent): static
    {
        return $this->state(fn () => ['parent_id' => $parent->id]);
    }
}
