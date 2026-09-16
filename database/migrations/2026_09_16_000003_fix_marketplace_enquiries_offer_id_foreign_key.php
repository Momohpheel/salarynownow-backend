<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // No-op: the bad offer_id FK and related index issues are now fully fixed
    // inside migration 000005 (safely_fix_marketplace_enquiries_identifier_too_long)
    // which handles both fresh and partially-applied DBs idempotently.
    // This file remains in-place so `php artisan migrate:status` reports the
    // correct batch ordering for already-recorded runs.
    public function up(): void { }
    public function down(): void { }
};
