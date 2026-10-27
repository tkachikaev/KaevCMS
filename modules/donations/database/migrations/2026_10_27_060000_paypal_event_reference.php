<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_donation_paypal_webhook_events', static function (Blueprint $table): void {
            $table->string('resource_id', 190)->nullable();
            $table->timestamp('event_occurred_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('module_donation_paypal_webhook_events')->where(static function ($query): void {
            $query->whereNotNull('resource_id')->orWhereNotNull('event_occurred_at');
        })->exists()) {
            throw new \RuntimeException('Cannot discard PayPal refund/dispute identifiers.');
        }
        Schema::table('module_donation_paypal_webhook_events', static function (Blueprint $table): void {
            $table->dropColumn(['resource_id', 'event_occurred_at']);
        });
    }
};
