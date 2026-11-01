<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('payroll:repair-team-member-staff {--force : Apply the repair (default is a dry run)}')]
#[Description('Re-anchor staff rows that were attached to team members back to their business owner')]
class RepairTeamMemberStaff extends Command
{
    public function handle()
    {
        $apply = (bool) $this->option('force');

        // A "team member" is a TYPE_EMPLOYEE whose employer_id points at another user.
        // Staff should never hang off these — only off a real business.
        $teamMemberIds = User::query()
            ->where('type', User::TYPE_EMPLOYEE)
            ->whereNotNull('employer_id')
            ->whereColumn('employer_id', '!=', 'id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (empty($teamMemberIds)) {
            $this->info('No team members found — nothing to repair.');
            return 0;
        }

        $staff = User::query()
            ->where('type', User::TYPE_STAFF)
            ->whereIn('parent_id', $teamMemberIds)
            ->get();

        if ($staff->isEmpty()) {
            $this->info('No staff rows are attached to team members — nothing to repair.');
            return 0;
        }

        $teamMembers = User::query()->whereIn('id', $teamMemberIds)->get()->keyBy('id');

        $planned = [];
        foreach ($staff as $member) {
            $teamMember = $teamMembers->get((int) $member->parent_id);
            if (!$teamMember) {
                continue;
            }

            $target = User::find((int) $teamMember->employer_id);

            // Only re-anchor onto a real business (employee with no employer of its own).
            if (!$target || $target->type !== User::TYPE_EMPLOYEE || $target->employer_id) {
                $this->warn("SKIP staff #{$member->id} ({$member->email}): could not resolve a real business from team member #{$teamMember->id}.");
                continue;
            }

            $planned[] = [$member, $target];
        }

        if (empty($planned)) {
            $this->info('Nothing to repair after validation.');
            return 0;
        }

        $this->table(
            ['Staff ID', 'Staff Email', 'From (team member)', 'To (business)'],
            array_map(fn ($row) => [
                $row[0]->id,
                $row[0]->email,
                (int) $row[0]->parent_id,
                $row[1]->id . ' (' . ($row[1]->company_name ?? $row[1]->name) . ')',
            ], $planned)
        );

        if (!$apply) {
            $this->newLine();
            $this->warn('DRY RUN — no changes made. Re-run with --force to apply the ' . count($planned) . ' change(s).');
            return 0;
        }

        DB::beginTransaction();
        try {
            $count = 0;
            foreach ($planned as [$member, $target]) {
                $member->parent_id = $target->id;
                $member->save();
                $count++;
            }
            DB::commit();
            $this->info("SUCCESS: Re-anchored {$count} staff row(s) to their business owner.");
            return 0;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Transaction rolled back: ' . $e->getMessage());
            return 1;
        }
    }
}
