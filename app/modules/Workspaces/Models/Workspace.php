<?php

namespace App\Modules\Workspaces\Models;

use App\Models\AbstractModel;
use App\Models\User;
use App\Modules\Workspaces\Enums\WorkspaceDbMode;
use App\Modules\Workspaces\Enums\WorkspaceStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Workspace extends AbstractModel
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'name',
        'owner_id',
        'db_mode',
        'status',
        'db_driver',
        'db_host',
        'db_port',
        'db_database',
        'db_username',
        'db_password',
    ];

    protected function casts(): array
    {
        return [
            'db_mode' => WorkspaceDbMode::class,
            'status' => WorkspaceStatus::class,
            'db_password' => 'encrypted',
        ];
    }

    /**
     * Build the database connection config for an own-database workspace,
     * layered over the base connection of its driver.
     *
     * @return array<string, mixed>
     */
    public function connectionConfig(): array
    {
        $driver = $this->db_driver ?? config('database.default');

        $base = config('database.connections.' . $driver, []);

        $overrides = array_filter([
            'driver' => $this->db_driver,
            'host' => $this->db_host,
            'port' => $this->db_port,
            'database' => $this->db_database,
            'username' => $this->db_username,
            'password' => $this->db_password,
        ], fn ($value) => $value !== null);

        return array_merge($base, $overrides);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user');
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner_id === $user->id;
    }

    public function hasMember(User $user): bool
    {
        // The membership existence check must run UNSCOPED. With an active workspace,
        // the User global scope (WorkspaceMemberScope) would constrain the relation
        // existence query to members of the ACTIVE workspace, not of $this — which is
        // wrong when checking a DIFFERENT workspace (e.g. ResolveWorkspace gating, or
        // hasMember against a workspace the user is about to switch into).
        return $this->isOwnedBy($user)
            || $this->users()->withoutWorkspaceMemberScope()->whereKey($user->id)->exists();
    }

    protected static function newFactory()
    {
        return \Database\Factories\WorkspaceFactory::new();
    }
}
