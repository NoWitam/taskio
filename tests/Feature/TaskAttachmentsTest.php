<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Disk\Enums\FileType;
use App\Modules\Disk\Models\File;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private function tempFile(User $uploader, string $name = 'doc.pdf'): File
    {
        return File::create([
            'name' => $name,
            'path' => 'uploads/' . fake()->uuid() . '.pdf',
            'type' => FileType::DOCUMENT,
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'uploader_id' => $uploader->id,
        ]);
    }

    public function test_update_attaches_new_files_without_removing_existing(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->create([
            'creator_id' => $user->id,
            'assigned_id' => $user->id,
        ]);

        $existing = $this->tempFile($user, 'existing.pdf');
        $existing->update([
            'fileable_id' => $task->id,
            'fileable_type' => $task->getMorphClass(),
        ]);

        $newFile = $this->tempFile($user, 'new.pdf');

        $response = $this->actingAs($user)->putJson("/api/tasks/{$task->id}", [
            'title' => $task->title,
            'priority' => 'high',
            'assigned_id' => $user->id,
            'attachments' => [$newFile->id],
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('files', [
            'id' => $existing->id,
            'fileable_id' => $task->id,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('files', [
            'id' => $newFile->id,
            'fileable_id' => $task->id,
            'fileable_type' => $task->getMorphClass(),
        ]);
        $this->assertSame(2, $task->files()->count());
    }

    public function test_remove_attachment_deletes_only_the_target_file(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->create([
            'creator_id' => $user->id,
            'assigned_id' => $user->id,
        ]);

        $keep = $this->tempFile($user, 'keep.pdf');
        $remove = $this->tempFile($user, 'remove.pdf');
        foreach ([$keep, $remove] as $file) {
            $file->update([
                'fileable_id' => $task->id,
                'fileable_type' => $task->getMorphClass(),
            ]);
        }

        $response = $this->actingAs($user)
            ->deleteJson("/api/tasks/{$task->id}/attachments/{$remove->id}");

        $response->assertOk();

        $this->assertSoftDeleted('files', ['id' => $remove->id]);
        $this->assertDatabaseHas('files', [
            'id' => $keep->id,
            'deleted_at' => null,
        ]);
        $this->assertSame(1, $task->files()->count());
    }

    public function test_remove_attachment_rejects_a_file_from_another_task(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->create([
            'creator_id' => $user->id,
            'assigned_id' => $user->id,
        ]);
        $otherTask = Task::factory()->create([
            'creator_id' => $user->id,
            'assigned_id' => $user->id,
        ]);

        $foreign = $this->tempFile($user, 'foreign.pdf');
        $foreign->update([
            'fileable_id' => $otherTask->id,
            'fileable_type' => $otherTask->getMorphClass(),
        ]);

        $response = $this->actingAs($user)
            ->deleteJson("/api/tasks/{$task->id}/attachments/{$foreign->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('files', [
            'id' => $foreign->id,
            'deleted_at' => null,
        ]);
    }

    public function test_remove_attachment_is_forbidden_for_unrelated_user(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $task = Task::factory()->create([
            'creator_id' => $owner->id,
            'assigned_id' => $owner->id,
        ]);

        $file = $this->tempFile($owner, 'doc.pdf');
        $file->update([
            'fileable_id' => $task->id,
            'fileable_type' => $task->getMorphClass(),
        ]);

        $response = $this->actingAs($stranger)
            ->deleteJson("/api/tasks/{$task->id}/attachments/{$file->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('files', [
            'id' => $file->id,
            'deleted_at' => null,
        ]);
    }
}
