<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Tasks\Models\Task;
use Tests\TestCase;

class CentralConnectionTest extends TestCase
{
    public function test_user_pins_the_central_connection(): void
    {
        $this->assertSame(config('database.default'), (new User)->getConnectionName());
    }

    /**
     * Regression for the own-DB cross-database relation bug: a User loaded as a
     * relation FROM a tenant-connected model must NOT inherit the `tenant`
     * connection (the `users` table only exists in the central database).
     */
    public function test_user_relation_from_a_tenant_connected_model_stays_central(): void
    {
        $task = new Task;
        // Simulate a model hydrated from the dynamic tenant connection.
        $task->setConnection('tenant');

        $related = $task->assigned()->getRelated();

        $this->assertSame(config('database.default'), $related->getConnectionName());
        $this->assertNotSame('tenant', $related->getConnectionName());
    }
}
