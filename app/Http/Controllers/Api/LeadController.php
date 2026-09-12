<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $leads = Lead::query()
            ->when($request->string('stage')->toString(), fn ($q, $stage) => $q->where('stage', $stage))
            ->when($request->string('search')->toString(), fn ($q, $term) => $q->where(
                fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('detail', 'like', "%{$term}%")
            ))
            ->with('owner:id,name')
            ->latest('created_at')
            ->paginate(min($request->integer('perPage', 25), 100));

        return response()->json([
            'data' => collect($leads->items())->map($this->present(...))->all(),
            'meta' => [
                'page' => $leads->currentPage(),
                'perPage' => $leads->perPage(),
                'total' => $leads->total(),
                'lastPage' => $leads->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $lead = Lead::create($this->validated($request));

        return response()->json($this->present($lead->fresh('owner')), 201);
    }

    public function show(Lead $lead): JsonResponse
    {
        return response()->json($this->present($lead->load('owner:id,name')));
    }

    public function update(Request $request, Lead $lead): JsonResponse
    {
        $lead->update($this->validated($request, partial: true));

        return response()->json($this->present($lead->fresh('owner')));
    }

    public function destroy(Lead $lead): JsonResponse
    {
        $lead->delete();

        return response()->json(status: 204);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? ['sometimes'] : ['required'];

        return $request->validate([
            'name' => [...$required, 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'detail' => ['nullable', 'string', 'max:255'],
            'stage' => ['sometimes', Rule::in(array_keys(Lead::STAGES))],
            'value' => ['sometimes', 'integer', 'min:0'],
            'owner_id' => ['nullable', 'exists:users,id'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(Lead $lead): array
    {
        return [
            'id' => (string) $lead->id,
            'name' => $lead->name,
            'initials' => $lead->initials(),
            'email' => $lead->email,
            'phone' => $lead->phone,
            'detail' => $lead->detail ?? '',
            'stage' => $lead->stage,
            'stageLabel' => Lead::STAGES[$lead->stage] ?? $lead->stage,
            'value' => $lead->value,
            'owner' => $lead->owner?->only('id', 'name'),
            'receivedLabel' => $lead->created_at->diffForHumans(short: true),
            'createdAt' => $lead->created_at->toIso8601String(),
        ];
    }
}
