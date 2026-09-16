<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // No-op: superseded by the comprehensive idempotent fix in migration
    // 000005 (safely_fix_marketplace_enquiries_identifier_too_long). Kept
    // so migration batch history remains consistent for any recorded entries.
    public function up(): void { }
    public function down(): void { }
};
