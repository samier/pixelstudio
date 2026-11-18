<?php

namespace Database\Seeders;

use App\Models\CreditTransaction;
use App\Models\User;
use App\Services\Billing\CreditLedger;
use App\Support\Money;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Local development only: a demo account (password "password", the factory default) with $5 of credit.
     */
    public function run(): void
    {
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        app(CreditLedger::class)->credit($user, CreditTransaction::GRANT, Money::fromDollars(5), 'Demo credit');
    }
}
