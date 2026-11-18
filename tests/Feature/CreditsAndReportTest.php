<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

class CreditsAndReportTest extends TestCase
{
    use PixelforgeFixtures, RefreshDatabase;

    private const BASE = 'https://pixelforge.test/v1';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.pixelforge.base_url' => self::BASE, 'billing.admin_emails' => ['ops@example.com']]);
        $this->app->forgetInstance(PixelforgeClient::class);
    }

    public function test_new_accounts_get_the_welcome_credit(): void
    {
        $this->post('/register', [
            'name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'password', 'password_confirmation' => 'password',
        ])->assertSessionHasNoErrors()->assertRedirect(route('dashboard', absolute: false));

        $this->assertSame(Money::fromDollars(1), User::sole()->balance_nanos);
    }

    public function test_buying_credits_adds_to_the_balance_and_the_ledger(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/credits', ['amount' => 10])->assertSessionHasNoErrors();

        $this->assertSame(Money::fromDollars(10), $user->fresh()->balance_nanos);
        $this->assertSame(1, $user->creditTransactions()->where('type', 'purchase')->count());
    }

    public function test_a_custom_amount_is_added_exactly_in_cents(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/credits', ['amount' => '12.10'])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Added $12.10 to your balance.');
        $this->actingAs($user)->post('/credits', ['amount' => '7'])->assertSessionHasNoErrors();

        $this->assertSame(19_100_000_000, $user->fresh()->balance_nanos);
        $this->assertSame('Bought $12.10 of credits', $user->creditTransactions()->oldest('id')->value('description'));
    }

    public function test_invalid_custom_amounts_are_rejected(): void
    {
        $user = User::factory()->create();

        foreach (['', 'abc', '-5', '0.50', '10.999', '1e3', '500.01', '1000'] as $amount) {
            $this->actingAs($user)->post('/credits', ['amount' => $amount])->assertSessionHasErrors('amount');
        }
        $this->actingAs($user)->post('/credits', ['amount' => '500'])->assertSessionHasNoErrors(); // the maximum itself is allowed

        $this->assertSame(Money::fromDollars(500), $user->fresh()->balance_nanos);
        $this->assertSame(1, $user->creditTransactions()->count());
    }

    public function test_generate_history_and_credits_pages_render(): void
    {
        Http::fake([self::BASE.'/pricing' => Http::response($this->pricingPayload())]);
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->component('Generate')->where('pricing.rates.output_tokens', '40.00')->where('markupPercent', 25));
        $this->actingAs($user)->get('/generations')->assertInertia(fn (Assert $page) => $page->component('History'));
        $this->actingAs($user)->get('/credits')->assertInertia(fn (Assert $page) => $page->component('Credits'));
    }

    public function test_the_report_is_for_operators_only(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/report')->assertForbidden();
    }

    public function test_the_report_totals_spend_cost_and_profit_and_reconciles_with_pixelforge_usage(): void
    {
        $admin = User::factory()->create(['email' => 'ops@example.com']);
        $customer = User::factory()->create();
        $day = now('UTC')->subDay();

        $this->succeeded($customer, 305_510_000, $day->copy()->setTime(10, 0));
        $this->succeeded($customer, 46_125_000, $day->copy()->setTime(23, 59, 59));
        $this->succeeded($customer, 1_000, $day->copy()->addDay()->setTime(0, 0, 1)); // next UTC day

        Http::fake([self::BASE.'/usage*' => Http::response(['object' => 'usage', 'data' => [
            ['date' => $day->toDateString(), 'generations' => 2, 'cost_usd' => '0.351635000'],
            ['date' => $day->copy()->addDay()->toDateString(), 'generations' => 1, 'cost_usd' => '0.000001000'],
        ]])]);

        $this->actingAs($admin)->get('/admin/report')->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Report')
            ->where('total.cost', 351_636_000)
            ->where('total.charged', Money::withMarkup(305_510_000, 25) + Money::withMarkup(46_125_000, 25) + Money::withMarkup(1_000, 25))
            ->where('total.matches', true)
            ->where('users.0.email', $customer->email)
            ->where('users.0.generations', 3)
            ->where('days.1.date', $day->toDateString())
            ->where('days.1.cost', 351_635_000)
            ->where('days.1.matches', true));
    }

    private function succeeded(User $user, int $cost, $completedAt): void
    {
        Generation::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'provider_id' => 'gen_'.Str::random(10),
            'status' => 'succeeded',
            'prompt' => 'x', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => [],
            'estimated_min_nanos' => 0, 'estimated_nanos' => 0, 'estimated_max_nanos' => 0, 'hold_nanos' => 0,
            'provider_cost_nanos' => $cost,
            'charged_nanos' => Money::withMarkup($cost, 25),
            'completed_at' => $completedAt,
        ]);
    }
}
