<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('payroll:link-sibling-employers {--owner= : Owner employer user ID} {--subs=* : Subordinate employer user IDs (space-separated)}')]
#[Description('Link sibling employer accounts into an owner group sharing one wallet')]
class LinkSiblingEmployers extends Command
{
    public function handle()
    {
        $ownerId = $this->option('owner');
        $subIds = $this->option('subs');

        if (!$ownerId) {
            $this->error('Missing required option --owner=<user_id>');
            return 1;
        }

        $ownerId = (int) $ownerId;
        $owner = User::find($ownerId);

        if (!$owner) {
            $this->error("Owner user ID {$ownerId} not found.");
            return 1;
        }

        if ($owner->type !== User::TYPE_EMPLOYEE) {
            $this->error("Owner user ID {$ownerId} is not TYPE_EMPLOYEE (got: {$owner->type}).");
            return 1;
        }

        $allIds = array_merge([$ownerId], array_map('intval', $subIds));
        $subIds = array_values(array_filter(array_map('intval', $subIds), fn($id) => $id !== $ownerId && $id > 0));

        $subs = User::whereIn('id', $subIds)->get();
        $foundSubIds = $subs->pluck('id')->all();
        $missingSubIds = array_values(array_diff($subIds, $foundSubIds));

        if (!empty($missingSubIds)) {
            $this->error('Subordinate employer IDs not found: ' . implode(', ', $missingSubIds));
            return 1;
        }

        $wrongTypeSubs = $subs->filter(fn($u) => $u->type !== User::TYPE_EMPLOYEE);
        if ($wrongTypeSubs->isNotEmpty()) {
            $list = $wrongTypeSubs->map(fn($u) => "id={$u->id} type={$u->type}")->implode(', ');
            $this->error("Some sub IDs are not TYPE_EMPLOYEE: {$list}");
            return 1;
        }

        DB::beginTransaction();
        try {
            $owner->owner_group_user_id = $owner->id;
            $owner->save();
            $this->info("Owner {$owner->id} (" . ($owner->company_name ?? $owner->name) . ") — owner_group_user_id set to self ({$owner->id}).");

            $updatedCount = 0;
            foreach ($subs as $sub) {
                $sub->owner_group_user_id = $owner->id;
                $sub->save();
                $updatedCount++;
                $this->info("Sub   {$sub->id} (" . ($sub->company_name ?? $sub->name) . ") — owner_group_user_id set to owner {$owner->id}.");
            }

            DB::commit();
            $this->newLine();
            $this->info("SUCCESS: Owner group linked. Owner: {$owner->id}. Subs linked: {$updatedCount} (IDs: " . implode(', ', $foundSubIds) . "). Total group size: " . (1 + $updatedCount) . ".");
            return 0;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Transaction rolled back: ' . $e->getMessage());
            return 1;
        }
    }
}
