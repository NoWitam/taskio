<?php

namespace App\Traits;

/**
 * Pins a model to the CENTRAL (landlord) database connection.
 *
 * Use on models whose rows ALWAYS live in the central DB (users, workspaces, …) so
 * they are never routed to a tenant connection. This matters specifically when such
 * a model is loaded as a RELATION from a tenant-connected model: Eloquent's
 * `newRelatedInstance` copies the PARENT's connection onto a related model that has
 * no explicit connection of its own. A `User` loaded via `$tenantTask->creator`
 * would therefore inherit the `tenant` connection and query `users` in the tenant
 * database — where that central table does not exist.
 *
 * By returning a NON-NULL connection name, the related instance keeps the central
 * connection and `newRelatedInstance` leaves it untouched.
 */
trait UsesCentralConnection
{
    public function getConnectionName()
    {
        return $this->connection ?? config('database.default');
    }
}
