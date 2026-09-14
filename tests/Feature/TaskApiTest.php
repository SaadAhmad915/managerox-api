<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskApiTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create([
            'name' => 'Ali Khan',
            'email' => 'ali'.uniqid().'@managerox.com',
            'password' => 'password',
        ]);
    }

    public function test_it_lists_tasks_with_their_linked_records(): void
    {
        $user = $this->user();
        $contact = Contact::create(['name' => 'Hina Raza']);
        Task::create([
            'title' => 'Call Hina',
            'due_at' => now()->addDay(),
            'user_id' => $user->id,
            'contact_id' => $contact->id,
        ]);

        $this->actingAs($user)->getJson('/api/tasks')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.title', 'Call Hina')
            ->assertJsonPath('data.0.contact.name', 'Hina Raza');
    }

    public function test_it_flags_overdue_and_counts_them(): void
    {
        $user = $this->user();
        Task::create(['title' => 'Late one', 'due_at' => now()->subDay(), 'user_id' => $user->id]);
        Task::create(['title' => 'Future one', 'due_at' => now()->addWeek(), 'user_id' => $user->id]);

        $response = $this->actingAs($user)->getJson('/api/tasks?filter=overdue')->assertOk();

        $response->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.title', 'Late one')
            ->assertJsonPath('data.0.isOverdue', true)
            ->assertJsonPath('data.0.priority', 'urgent')
            ->assertJsonPath('counts.overdue', 1)
            ->assertJsonPath('counts.open', 2);
    }

    public function test_completing_a_task_clears_its_urgency(): void
    {
        $user = $this->user();
        $task = Task::create(['title' => 'Late one', 'due_at' => now()->subDay(), 'user_id' => $user->id]);

        $this->actingAs($user)->patchJson("/api/tasks/{$task->id}", ['done' => true])
            ->assertOk()
            ->assertJsonPath('done', true)
            // A finished task is not overdue, however late it was.
            ->assertJsonPath('isOverdue', false)
            ->assertJsonPath('priority', 'normal');
    }

    public function test_it_creates_a_task_owned_by_the_signed_in_user(): void
    {
        $user = $this->user();

        $id = $this->actingAs($user)->postJson('/api/tasks', [
            'title' => 'Prepare proposal',
            'due_at' => now()->addDays(2)->toIso8601String(),
        ])->assertCreated()->json('id');

        $this->assertSame($user->id, Task::find($id)->user_id);
    }
}
