<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * All money columns are integer nanodollars (1 USD = 1_000_000_000).
 * Pixelforge prices to nine decimal places, so this is exact and never drifts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Spendable credit. Holds for in-flight generations are already deducted.
            $table->bigInteger('balance_nanos')->default(0);
        });

        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique(); // Our id: Idempotency-Key and metadata sent to Pixelforge.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider_id')->nullable()->unique(); // gen_…

            $table->string('status')->default('queued'); // queued | processing | succeeded | failed
            $table->string('status_detail')->nullable(); // Human-readable "what's happening right now".

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            // What we showed the user before submitting (user-facing price, markup included).
            $table->bigInteger('estimated_min_nanos');
            $table->bigInteger('estimated_nanos');
            $table->bigInteger('estimated_max_nanos');
            $table->bigInteger('hold_nanos'); // Reserved from the balance until the generation settles.

            // Filled in from Pixelforge.
            $table->json('rates')->nullable();
            $table->json('usage')->nullable();
            $table->bigInteger('provider_cost_nanos')->nullable(); // What Pixelforge charges us.
            $table->bigInteger('charged_nanos')->nullable();       // What we charged the user.
            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            $table->unsignedInteger('submit_attempts')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable(); // Provider's completed_at; Pixelforge bills on this UTC day.
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'last_synced_at']);
            $table->index('completed_at');
        });

        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type'); // grant | purchase | hold | hold_release | charge
            $table->bigInteger('amount_nanos'); // Signed: credits positive, debits negative.
            $table->string('description');
            $table->timestamps();

            // The database itself guarantees a generation can only be held, released and charged once.
            $table->unique(['generation_id', 'type']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique(); // evt_… — dedupe key.
            $table->string('type');
            $table->string('provider_generation_id')->nullable()->index();
            $table->foreignId('generation_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload');
            $table->unsignedInteger('delivery_attempt')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('credit_transactions');
        Schema::dropIfExists('generations');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('balance_nanos'));
    }
};
