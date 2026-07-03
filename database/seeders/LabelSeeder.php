<?php

namespace Database\Seeders;

use App\Enums\IconEnum;
use App\Modules\Labels\Models\Label;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Database\Seeder;

class LabelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $workspace = Workspace::query()->orderBy('created_at')->firstOrFail();

        $n = 15;

        for ($i = 0; $i < $n; $i++) {
            $label = new Label([
                'name' => fake()->text(12),
                'icon' => fake()->randomDigit() < 5 ? fake()->randomElement(array_column(IconEnum::cases(), 'value')) : null,
                'color' => fake()->randomDigit() < 3 ? fake()->hexColor() : null,
            ]);

            $label->workspace_id = $workspace->id;
            $label->save();
        }
    }
}
