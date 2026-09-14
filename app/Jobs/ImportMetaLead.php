<?php

namespace App\Jobs;

use App\Services\MetaLeadService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Fetches one lead from Meta and stores it.
 *
 * Queued because the webhook must answer within seconds; the Graph call is a
 * network round trip that can be slow or briefly fail.
 */
class ImportMetaLead implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** Back off rather than hammering Graph when it is rate limiting. */
    public array $backoff = [10, 30, 60, 300];

    public function __construct(
        public string $leadgenId,
        public ?string $formId = null,
        public ?string $pageId = null,
    ) {}

    public function handle(MetaLeadService $meta): void
    {
        $payload = $meta->fetch($this->leadgenId);

        if ($payload === null) {
            // fetch() already logged the reason; retry via the queue.
            $this->release(30);

            return;
        }

        $lead = $meta->import($payload, $this->formId, $this->pageId);

        Log::info('Imported Meta lead', [
            'leadgen_id' => $this->leadgenId,
            'lead_id' => $lead->id,
            'was_new' => $lead->wasRecentlyCreated,
        ]);
    }

    /** Dedupe key: Meta can deliver the same leadgen_id more than once. */
    public function uniqueId(): string
    {
        return $this->leadgenId;
    }
}
