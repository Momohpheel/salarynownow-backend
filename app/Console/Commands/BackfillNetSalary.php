<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('users:backfill-net-salary {--force : Apply the backfill (default is a dry run)} {--include-zero : Also treat net_salary = 0 as empty}')]
#[Description('Copy the salary column into net_salary for rows where net_salary is empty')]
class BackfillNetSalary extends Command
{
    public function handle()
    {
        $apply = (bool) $this->option('force');
        $includeZero = (bool) $this->option('include-zero');

        $query = User::query()
            ->whereNotNull('salary')
            ->where(function ($q) use ($includeZero) {
                $q->whereNull('net_salary');
                if ($includeZero) {
                    $q->orWhere('net_salary', 0);
                }
            });

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info('Nothing to backfill — every row with a salary already has a net_salary.');
            return 0;
        }

        $this->table(
            ['ID', 'Email', 'Salary', 'Net salary (now)'],
            (clone $query)->limit(20)->get()->map(fn ($u) => [
                $u->id,
                $u->email,
                $u->salary,
                $u->net_salary,
            ])->all()
        );

        if (!$apply) {
            $this->newLine();
            $this->warn("DRY RUN — {$count} row(s) would be updated. Re-run with --force to apply.");
            return 0;
        }

        DB::beginTransaction();
        try {
            $updated = $query->update(['net_salary' => DB::raw('salary')]);
            DB::commit();
            $this->info("SUCCESS: Backfilled net_salary for {$updated} row(s).");
            return 0;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Transaction rolled back: ' . $e->getMessage());
            return 1;
        }
    }
}
