<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $contacts = Contact::query()
            ->when($request->string('search')->toString(), fn ($q, $term) => $q->where(
                fn ($q) => $q->where('name', 'like', "%{$term}%")
                    ->orWhere('company', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
            ))
            ->withCount(['deals' => fn ($q) => $q->whereNull('lost_at')])
            ->withSum(['deals' => fn ($q) => $q->where('stage', 'closed')->whereNull('lost_at')], 'value')
            ->with('owner:id,name')
            ->latest('created_at')
            ->paginate(min($request->integer('perPage', 25), 100));

        return response()->json([
            'data' => collect($contacts->items())->map($this->present(...))->all(),
            'meta' => $this->meta($contacts),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $contact = Contact::create($this->validated($request));

        return response()->json($this->present($contact->fresh(['owner'])->loadCount('deals')), 201);
    }

    public function show(Contact $contact): JsonResponse
    {
        return response()->json($this->present(
            $contact->load('owner:id,name', 'deals')->loadCount('deals'),
        ));
    }

    public function update(Request $request, Contact $contact): JsonResponse
    {
        $contact->update($this->validated($request, partial: true));

        return response()->json($this->present($contact->fresh(['owner'])->loadCount('deals')));
    }

    public function destroy(Contact $contact): JsonResponse
    {
        $contact->delete();

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
            'company' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'owner_id' => ['nullable', 'exists:users,id'],
        ]);
    }

    /** @return array<string, mixed> */
    private function meta($paginator): array
    {
        return [
            'page' => $paginator->currentPage(),
            'perPage' => $paginator->perPage(),
            'total' => $paginator->total(),
            'lastPage' => $paginator->lastPage(),
        ];
    }

    /** @return array<string, mixed> */
    private function present(Contact $contact): array
    {
        return [
            'id' => (string) $contact->id,
            'name' => $contact->name,
            'initials' => $contact->initials(),
            'email' => $contact->email,
            'phone' => $contact->phone,
            'company' => $contact->company,
            'notes' => $contact->notes,
            'owner' => $contact->owner?->only('id', 'name'),
            'openDeals' => (int) ($contact->deals_count ?? 0),
            'wonValue' => (int) ($contact->deals_sum_value ?? 0),
            'addedLabel' => $contact->created_at->diffForHumans(short: true),
            'createdAt' => $contact->created_at->toIso8601String(),
        ];
    }
}
