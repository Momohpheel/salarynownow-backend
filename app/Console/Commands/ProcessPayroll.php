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
        $bankCodeLookup = $this->buildBankCodeLookup();

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
            $employerWallet = $payroll->user->wallet;
            $availableBalance = (float) ($employerWallet?->balance ?? 0);

            $payslips = Payslip::where('payroll_id', $payroll->id)
                ->get();
            //$payroll->payslips()
               // ->where('status', Payslip::STATUS_PROCESSING)
               // ->get();

            foreach ($payslips as $payslip) {
                $staff = $payslip->user;
                $reference = 'SAL-' . Str::upper(Str::random(10));

                $this->info("Initiating transfer of ₦" . number_format($payslip->net_salary, 2) . " to {$staff->name} ({$staff->account_number})");

                try {
                    if (! $employerWallet) {
                        throw new \Exception("Employer wallet not found.");
                    }

                    if ($availableBalance < (float) $payslip->net_salary) {
                        throw new \Exception("Insufficient employer wallet balance for this transaction.");
                    }

                    $bankCode = $this->resolveBankCode($staff->bank_name, $bankCodeLookup);

                    if (! $bankCode) {
                        throw new \Exception("Bank code not found for {$staff->bank_name}.");
                    }

                    $response = $this->sarepayService->transfer(
                        $reference,
                        $staff->account_number,
                        $bankCode,
                        $payslip->net_salary,
                        "Salary for {$payroll->description}"
                    );

                    $availableBalance -= (float) $payslip->net_salary;

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

    private function buildBankCodeLookup(): array
    {
        $lookup = [];

        try {
            $banks = $this->sarepayService->getBanks();

            foreach ($banks as $bank) {
                $name = is_array($bank)
                    ? ($bank['name'] ?? $bank['bank_name'] ?? null)
                    : ($bank->name ?? $bank->bank_name ?? null);

                $code = is_array($bank)
                    ? ($bank['code'] ?? $bank['bank_code'] ?? null)
                    : ($bank->code ?? $bank->bank_code ?? null);

                if ($name && $code) {
                    $lookup[$this->normalizeBankName($name)] = $code;
                }
            }
        } catch (\Exception $e) {
            $this->error('Failed to fetch bank list: ' . $e->getMessage());
        }

        return $lookup;
    }

    private function resolveBankCode(?string $bankName, array $bankCodeLookup): ?string
    {
        if (! $bankName) {
            return null;
        }

        return $bankCodeLookup[$this->normalizeBankName($bankName)] ?? null;
    }

    private function normalizeBankName(string $bankName): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $bankName)));
    }
}
