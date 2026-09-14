<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads a lead from Meta's Graph API and turns it into a CRM lead.
 *
 * Instant Forms let advertisers write their own questions, so the field names
 * are only partly predictable. Known contact fields are mapped explicitly and
 * everything else is kept verbatim in `payload`, so a form change never
 * silently loses an answer.
 */
class MetaLeadService
{
    /** Meta field names that mean "this person's name". */
    private const NAME_KEYS = ['full_name', 'name', 'first_name'];

    private const EMAIL_KEYS = ['email', 'work_email'];

    private const PHONE_KEYS = ['phone_number', 'phone', 'work_phone_number'];

    public function fetch(string $leadgenId): ?array
    {
        $token = config('services.meta.page_token');

        if (blank($token)) {
            Log::error('META_PAGE_TOKEN is not set; cannot read lead from Meta.');

            return null;
        }

        $version = config('services.meta.graph_version');
        $base = config('services.meta.graph_url');

        $response = Http::retry(3, 200, throw: false)
            ->timeout(15)
            ->get("{$base}/{$version}/{$leadgenId}", [
                'access_token' => $token,
                'fields' => 'id,created_time,form_id,field_data',
            ]);

        if ($response->failed()) {
            Log::error('Could not read Meta lead', [
                'leadgen_id' => $leadgenId,
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            return null;
        }

        return $response->json();
    }

    /**
     * Creates the lead, or returns the existing one if this leadgen_id has
     * already been imported. Meta redelivers on any non-2xx, so imports must
     * be idempotent.
     */
    public function import(array $meta, ?string $formId, ?string $pageId): Lead
    {
        $answers = $this->flatten($meta['field_data'] ?? []);

        return Lead::firstOrCreate(
            ['external_id' => (string) $meta['id']],
            [
                'name' => $this->pick($answers, self::NAME_KEYS) ?? 'Meta lead',
                'email' => $this->pick($answers, self::EMAIL_KEYS),
                'phone' => $this->pick($answers, self::PHONE_KEYS),
                'detail' => $this->describe($answers),
                'status' => 'new',
                'source' => 'meta',
                'form_id' => $meta['form_id'] ?? $formId,
                'page_id' => $pageId,
                'payload' => $answers,
                'created_at' => isset($meta['created_time'])
                    ? \Illuminate\Support\Carbon::parse($meta['created_time'])
                    : now(),
            ],
        );
    }

    /** field_data is [{name, values: []}, …]; flatten to name => first value. */
    private function flatten(array $fieldData): array
    {
        $out = [];

        foreach ($fieldData as $field) {
            $name = $field['name'] ?? null;
            if (blank($name)) {
                continue;
            }
            $out[$name] = $field['values'][0] ?? null;
        }

        return $out;
    }

    private function pick(array $answers, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (filled($answers[$key] ?? null)) {
                return (string) $answers[$key];
            }
        }

        return null;
    }

    /**
     * A one-line summary for list views, built from whatever custom question
     * the form asked. Falls back to naming the source.
     */
    private function describe(array $answers): string
    {
        $skip = [...self::NAME_KEYS, ...self::EMAIL_KEYS, ...self::PHONE_KEYS];

        foreach ($answers as $key => $value) {
            if (! in_array($key, $skip, true) && filled($value)) {
                return str($key)->replace('_', ' ')->title().': '.$value;
            }
        }

        return 'Meta Instant Form';
    }
}
