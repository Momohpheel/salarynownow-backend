<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\Transaction;
use App\Services\Sarepay\SarepayService;
use Illuminate\Support\Str;

#[Signature('app:process-payroll')]
#[Description('Process and disburse salaries for scheduled payrolls')]
class ProcessPayroll extends Command
{
    protected $sarepayService;

    public function __construct(SarepayService $sarepayService)
    {
        parent::__construct();
        $this->sarepayService = $sarepayService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking for payrolls to disburse...');

        $payrolls = Payroll::whereIn('status', [
                Payroll::STATUS_PENDING,
                Payroll::STATUS_PROCESSING,
            ])
            ->whereDate('processed_at', '<=', now())
            ->get();

        if ($payrolls->isEmpty()) {
            $this->info('No pending or processing payrolls for today.');
            return;
        }

        foreach ($payrolls as $payroll) {
            $this->info("Processing payroll ID: {$payroll->id} for employer: {$payroll->user->name}");
            
            $payroll->update(['status' => Payroll::STATUS_PROCESSING]);

            $payslips = $payroll->payslips()
                ->where('status', Payslip::STATUS_PROCESSING)
                ->get();

            foreach ($payslips as $payslip) {
                $staff = $payslip->user;
                $reference = 'SAL-' . Str::upper(Str::random(10));

                $this->info("Initiating transfer of ₦" . number_format($payslip->net_salary, 2) . " to {$staff->name} ({$staff->account_number})");

                try {
                    $response = $this->sarepayService->transfer(
                        $reference,
                        $staff->account_number,
                        $staff->bank_code ?? '000', // Default if missing, but should be there
                        $payslip->net_salary,
                        "Salary for {$payroll->description}"
                    );

                    // Create transaction record
                    Transaction::create([
                        'user_id' => $staff->id,
                        'payroll_id' => $payroll->id,
                        'payslip_id' => $payslip->id,
                        'reference' => $reference,
                        'amount' => $payslip->net_salary,
                        'status' => Transaction::STATUS_PROCESSING,
                        'metadata' => (array) $response,
                    ]);

                } catch (\Exception $e) {
                    $this->error("Failed to initiate transfer for {$staff->name}: " . $e->getMessage());
                    
                    Transaction::create([
                        'user_id' => $staff->id,
                        'payroll_id' => $payroll->id,
                        'payslip_id' => $payslip->id,
                        'reference' => $reference,
                        'amount' => $payslip->net_salary,
                        'status' => Transaction::STATUS_FAILED,
                        'response_message' => $e->getMessage(),
                    ]);
                }
            }

            $this->info("Payroll ID: {$payroll->id} processing complete.");
        }
    }
}
