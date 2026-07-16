<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Modules\Workspaces\Models\Workspace>
 */
class WorkspaceFactory extends Factory
{
    protected $model = Workspace::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'owner_id' => User::factory(),
            'db_mode' => 'shared',
        ];
    }
}
