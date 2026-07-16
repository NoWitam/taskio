<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Disk\Enums\FileType;
use App\Modules\Disk\Models\File;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @extends Factory<File>
 */
class FileFactory extends Factory
{
    protected $model = File::class;

    /**
     * A temp upload (no parent) by default — `fileable_type` NULL is what File::scopeTemp
     * matches, i.e. an uploaded-but-not-yet-attached file. `uploader_type` is stamped to
     * 'user' by HasCreator on save.
     *
     * NOTE: this only creates the ROW. Tests that read the bytes must also put them on the
     * (faked) disk under `path`.
     */
    public function definition(): array
    {
        return [
            'name' => fake()->slug(2) . '.pdf',
            'path' => 'uploads/' . Str::uuid() . '.pdf',
            'type' => FileType::DOCUMENT,
            'mime_type' => 'application/pdf',
            'size' => fake()->numberBetween(1_024, 5_242_880),
            'uploader_id' => User::factory(),
        ];
    }

    /** An image — the one family that renders inline (`?inline=1`). */
    public function image(): static
    {
        return $this->state(fn () => [
            'name' => fake()->slug(2) . '.png',
            'path' => 'uploads/' . Str::uuid() . '.png',
            'type' => FileType::IMAGE,
            'mime_type' => 'image/png',
        ]);
    }

    /** Attached to a parent (a task attachment, a report's file, …) — no longer a temp file. */
    public function attachedTo(Model $parent): static
    {
        return $this->state(fn () => [
            'fileable_id' => $parent->getKey(),
            'fileable_type' => $parent->getMorphClass(),
        ]);
    }
}
