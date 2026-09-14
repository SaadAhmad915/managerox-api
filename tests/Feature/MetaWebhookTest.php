<?php

namespace Tests\Feature;

use App\Jobs\ImportMetaLead;
use App\Models\Lead;
use App\Services\MetaLeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MetaWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.meta.app_secret', 'test-app-secret');
        config()->set('services.meta.verify_token', 'test-verify-token');
        config()->set('services.meta.page_token', 'test-page-token');
    }

    /** @return array{0: string, 1: string} raw body and its Meta signature */
    private function signed(array $payload, string $secret = 'test-app-secret'): array
    {
        $body = json_encode($payload);

        return [$body, 'sha256='.hash_hmac('sha256', $body, $secret)];
    }

    private function postSigned(string $body, ?string $signature)
    {
        return $this->call('POST', '/api/webhooks/meta/leads', [], [], [], array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ]), $body);
    }

    private function leadgenPayload(string $leadgenId = '900001'): array
    {
        return ['entry' => [[
            'changes' => [[
                'field' => 'leadgen',
                'value' => [
                    'leadgen_id' => $leadgenId,
                    'form_id' => 'form-1',
                    'page_id' => 'page-1',
                ],
            ]],
        ]]];
    }

    // --- subscription handshake --------------------------------------------

    public function test_it_echoes_the_challenge_when_the_verify_token_matches(): void
    {
        $this->get('/api/webhooks/meta/leads?hub_mode=subscribe&hub_verify_token=test-verify-token&hub_challenge=abc123')
            ->assertOk()
            ->assertSee('abc123');
    }

    /**
     * Meta sends hub.mode / hub.verify_token / hub.challenge with DOTS. PHP
     * rewrites dots to underscores in query keys, which is the only reason
     * reading hub_mode works — so assert the real format, not our convenient one.
     */
    public function test_it_handles_metas_dotted_query_parameters(): void
    {
        $this->get('/api/webhooks/meta/leads?hub.mode=subscribe&hub.verify_token=test-verify-token&hub.challenge=xyz789')
            ->assertOk()
            ->assertSee('xyz789');
    }

    public function test_it_refuses_the_handshake_when_the_verify_token_is_wrong(): void
    {
        $this->get('/api/webhooks/meta/leads?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=abc123')
            ->assertForbidden();
    }

    // --- signature ----------------------------------------------------------

    public function test_it_rejects_a_webhook_with_no_signature(): void
    {
        Queue::fake();
        [$body] = $this->signed($this->leadgenPayload());

        $this->postSigned($body, null)->assertStatus(401);

        Queue::assertNothingPushed();
    }

    public function test_it_rejects_a_webhook_signed_with_the_wrong_secret(): void
    {
        Queue::fake();
        [$body] = $this->signed($this->leadgenPayload());
        $forged = 'sha256='.hash_hmac('sha256', $body, 'not-the-secret');

        $this->postSigned($body, $forged)->assertStatus(401);

        Queue::assertNothingPushed();
    }

    public function test_it_fails_closed_when_no_app_secret_is_configured(): void
    {
        config()->set('services.meta.app_secret', null);
        Queue::fake();
        [$body, $signature] = $this->signed($this->leadgenPayload());

        $this->postSigned($body, $signature)->assertStatus(500);

        Queue::assertNothingPushed();
    }

    public function test_it_accepts_a_correctly_signed_webhook_and_queues_the_import(): void
    {
        Queue::fake();
        [$body, $signature] = $this->signed($this->leadgenPayload());

        $this->postSigned($body, $signature)->assertOk();

        Queue::assertPushed(
            ImportMetaLead::class,
            fn (ImportMetaLead $job) => $job->leadgenId === '900001'
                && $job->formId === 'form-1'
                && $job->pageId === 'page-1',
        );
    }

    public function test_it_ignores_changes_that_are_not_leadgen(): void
    {
        Queue::fake();
        [$body, $signature] = $this->signed(
            ['entry' => [['changes' => [['field' => 'feed', 'value' => []]]]]],
        );

        $this->postSigned($body, $signature)->assertOk();

        Queue::assertNothingPushed();
    }

    // --- import -------------------------------------------------------------

    public function test_it_maps_meta_field_data_onto_a_lead(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'id' => '900001',
            'created_time' => '2026-09-14T09:30:00+0000',
            'form_id' => 'form-1',
            'field_data' => [
                ['name' => 'full_name', 'values' => ['Hina Raza']],
                ['name' => 'email', 'values' => ['hina@example.com']],
                ['name' => 'phone_number', 'values' => ['+923001234567']],
                ['name' => 'which_property_type', 'values' => ['Villa']],
            ],
        ])]);

        (new ImportMetaLead('900001', 'form-1', 'page-1'))->handle(app(MetaLeadService::class));

        $lead = Lead::where('external_id', '900001')->sole();

        $this->assertSame('Hina Raza', $lead->name);
        $this->assertSame('hina@example.com', $lead->email);
        $this->assertSame('+923001234567', $lead->phone);
        $this->assertSame('new', $lead->stage);
        $this->assertSame('meta', $lead->source);
        $this->assertSame('Which Property Type: Villa', $lead->detail);
        // Unmapped custom answers must survive verbatim.
        $this->assertSame('Villa', $lead->payload['which_property_type']);
    }

    public function test_it_does_not_duplicate_a_lead_when_meta_redelivers(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'id' => '900002',
            'field_data' => [['name' => 'full_name', 'values' => ['Repeat Person']]],
        ])]);

        foreach (range(1, 3) as $ignored) {
            (new ImportMetaLead('900002'))->handle(app(MetaLeadService::class));
        }

        $this->assertSame(1, Lead::where('external_id', '900002')->count());
    }

    public function test_it_still_imports_a_lead_with_no_recognisable_fields(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'id' => '900003',
            'field_data' => [['name' => 'budget_range', 'values' => ['5-10M']]],
        ])]);

        (new ImportMetaLead('900003'))->handle(app(MetaLeadService::class));

        $lead = Lead::where('external_id', '900003')->sole();

        $this->assertSame('Meta lead', $lead->name);
        $this->assertNull($lead->email);
        $this->assertSame('Budget Range: 5-10M', $lead->detail);
    }
}
