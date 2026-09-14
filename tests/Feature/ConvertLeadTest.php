<?php

namespace Tests\Feature;

use App\Actions\ConvertLead;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ConvertLeadTest extends TestCase
{
    use RefreshDatabase;

    private function lead(array $overrides = []): Lead
    {
        $user = User::create([
            'name' => 'Ali Khan',
            'email' => 'ali'.uniqid().'@managerox.com',
            'password' => 'password',
            'role' => 'Sales Manager',
        ]);

        return Lead::create([
            'name' => 'Hina Raza',
            'email' => 'hina@example.com',
            'phone' => '+923001234567',
            'detail' => 'Villa – DHA Islamabad',
            'status' => 'qualified',
            'owner_id' => $user->id,
        ] + $overrides);
    }

    public function test_it_creates_a_contact_and_a_deal_from_a_lead(): void
    {
        $lead = $this->lead();

        $result = (new ConvertLead)->handle($lead, 'Villa purchase', 12_000_000);

        $this->assertSame('Hina Raza', $result['contact']->name);
        $this->assertSame('hina@example.com', $result['contact']->email);
        $this->assertSame($lead->id, $result['contact']->lead_id);

        $this->assertSame('Villa purchase', $result['deal']->title);
        $this->assertSame(12_000_000, $result['deal']->value);
        $this->assertSame('qualified', $result['deal']->stage);
        $this->assertSame($result['contact']->id, $result['deal']->contact_id);

        // Ownership follows the person who was working the enquiry.
        $this->assertSame($lead->owner_id, $result['deal']->owner_id);
    }

    public function test_it_marks_the_lead_converted(): void
    {
        $lead = $this->lead();

        (new ConvertLead)->handle($lead);

        $lead->refresh();
        $this->assertSame('converted', $lead->status);
        $this->assertNotNull($lead->converted_at);
        $this->assertTrue($lead->isConverted());
    }

    public function test_it_refuses_to_convert_the_same_lead_twice(): void
    {
        $lead = $this->lead();
        (new ConvertLead)->handle($lead);

        $this->expectException(RuntimeException::class);
        (new ConvertLead)->handle($lead->fresh());
    }

    public function test_converting_twice_creates_nothing_extra(): void
    {
        $lead = $this->lead();
        (new ConvertLead)->handle($lead);

        try {
            (new ConvertLead)->handle($lead->fresh());
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(1, Contact::where('lead_id', $lead->id)->count());
        $this->assertSame(1, Deal::where('lead_id', $lead->id)->count());
    }

    public function test_it_falls_back_to_the_enquiry_text_for_the_deal_title(): void
    {
        $lead = $this->lead();

        $result = (new ConvertLead)->handle($lead);

        $this->assertSame('Villa – DHA Islamabad', $result['deal']->title);
    }

    public function test_the_endpoint_rejects_a_second_conversion(): void
    {
        $lead = $this->lead();
        $user = User::first();

        $this->actingAs($user)
            ->postJson("/api/leads/{$lead->id}/convert", ['value' => 500000])
            ->assertCreated();

        $this->actingAs($user)
            ->postJson("/api/leads/{$lead->id}/convert")
            ->assertStatus(422);

        $this->assertSame(1, Contact::where('lead_id', $lead->id)->count());
    }
}
