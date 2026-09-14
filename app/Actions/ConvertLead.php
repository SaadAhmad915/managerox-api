<?php

namespace App\Actions;

use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turns a qualified enquiry into a Contact and a Deal.
 *
 * Converting twice would silently duplicate a person and their pipeline, so it
 * is refused. The whole thing runs in a transaction: a lead marked converted
 * with no deal behind it is worse than a failed conversion, because nothing in
 * the UI would show the work was lost.
 */
class ConvertLead
{
    /** @return array{contact: Contact, deal: Deal} */
    public function handle(Lead $lead, ?string $dealTitle = null, int $value = 0): array
    {
        if ($lead->isConverted()) {
            throw new RuntimeException('This lead has already been converted.');
        }

        return DB::transaction(function () use ($lead, $dealTitle, $value) {
            $contact = Contact::create([
                'name' => $lead->name,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'owner_id' => $lead->owner_id,
                'lead_id' => $lead->id,
                // The enquiry text is the only context the person arrived with.
                'notes' => $lead->detail,
            ]);

            $deal = Deal::create([
                'title' => $dealTitle ?: ($lead->detail ?: $lead->name),
                'contact_id' => $contact->id,
                'owner_id' => $lead->owner_id,
                'lead_id' => $lead->id,
                'stage' => 'qualified',
                'value' => $value,
            ]);

            $lead->update([
                'status' => 'converted',
                'converted_at' => now(),
            ]);

            return ['contact' => $contact, 'deal' => $deal];
        });
    }
}
