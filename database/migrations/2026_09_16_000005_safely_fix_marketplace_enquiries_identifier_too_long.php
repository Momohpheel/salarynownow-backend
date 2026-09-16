<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $dropIndexCandidates = [
        // Long auto-generated names that caused MySQL 1059 (>64 char identifier)
        'marketplace_enquiries_partner_id_status_created_at_index',
        'marketplace_enquiries_submitter_type_submitter_id_created_at_index',
        'marketplace_enquiries_offer_id_index',
        // Short names from edited migrations (in case any partially applied)
        'mkpq_partner_status_created_at',
        'mkpq_submitter_created_at',
        'mkpq_offer_id',
    ];

    private function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

    private function indexExists(string $table, string $keyName): bool
    {
        try {
            $rows = DB::select(
                DB::raw('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ?'),
                [$keyName]
            );
            return count($rows) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function foreignKeyExists(string $table, string $fkName): bool
    {
        try {
            $rows = DB::select(DB::raw(
                "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND CONSTRAINT_NAME = ?
                   AND CONSTRAINT_TYPE = 'FOREIGN KEY'
                 LIMIT 1"
            ), [$table, $fkName]);
            return count($rows) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function dropForeignCandidates(string $table, string $columnPrefix, string $refTable, array $colNames): void
    {
        $tried = [];
        foreach ($colNames as $col) {
            $candidates = [
                "{$table}_{$col}_foreign",
                "{$table}_{$columnPrefix}_{$col}_foreign",
                "{$table}_ibfk",
            ];
            foreach ($candidates as $c) {
                if (in_array($c, $tried, true)) continue;
                $tried[] = $c;
                if ($this->foreignKeyExists($table, $c)) {
                    try { DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$c}`"); }
                    catch (\Throwable) {}
                }
            }
        }
        // Try a manual list-table sweep for anything matching _foreign on related columns
        try {
            $all = DB::select(DB::raw(
                "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
            ), [$table]);
            foreach ($all as $row) {
                $name = $row->CONSTRAINT_NAME;
                foreach ($colNames as $col) {
                    if (str_contains($name, $col) && !in_array($name, $tried, true)) {
                        $tried[] = $name;
                        try { DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$name}`"); }
                        catch (\Throwable) {}
                    }
                }
            }
        } catch (\Throwable) {}
    }

    public function up(): void
    {
        if (!$this->tableExists('marketplace_enquiries')) {
            // Fresh environment: table will be created by 000001 with short names if it hasn't run yet;
            // if 000001 was already fixed in the repo, we're done here.
            return;
        }

        // STEP 1 — Drop all long/short indexes (idempotent)
        foreach ($this->dropIndexCandidates as $idx) {
            if ($this->indexExists('marketplace_enquiries', $idx)) {
                try {
                    DB::statement("ALTER TABLE marketplace_enquiries DROP INDEX `{$idx}`");
                } catch (\Throwable) {
                }
            }
        }

        // STEP 2 — Drop offer_id FK to non-existent `offers` table (original 000001 used foreignId, no such table)
        //         Then ensure offer_id column is nullable unsigned bigint
        $this->dropForeignCandidates(
            'marketplace_enquiries',
            'marketplace_enquiries',
            'offers',
            ['offer_id']
        );

        try {
            // Schema\Builder change() requires doctrine/dbal for some drivers; use raw ALTER.
            DB::statement(
                "ALTER TABLE marketplace_enquiries
                 MODIFY COLUMN `offer_id` BIGINT UNSIGNED NULL"
            );
        } catch (\Throwable) {
        }

        // STEP 3 — Recreate 3 short indexes (names ≤ 30 chars, well under MySQL 64 limit)
        $short = [
            'mkpq_partner_status_created_at' => ['partner_id', 'status', 'created_at'],
            'mkpq_submitter_created_at'      => ['submitter_type', 'submitter_id', 'created_at'],
            'mkpq_offer_id'                  => ['offer_id'],
        ];
        foreach ($short as $name => $cols) {
            if ($this->indexExists('marketplace_enquiries', $name)) {
                continue;
            }
            $colSql = implode(', ', array_map(fn ($c) => "`{$c}`", $cols));
            try {
                DB::statement("ALTER TABLE marketplace_enquiries ADD INDEX `{$name}` ({$colSql})");
            } catch (\Throwable $e) {
                // Ignore strictly duplicate-key errors, re-raise anything else
                if (!str_contains($e->getMessage(), 'Duplicate key name')
                    && !str_contains($e->getMessage(), 'already exists')) {
                    throw $e;
                }
            }
        }
    }

    public function down(): void
    {
        if (!$this->tableExists('marketplace_enquiries')) {
            return;
        }
        foreach ([
            'mkpq_partner_status_created_at',
            'mkpq_submitter_created_at',
            'mkpq_offer_id',
        ] as $idx) {
            if ($this->indexExists('marketplace_enquiries', $idx)) {
                try {
                    DB::statement("ALTER TABLE marketplace_enquiries DROP INDEX `{$idx}`");
                } catch (\Throwable) {
                }
            }
        }
    }
};
