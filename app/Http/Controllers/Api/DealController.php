<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DealController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $deals = Deal::query()
            ->when($request->string('stage')->toString(), fn ($q, $stage) => $q->where('stage', $stage))
            ->when($request->string('search')->toString(), fn ($q, $term) => $q->where('title', 'like', "%{$term}%"))
            // Lost deals are history: shown only when explicitly asked for.
            ->when(! $request->boolean('includeLost'), fn ($q) => $q->whereNull('lost_at'))
            ->with(['contact:id,name', 'owner:id,name'])
            ->latest('created_at')
            ->paginate(min($request->integer('perPage', 25), 100));

        return response()->json([
            'data' => collect($deals->items())->map($this->present(...))->all(),
            'meta' => [
                'page' => $deals->currentPage(),
                'perPage' => $deals->perPage(),
                'total' => $deals->total(),
                'lastPage' => $deals->lastPage(),
            ],
            'summary' => [
                'openValue' => (int) Deal::active()
                    ->whereIn('stage', Deal::OPEN_STAGES)->sum('value'),
                'wonValueThisMonth' => (int) Deal::won()
                    ->where('closed_at', '>=', now()->startOfMonth())->sum('value'),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $deal = Deal::create($this->stamped($this->validated($request)));

        return response()->json($this->present($deal->fresh(['contact', 'owner'])), 201);
    }

    public function show(Deal $deal): JsonResponse
    {
        return response()->json($this->present($deal->load('contact:id,name', 'owner:id,name')));
    }

    public function update(Request $request, Deal $deal): JsonResponse
    {
        $deal->update($this->stamped($this->validated($request, partial: true), $deal));

        return response()->json($this->present($deal->fresh(['contact', 'owner'])));
    }

    public function destroy(Deal $deal): JsonResponse
    {
        $deal->delete();

        return response()->json(status: 204);
    }

    /**
     * Keeps closed_at and lost_at consistent with the stage, so reporting
     * cannot disagree with what the pipeline shows. Setting a stage by hand and
     * forgetting the timestamp is exactly how revenue figures drift.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function stamped(array $data, ?Deal $existing = null): array
    {
        $stage = $data['stage'] ?? $existing?->stage ?? 'new';
        $lost = array_key_exists('lost', $data)
            ? (bool) $data['lost']
            : $existing?->lost_at !== null;

        unset($data['lost']);

        $data['lost_at'] = $lost ? ($existing?->lost_at ?? now()) : null;
        $data['closed_at'] = $stage === 'closed' && ! $lost
            ? ($existing?->closed_at ?? now())
            : null;

        return $data;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? ['sometimes'] : ['required'];

        return $request->validate([
            'title' => [...$required, 'string', 'max:255'],
            'contact_id' => ['nullable', 'exists:contacts,id'],
            'owner_id' => ['nullable', 'exists:users,id'],
            'stage' => ['sometimes', Rule::in(array_keys(Deal::STAGES))],
            'value' => ['sometimes', 'integer', 'min:0'],
            'expected_close_on' => ['nullable', 'date'],
            'lost' => ['sometimes', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(Deal $deal): array
    {
        return [
            'id' => (string) $deal->id,
            'title' => $deal->title,
            'stage' => $deal->stage,
            'stageLabel' => Deal::STAGES[$deal->stage] ?? $deal->stage,
            'value' => $deal->value,
            'contact' => $deal->contact?->only('id', 'name'),
            'owner' => $deal->owner?->only('id', 'name'),
            'isWon' => $deal->isWon(),
            'isLost' => $deal->lost_at !== null,
            'expectedCloseOn' => $deal->expected_close_on?->toDateString(),
            'addedLabel' => $deal->created_at->diffForHumans(short: true),
            'createdAt' => $deal->created_at->toIso8601String(),
        ];
    }
}
