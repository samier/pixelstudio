<?php

namespace App\Http\Controllers;

use App\Models\CreditTransaction;
use App\Services\Billing\CreditLedger;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CreditsController extends Controller
{
    public function index(Request $request): Response
    {
        $transactions = $request->user()->creditTransactions()
            ->with('generation:id,uuid,prompt')
            ->latest('id')
            ->paginate(25)
            ->through(fn (CreditTransaction $t) => [
                'id' => $t->id,
                'type' => $t->type,
                'amount_nanos' => $t->amount_nanos,
                'description' => $t->description,
                'prompt' => $t->generation?->prompt,
                'created_at' => $t->created_at->toIso8601String(),
            ]);

        return Inertia::render('Credits', [
            'options' => config('billing.purchase_options_usd'),
            'customMin' => config('billing.custom_purchase_min_usd'),
            'customMax' => config('billing.custom_purchase_max_usd'),
            'transactions' => $transactions,
            'reservedNanos' => (int) $request->user()->generations()
                ->whereIn('status', ['queued', 'processing'])->sum('hold_nanos'),
        ]);
    }

    /**
     * Fake checkout: adds the amount straight to the balance. The preset buttons and the custom field
     * both post here; any amount in whole cents within the configured limits is accepted.
     */
    public function store(Request $request, CreditLedger $ledger): RedirectResponse
    {
        $min = config('billing.custom_purchase_min_usd');
        $max = config('billing.custom_purchase_max_usd');

        $validated = $request->validate([
            'amount' => ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/', 'numeric', "min:{$min}", "max:{$max}"],
        ], [
            'amount.regex' => 'Enter an amount in dollars with at most two decimals, e.g. 12.50.',
            'amount.min' => "The minimum purchase is \${$min}.",
            'amount.max' => "The maximum purchase is \${$max}.",
        ]);

        // Parsed from the exact decimal string, so $12.10 is exactly 12_100_000_000 nanos (no float rounding).
        $nanos = Money::parse((string) $validated['amount']);
        $label = '$'.Money::display($nanos, 2);

        $ledger->credit($request->user(), CreditTransaction::PURCHASE, $nanos, "Bought {$label} of credits");

        return back()->with('success', "Added {$label} to your balance.");
    }
}
