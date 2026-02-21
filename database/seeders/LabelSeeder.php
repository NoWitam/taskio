<?php

namespace Database\Seeders;

use App\Enums\IconEnum;
use App\Modules\Labels\Models\Label;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class LabelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $n = 30;

        for($i=0; $i<$n; $i++) {
            Label::create([
                'name' => fake()->text(12),
                'icon' => fake()->randomDigit() < 4 ? fake()->randomElement(array_column(IconEnum::cases(), 'value')) : null,
                'color' => fake()->randomDigit() < 2 ? fake()->hexColor() : null
            ]);
        }
    }
}
