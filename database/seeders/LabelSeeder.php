<?php

namespace Database\Seeders;

use App\Enums\IconEnum;
use App\Models\User;
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
        $workspace = Workspace::query()->orderBy('created_at')->first()
            ?? Workspace::factory()->for(User::query()->firstOrFail(), 'owner')->create();

        $n = 30;

        for ($i = 0; $i < $n; $i++) {
            $label = new Label([
                'name' => fake()->text(12),
                'icon' => fake()->randomDigit() < 4 ? fake()->randomElement(array_column(IconEnum::cases(), 'value')) : null,
                'color' => fake()->randomDigit() < 2 ? fake()->hexColor() : null,
            ]);

            $label->workspace_id = $workspace->id;
            $label->save();
        }
    }
}
