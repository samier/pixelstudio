<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Services\Billing\CreditLedger;
use App\Services\Generations\GenerationSync;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

class GenerationBillingTest extends TestCase
{
    use PixelforgeFixtures, RefreshDatabase;

    private const BASE = 'https://pixelforge.test/v1';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.pixelforge.base_url' => self::BASE,
            'services.pixelforge.api_key' => 'pf_test',
            'services.pixelforge.webhook_secret' => self::WEBHOOK_SECRET,
        ]);
        $this->app->forgetInstance(PixelforgeClient::class);
    }

    // ---- Submitting --------------------------------------------------------------------------------------

    public function test_submitting_reserves_the_maximum_estimate_and_queues_the_generation(): void
    {
        Queue::fake();
        Http::fake([self::BASE.'/pricing' => Http::response($this->pricingPayload())]);
        $user = $this->userWithBalance(1);

        $this->actingAs($user)->post('/generations', [
            'prompt' => str_repeat('a', 400),
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png', ''],
        ])->assertSessionHasNoErrors();

        $generation = Generation::sole();
        // Docs' worked example: max provider cost $0.373875 → + 25% = $0.46734375.
        $this->assertSame(467_343_750, $generation->estimated_max_nanos);
        $this->assertSame(467_343_750, $generation->hold_nanos);
        $this->assertSame(GenerationStatus::Queued, $generation->status);
        $this->assertCount(2, $generation->reference_images);
        $this->assertSame(Money::fromDollars(1) - 467_343_750, $user->fresh()->balance_nanos);
        Queue::assertPushed(SubmitGeneration::class);
    }

    public function test_submitting_is_blocked_when_the_user_cannot_afford_the_worst_case(): void
    {
        Queue::fake();
        Http::fake([self::BASE.'/pricing' => Http::response($this->pricingPayload())]);
        $user = User::factory()->create(['balance_nanos' => 400_000_000]); // $0.40 < $0.4673 max

        $this->actingAs($user)->post('/generations', [
            'prompt' => str_repeat('a', 400), 'size' => '1536x1024', 'quality' => 'high',
            'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
        ])->assertSessionHasErrors('credits');

        $this->assertSame(0, Generation::count());
        $this->assertSame(400_000_000, $user->fresh()->balance_nanos);
        Queue::assertNothingPushed();
    }

    public function test_job_sends_the_request_with_an_idempotency_key_and_records_the_provider_id(): void
    {
        $generation = $this->pendingGeneration();
        Http::fake([self::BASE.'/generations' => Http::response($this->generationObject(), 202)]);

        (new SubmitGeneration($generation))->handle(app(PixelforgeClient::class), app(GenerationSync::class));

        Http::assertSent(fn (Request $r) => $r->hasHeader('Idempotency-Key', $generation->uuid)
            && $r['metadata']['generation_uuid'] === $generation->uuid
            && $r['size'] === '1536x1024');
        $this->assertSame('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K', $generation->fresh()->provider_id);
    }

    public function test_overloaded_and_concurrency_limits_are_retried_after_retry_after(): void
    {
        $generation = $this->pendingGeneration();
        Http::fake([self::BASE.'/generations' => Http::response(
            ['error' => ['type' => 'rate_limit_error', 'code' => 'concurrency_limit_exceeded', 'message' => 'Too many']],
            429, ['Retry-After' => '5'],
        )]);

        $job = (new SubmitGeneration($generation))->withFakeQueueInteractions();
        $job->handle(app(PixelforgeClient::class), app(GenerationSync::class));

        $job->assertReleased(5);
        $generation->refresh();
        $this->assertSame(GenerationStatus::Queued, $generation->status);
        $this->assertSame('Waiting for a free slot at Pixelforge', $generation->status_detail);
    }

    public function test_a_rejected_prompt_fails_for_free(): void
    {
        $generation = $this->pendingGeneration();
        $balanceBefore = $generation->user->fresh()->balance_nanos;
        Http::fake([self::BASE.'/generations' => Http::response(
            ['error' => ['type' => 'invalid_request_error', 'code' => 'content_policy_violation', 'message' => 'Rejected']], 400,
        )]);

        (new SubmitGeneration($generation))->handle(app(PixelforgeClient::class), app(GenerationSync::class));

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('content_policy_violation', $generation->error_code);
        $this->assertNull($generation->charged_nanos);
        $this->assertSame($balanceBefore + $generation->hold_nanos, $generation->user->fresh()->balance_nanos);
    }

    // ---- Webhooks ----------------------------------------------------------------------------------------

    public function test_webhooks_with_a_bad_or_stale_signature_are_rejected(): void
    {
        $generation = $this->submittedGeneration();

        $this->postWebhook('generation.succeeded', $this->succeededObject(), secret: 'whsec_wrong')->assertStatus(400);
        $this->postWebhook('generation.succeeded', $this->succeededObject(), timestamp: time() - 600)->assertStatus(400);

        $this->assertSame(GenerationStatus::Queued, $generation->fresh()->status);
        $this->assertSame(0, CreditTransaction::where('type', 'charge')->count());
    }

    public function test_success_charges_the_exact_cost_plus_markup(): void
    {
        $generation = $this->submittedGeneration();
        $user = $generation->user;
        $start = $user->fresh()->balance_nanos + $generation->hold_nanos; // balance before the hold

        $this->postWebhook('generation.succeeded', $this->succeededObject())->assertNoContent();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(305_510_000, $generation->provider_cost_nanos);   // $0.305510000
        $this->assertSame(381_887_500, $generation->charged_nanos);         // × 1.25 = $0.3818875
        $this->assertSame($start - 381_887_500, $user->fresh()->balance_nanos);
        $this->assertSame('2026-11-04 14:03:29', $generation->completed_at->toDateTimeString());
    }

    public function test_the_same_event_delivered_twice_is_recorded_once(): void
    {
        $generation = $this->submittedGeneration();

        $this->postWebhook('generation.succeeded', $this->succeededObject(), eventId: 'evt_1')->assertNoContent();
        $balance = $generation->user->fresh()->balance_nanos;
        $this->postWebhook('generation.succeeded', $this->succeededObject(), eventId: 'evt_1')->assertNoContent();

        $this->assertSame($balance, $generation->user->fresh()->balance_nanos);
        $this->assertSame(1, CreditTransaction::where('type', 'charge')->count());
        $this->assertDatabaseCount('webhook_events', 1);
    }

    public function test_two_different_events_for_the_same_outcome_still_charge_once(): void
    {
        $generation = $this->submittedGeneration();

        $this->postWebhook('generation.succeeded', $this->succeededObject(), eventId: 'evt_1');
        $this->postWebhook('generation.succeeded', $this->succeededObject(), eventId: 'evt_2');
        app(GenerationSync::class)->apply($generation, $this->succeededObject()); // e.g. a poll as well

        $this->assertSame(1, CreditTransaction::where('generation_id', $generation->id)->where('type', 'charge')->count());
        $this->assertSame(1, CreditTransaction::where('generation_id', $generation->id)->where('type', 'hold_release')->count());
    }

    public function test_a_late_processing_event_never_moves_a_generation_back(): void
    {
        $generation = $this->submittedGeneration();

        $this->postWebhook('generation.succeeded', $this->succeededObject());
        $this->postWebhook('generation.processing', $this->generationObject(['status' => 'processing']));

        $this->assertSame(GenerationStatus::Succeeded, $generation->fresh()->status);
    }

    public function test_a_failed_generation_is_free(): void
    {
        $generation = $this->submittedGeneration();
        $before = $generation->user->fresh()->balance_nanos + $generation->hold_nanos;

        $this->postWebhook('generation.failed', $this->failedObject());
        $this->postWebhook('generation.succeeded', $this->succeededObject()); // contradictory late event: ignored

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('internal_error', $generation->error_code);
        $this->assertNull($generation->charged_nanos);
        $this->assertSame($before, $generation->user->fresh()->balance_nanos);
    }

    public function test_a_webhook_that_beats_the_create_response_is_matched_by_metadata(): void
    {
        $generation = $this->pendingGeneration(); // no provider id yet
        Http::fake();

        $this->postWebhook('generation.processing', $this->generationObject([
            'status' => 'processing', 'metadata' => ['generation_uuid' => $generation->uuid],
        ]))->assertNoContent();

        $generation->refresh();
        $this->assertSame('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K', $generation->provider_id);
        $this->assertSame(GenerationStatus::Processing, $generation->status);

        // The (late) create response then says "queued": that must not move it back.
        app(GenerationSync::class)->apply($generation, $this->generationObject());
        $this->assertSame(GenerationStatus::Processing, $generation->fresh()->status);

        // And a retried submit job doesn't call Pixelforge again.
        (new SubmitGeneration($generation))->handle(app(PixelforgeClient::class), app(GenerationSync::class));
        Http::assertNothingSent();
    }

    public function test_an_unconfirmed_submission_is_revived_by_the_real_outcome(): void
    {
        $generation = $this->pendingGeneration();
        app(GenerationSync::class)->failLocally($generation, Generation::SUBMISSION_UNCONFIRMED, 'No answer');
        $before = $generation->user->fresh()->balance_nanos; // hold already released

        $this->postWebhook('generation.succeeded', $this->succeededObject(['metadata' => ['generation_uuid' => $generation->uuid]]));

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertNull($generation->error_code);
        $this->assertSame($before - 381_887_500, $generation->user->fresh()->balance_nanos);
    }

    public function test_events_for_unknown_generations_and_pings_are_acknowledged(): void
    {
        $this->postWebhook('generation.succeeded', $this->succeededObject(['id' => 'gen_someone_else']))->assertNoContent();
        $this->postWebhook('ping', ['object' => 'ping'])->assertNoContent();
    }

    // ---- Polling fallback --------------------------------------------------------------------------------

    public function test_the_sync_command_polls_generations_whose_webhooks_went_missing(): void
    {
        $generation = $this->submittedGeneration();
        $generation->forceFill(['updated_at' => now()->subMinutes(5)])->saveQuietly();
        Http::fake([self::BASE.'/generations/*' => Http::response($this->succeededObject())]);

        $this->artisan('pixelforge:sync')->assertSuccessful();

        $this->assertSame(381_887_500, $generation->fresh()->charged_nanos);
    }

    // ---- Helpers -----------------------------------------------------------------------------------------

    private function userWithBalance(int $dollars): User
    {
        return User::factory()->create(['balance_nanos' => Money::fromDollars($dollars)]);
    }

    /** A generation that has been priced and held, but not yet accepted by Pixelforge. */
    private function pendingGeneration(): Generation
    {
        $user = $this->userWithBalance(5);
        $hold = 467_343_750;

        $generation = Generation::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => [],
            'estimated_min_nanos' => 300_000_000,
            'estimated_nanos' => 380_000_000,
            'estimated_max_nanos' => $hold,
            'hold_nanos' => $hold,
        ]);

        $this->assertTrue(app(CreditLedger::class)->hold($generation));

        return $generation;
    }

    private function submittedGeneration(): Generation
    {
        $generation = $this->pendingGeneration();
        $generation->forceFill(['provider_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K', 'submitted_at' => now()])->save();

        return $generation;
    }
}
