<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TaskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filter = $request->string('filter')->toString();

        $tasks = Task::query()
            ->when($filter === 'open', fn ($q) => $q->where('done', false))
            ->when($filter === 'done', fn ($q) => $q->where('done', true))
            ->when($filter === 'overdue', fn ($q) => $q->where('done', false)->where('due_at', '<', now()))
            ->when($filter === 'today', fn ($q) => $q->where('done', false)
                ->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()]))
            ->with(['contact:id,name', 'deal:id,title'])
            ->orderBy('done')
            ->orderBy('due_at')
            ->paginate(min($request->integer('perPage', 50), 100));

        return response()->json([
            'data' => collect($tasks->items())->map($this->present(...))->all(),
            'meta' => [
                'page' => $tasks->currentPage(),
                'perPage' => $tasks->perPage(),
                'total' => $tasks->total(),
                'lastPage' => $tasks->lastPage(),
            ],
            'counts' => [
                'open' => Task::where('done', false)->count(),
                'overdue' => Task::where('done', false)->where('due_at', '<', now())->count(),
                'today' => Task::where('done', false)
                    ->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()])->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $task = Task::create($this->validated($request) + ['user_id' => $request->user()->id]);

        return response()->json($this->present($task->fresh(['contact', 'deal'])), 201);
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        $task->update($this->validated($request, partial: true));

        return response()->json($this->present($task->fresh(['contact', 'deal'])));
    }

    public function destroy(Task $task): JsonResponse
    {
        $task->delete();

        return response()->json(status: 204);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? ['sometimes'] : ['required'];

        return $request->validate([
            'title' => [...$required, 'string', 'max:255'],
            'due_at' => [...$required, 'date'],
            'done' => ['sometimes', 'boolean'],
            'contact_id' => ['nullable', 'exists:contacts,id'],
            'deal_id' => ['nullable', 'exists:deals,id'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(Task $task): array
    {
        return [
            'id' => (string) $task->id,
            'title' => $task->title,
            'dueAt' => $task->due_at->toIso8601String(),
            'dueLabel' => $this->dueLabel($task->due_at),
            'done' => $task->done,
            'isOverdue' => ! $task->done && $task->due_at->isPast(),
            'priority' => ! $task->done && ($task->due_at->isToday() || $task->due_at->isPast())
                ? 'urgent'
                : 'normal',
            'contact' => $task->contact?->only('id', 'name'),
            'deal' => $task->deal?->only('id', 'title'),
        ];
    }

    private function dueLabel(Carbon $due): string
    {
        $day = match (true) {
            $due->isToday() => 'Today',
            $due->isTomorrow() => 'Tomorrow',
            $due->isYesterday() => 'Yesterday',
            default => $due->format('D j M'),
        };

        return $day.', '.$due->format('g:i A');
    }
}
