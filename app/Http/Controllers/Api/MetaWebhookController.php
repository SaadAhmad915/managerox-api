<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ImportMetaLead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives Meta Lead Ads (Instant Form) webhooks.
 *
 * Two endpoints, because Meta uses the same URL for both:
 *  - GET  is the one-off subscription handshake; echo hub.challenge back.
 *  - POST is a lead notification. It carries only ids, never the answers, so
 *    the actual form data is fetched from the Graph API afterwards.
 *
 * The POST must return 200 quickly. Meta retries for hours if it does not, so
 * the Graph call is queued rather than done inline.
 */
class MetaWebhookController extends Controller
{
    /** Subscription handshake. */
    public function verify(Request $request): Response
    {
        $expected = config('services.meta.verify_token');

        if (
            blank($expected)
            || $request->query('hub_mode') !== 'subscribe'
            || ! hash_equals((string) $expected, (string) $request->query('hub_verify_token'))
        ) {
            return response('Verification failed.', 403);
        }

        // Meta requires the raw challenge echoed as plain text.
        return response((string) $request->query('hub_challenge'), 200)
            ->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request): JsonResponse
    {
        foreach ($request->input('entry', []) as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? null) !== 'leadgen') {
                    continue;
                }

                $value = $change['value'] ?? [];
                $leadgenId = $value['leadgen_id'] ?? null;

                if (blank($leadgenId)) {
                    Log::warning('Meta leadgen webhook had no leadgen_id', $value);

                    continue;
                }

                ImportMetaLead::dispatch(
                    leadgenId: (string) $leadgenId,
                    formId: isset($value['form_id']) ? (string) $value['form_id'] : null,
                    pageId: isset($value['page_id']) ? (string) $value['page_id'] : null,
                );
            }
        }

        // Always 200: a non-2xx makes Meta redeliver the same events.
        return response()->json(['received' => true]);
    }
}
