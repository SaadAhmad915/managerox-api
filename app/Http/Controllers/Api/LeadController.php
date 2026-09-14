<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Actions\ConvertLead;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $leads = Lead::query()
            ->when($request->string('status')->toString(), fn ($q, $status) => $q->where('status', $status))
            ->when($request->boolean('openOnly'), fn ($q) => $q->whereIn('status', Lead::OPEN_STATUSES))
            ->when($request->string('source')->toString(), fn ($q, $source) => $q->where('source', $source))
            ->when($request->string('search')->toString(), fn ($q, $term) => $q->where(
                fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('detail', 'like', "%{$term}%")
            ))
            ->with('owner:id,name', 'contact:id,lead_id', 'deal:id,lead_id')
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
        return response()->json($this->present(
            $lead->load('owner:id,name', 'contact:id,lead_id', 'deal:id,lead_id'),
        ));
    }

    /**
     * Turns a qualified enquiry into a Contact and a Deal. Converting twice
     * would duplicate both, so a second attempt is refused rather than
     * silently creating a parallel set of records.
     */
    public function convert(Request $request, Lead $lead, ConvertLead $converter): JsonResponse
    {
        $input = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'value' => ['sometimes', 'integer', 'min:0'],
        ]);

        if ($lead->isConverted()) {
            return response()->json([
                'message' => 'This lead has already been converted.',
            ], 422);
        }

        $result = $converter->handle($lead, $input['title'] ?? null, (int) ($input['value'] ?? 0));

        return response()->json([
            'lead' => $this->present($lead->fresh(['owner', 'contact', 'deal'])),
            'contactId' => (string) $result['contact']->id,
            'dealId' => (string) $result['deal']->id,
        ], 201);
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
            'status' => ['sometimes', Rule::in(array_keys(Lead::STATUSES))],
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
            'status' => $lead->status,
            'statusLabel' => Lead::STATUSES[$lead->status] ?? $lead->status,
            'source' => $lead->source,
            'isConverted' => $lead->isConverted(),
            'contactId' => $lead->contact?->id ? (string) $lead->contact->id : null,
            'dealId' => $lead->deal?->id ? (string) $lead->deal->id : null,
            'owner' => $lead->owner?->only('id', 'name'),
            'receivedLabel' => $lead->created_at->diffForHumans(short: true),
            'createdAt' => $lead->created_at->toIso8601String(),
        ];
    }
}
