<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * closed_at and lost_at must always agree with the stage. If they drift, the
 * pipeline and the revenue figures tell different stories and there is no way
 * to tell which is right.
 */
class DealStampingTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create([
            'name' => 'Ali Khan',
            'email' => 'ali'.uniqid().'@managerox.com',
            'password' => 'password',
        ]);
    }

    public function test_closing_a_deal_stamps_closed_at(): void
    {
        $user = $this->user();

        $id = $this->actingAs($user)->postJson('/api/deals', [
            'title' => 'Villa purchase', 'stage' => 'proposal', 'value' => 5_000_000,
        ])->assertCreated()->json('id');

        $this->assertNull(Deal::find($id)->closed_at);

        $this->actingAs($user)->patchJson("/api/deals/{$id}", ['stage' => 'closed'])
            ->assertOk()
            ->assertJsonPath('isWon', true);

        $this->assertNotNull(Deal::find($id)->closed_at);
    }

    public function test_reopening_a_deal_clears_closed_at(): void
    {
        $user = $this->user();
        $id = $this->actingAs($user)->postJson('/api/deals', [
            'title' => 'Villa purchase', 'stage' => 'closed', 'value' => 1_000_000,
        ])->json('id');

        $this->assertNotNull(Deal::find($id)->closed_at);

        $this->actingAs($user)->patchJson("/api/deals/{$id}", ['stage' => 'negotiation'])->assertOk();

        $this->assertNull(Deal::find($id)->closed_at);
    }

    public function test_a_lost_deal_is_never_counted_as_won(): void
    {
        $user = $this->user();
        $id = $this->actingAs($user)->postJson('/api/deals', [
            'title' => 'Villa purchase', 'stage' => 'closed', 'value' => 9_000_000,
        ])->json('id');

        $this->actingAs($user)->patchJson("/api/deals/{$id}", ['lost' => true])
            ->assertOk()
            ->assertJsonPath('isWon', false)
            ->assertJsonPath('isLost', true);

        $deal = Deal::find($id);
        $this->assertNull($deal->closed_at);
        $this->assertNotNull($deal->lost_at);
        $this->assertSame(0, Deal::won()->count());
        // And it drops out of the funnel.
        $this->assertSame(0, Deal::active()->count());
    }

    public function test_lost_deals_are_hidden_from_the_list_unless_asked_for(): void
    {
        $user = $this->user();
        $id = $this->actingAs($user)->postJson('/api/deals', ['title' => 'Lost one'])->json('id');
        $this->actingAs($user)->patchJson("/api/deals/{$id}", ['lost' => true]);

        $this->actingAs($user)->getJson('/api/deals')->assertJsonPath('meta.total', 0);
        $this->actingAs($user)->getJson('/api/deals?includeLost=1')->assertJsonPath('meta.total', 1);
    }
}
