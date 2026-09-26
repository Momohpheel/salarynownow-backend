<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('payroll:unlink-employer {employerId : Employer user ID to unlink from its owner group}')]
#[Description('Remove an employer from its owner group (sets owner_group_user_id to null)')]
class UnlinkEmployer extends Command
{
    public function handle()
    {
        $employerId = (int) $this->argument('employerId');

        if ($employerId <= 0) {
            $this->error('Invalid employer ID.');
            return 1;
        }

        $employer = User::find($employerId);

        if (!$employer) {
            $this->error("Employer user ID {$employerId} not found.");
            return 1;
        }

        if ($employer->type !== User::TYPE_EMPLOYEE) {
            $this->error("User ID {$employerId} is not TYPE_EMPLOYEE (got: {$employer->type}).");
            return 1;
        }

        if ($employer->owner_group_user_id === null) {
            $this->warn("Employer {$employerId} (" . ($employer->company_name ?? $employer->name) . ") is already not in any owner group — nothing to do.");
            return 0;
        }

        $previousOwnerId = $employer->owner_group_user_id;
        $employer->owner_group_user_id = null;
        $employer->save();

        $this->info("SUCCESS: Employer {$employerId} (" . ($employer->company_name ?? $employer->name) . ") unlinked from owner group (previous owner_group_user_id = {$previousOwnerId}).");
        return 0;
    }
}
