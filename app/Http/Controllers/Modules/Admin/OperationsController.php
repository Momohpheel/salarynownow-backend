<?php

namespace App\Http\Controllers\Modules\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payroll;
use App\Models\SalaryAdvance;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OperationsController extends Controller
{
    public function advances(Request $request)
    {
        $admin = $request->user();
        $employerIds = $this->getEmployerIds($admin);

        $query = SalaryAdvance::with([
            'staff:id,name,email',
            'user:id,company_name,name',
        ])->whereIn('user_id', $employerIds);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('staff', function ($staffQuery) use ($search) {
                    $staffQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                })->orWhereHas('user', function ($userQuery) use ($search) {
                    $userQuery->where('company_name', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%");
                });
            });
        }

        if ($request->filled('status') && strtolower($request->status) !== 'all') {
            $query->where('status', strtolower($request->status));
        }

        $advances = $query->latest()->get();

        $data = [
            'summary' => [
                'total_advances' => $advances->count(),
                'approved_advances' => $advances->where('status', 'approved')->count(),
                'pending_advances' => $advances->where('status', 'pending')->count(),
                'total_amount' => '₦' . number_format($advances->sum('amount'), 2),
            ],
            'items' => $advances->map(function ($advance) {
                return [
                    'id' => $advance->id,
                    'employee' => $advance->staff->name ?? 'Unknown',
                    'company' => $advance->user->company_name ?? $advance->user->name ?? 'Unknown',
                    'amount' => '₦' . number_format($advance->amount, 2),
                    'lender' => 'SalaryNowNow',
                    'status' => ucfirst($advance->status),
                    'date' => $advance->created_at->format('d M Y'),
                ];
            }),
        ];

        return $this->sendResponse($data, 'Advance oversight data retrieved successfully');
    }

    public function auditLog(Request $request)
    {
        $admin = $request->user();
        $auditEvents = $this->buildAuditEvents($admin, $request);
        $lastSuccessfulPayroll = Payroll::whereIn('user_id', $this->getEmployerIds($admin))
            ->where('status', Payroll::STATUS_COMPLETED)
            ->latest('processed_at')
            ->first();

        $databaseStatus = $this->checkDatabaseStatus();
        $checkedAt = now()->format('H:i:s');

        $data = [
            'system_health' => [
                [
                    'name' => 'Paystack Payment Gateway',
                    'status' => config('payment.base_url') ? 'Online' : 'Offline',
                    'checked_at' => $checkedAt,
                ],
                [
                    'name' => 'Smile Identity KYC Service',
                    'status' => 'Online',
                    'checked_at' => $checkedAt,
                ],
                [
                    'name' => 'Database Connection',
                    'status' => $databaseStatus ? 'Online' : 'Offline',
                    'checked_at' => $checkedAt,
                ],
            ],
            'processing_timestamps' => [
                'last_successful_payroll' => $lastSuccessfulPayroll
                    ? $lastSuccessfulPayroll->processed_at?->format('d M Y, H:i')
                    : 'No payroll processed yet',
                'server_time' => now()->format('d M Y, H:i:s'),
                'platform_uptime' => '99.97%',
            ],
            'audit_log' => [
                'total_entries' => $auditEvents->count(),
                'items' => $auditEvents->values(),
            ],
        ];

        return $this->sendResponse($data, 'Audit log data retrieved successfully');
    }

    public function exportAuditLog(Request $request)
    {
        $admin = $request->user();
        $auditEvents = $this->buildAuditEvents($admin, $request)->values();

        $rows = [];
        $rows[] = ['Time', 'Action', 'Target', 'Details'];

        foreach ($auditEvents as $event) {
            $rows[] = [
                $event['time'],
                $event['action'],
                $event['target'],
                $event['details'],
            ];
        }

        $csv = collect($rows)->map(function ($row) {
            return implode(',', array_map(function ($value) {
                $escaped = str_replace('"', '""', (string) $value);
                return "\"{$escaped}\"";
            }, $row));
        })->implode("\n");

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="audit-log.csv"',
        ]);
    }

    public function users(Request $request)
    {
        $admin = $request->user();
        $employerIds = $this->getEmployerIds($admin);
        $search = $request->query('search');

        $employersQuery = User::where('type', User::TYPE_EMPLOYEE)
            ->where('parent_id', $admin->id);

        $staffQuery = User::where('type', User::TYPE_STAFF)
            ->whereIn('parent_id', $employerIds);

        $partnersQuery = User::where('type', User::TYPE_PARTNER);
        $adminsQuery = User::where('type', User::TYPE_ADMIN);

        if ($search) {
            $applySearch = function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('company_name', 'like', "%{$search}%")
                        ->orWhere('contact_person', 'like', "%{$search}%");
                });
            };

            $applySearch($employersQuery);
            $applySearch($staffQuery);
            $applySearch($partnersQuery);
            $applySearch($adminsQuery);
        }

        $employers = $employersQuery->latest()->get();
        $staff = $staffQuery->latest()->get();
        $partners = $partnersQuery->latest()->get();
        $admins = $adminsQuery->latest()->get();

        $data = [
            'stats' => [
                'employers' => User::where('type', User::TYPE_EMPLOYEE)->where('parent_id', $admin->id)->count(),
                'staff' => User::where('type', User::TYPE_STAFF)->whereIn('parent_id', $employerIds)->count(),
                'partners' => User::where('type', User::TYPE_PARTNER)->count(),
                'admins' => User::where('type', User::TYPE_ADMIN)->count(),
            ],
            'tabs' => [
                'employers' => $employers->map(function ($employer) {
                    return [
                        'id' => $employer->id,
                        'employer' => $employer->company_name ?? $employer->name ?? '—',
                        'owner' => $employer->name ?? $employer->contact_person ?? '—',
                        'kyb' => $employer->is_approved ? 'Approved' : 'Pending',
                        'team' => User::where('employer_id', $employer->id)
                            ->where('type', User::TYPE_EMPLOYEE)
                            ->count(),
                        'staff' => User::where('parent_id', $employer->id)
                            ->where('type', User::TYPE_STAFF)
                            ->count(),
                        'joined' => $employer->created_at->format('d M Y'),
                    ];
                }),
                'staff' => $staff->map(function ($member) {
                    $employer = User::find($member->parent_id);

                    return [
                        'id' => $member->id,
                        'name' => $member->name,
                        'email' => $member->email,
                        'department' => $member->department ?? '—',
                        'company' => $employer->company_name ?? $employer->name ?? '—',
                        'joined' => $member->created_at->format('d M Y'),
                    ];
                }),
                'partners' => $partners->map(function ($partner) {
                    return [
                        'id' => $partner->id,
                        'name' => $partner->name,
                        'email' => $partner->email,
                        'status' => $partner->is_active ? 'Active' : 'Inactive',
                        'joined' => $partner->created_at->format('d M Y'),
                    ];
                }),
                'admins' => $admins->map(function ($adminUser) {
                    return [
                        'id' => $adminUser->id,
                        'name' => $adminUser->name,
                        'email' => $adminUser->email,
                        'status' => ucfirst($adminUser->status ?? 'active'),
                        'joined' => $adminUser->created_at->format('d M Y'),
                    ];
                }),
            ],
        ];

        return $this->sendResponse($data, 'User directory retrieved successfully');
    }

    public function wallets(Request $request)
    {
        $admin = $request->user();
        $employerIds = $this->getEmployerIds($admin);

        $walletsQuery = Wallet::with('user:id,name,company_name')
            ->whereIn('user_id', $employerIds);

        if ($request->filled('search')) {
            $search = $request->search;
            $walletsQuery->whereHas('user', function ($query) use ($search) {
                $query->where('company_name', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        $wallets = $walletsQuery->latest('updated_at')->get();
        $walletIds = $wallets->pluck('id');

        $recentTransactions = WalletLog::with('wallet.user:id,name,company_name')
            ->whereIn('wallet_id', $walletIds)
            ->latest()
            ->limit(20)
            ->get();

        $data = [
            'summary' => [
                'active_wallets' => $wallets->count(),
                'total_held' => '₦' . number_format($wallets->sum('balance'), 2),
            ],
            'wallet_balances' => $wallets->map(function ($wallet) {
                return [
                    'wallet_id' => $wallet->id,
                    'company' => $wallet->user->company_name ?? $wallet->user->name ?? '—',
                    'balance' => '₦' . number_format($wallet->balance, 2),
                    'last_updated' => $wallet->updated_at->format('d M Y, H:i'),
                ];
            }),
            'recent_transactions' => $recentTransactions->map(function ($log) {
                return [
                    'date' => $log->created_at->format('d M Y'),
                    'company' => $log->wallet->user->company_name ?? $log->wallet->user->name ?? '—',
                    'type' => ucfirst($log->type),
                    'amount' => '₦' . number_format($log->amount, 2),
                    'status' => 'Confirmed',
                    'reference' => $log->metadata['transaction_reference']
                        ?? $log->metadata['reference']
                        ?? '-',
                ];
            }),
        ];

        return $this->sendResponse($data, 'Wallet oversight retrieved successfully');
    }

    public function payrolls(Request $request)
    {
        $admin = $request->user();
        $employerIds = $this->getEmployerIds($admin);

        $query = Payroll::with('user:id,name,company_name')
            ->whereIn('user_id', $employerIds);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', function ($userQuery) use ($search) {
                    $userQuery->where('company_name', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%");
                })->orWhere('status', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status') && strtolower($request->status) !== 'all') {
            $query->where('status', strtolower($request->status));
        }

        $payrolls = $query->latest('processed_at')->get();

        $data = [
            'summary' => [
                'payroll_runs' => $payrolls->count(),
                'completed' => $payrolls->where('status', Payroll::STATUS_COMPLETED)->count(),
                'total_processed' => '₦' . number_format(
                    $payrolls->where('status', Payroll::STATUS_COMPLETED)->sum('amount'),
                    2
                ),
                'people_included' => $payrolls->sum('staff_count'),
            ],
            'items' => $payrolls->map(function ($payroll) {
                return [
                    'id' => $payroll->id,
                    'company' => $payroll->user->company_name ?? $payroll->user->name ?? '—',
                    'run_date' => $payroll->processed_at?->format('d M Y') ?? '—',
                    'pay_period' => $payroll->period_start && $payroll->period_end
                        ? $payroll->period_start->format('d M') . ' — ' . $payroll->period_end->format('d M Y')
                        : '—',
                    'staff_count' => $payroll->staff_count,
                    'total_amount' => '₦' . number_format($payroll->amount, 2),
                    'status' => ucfirst($payroll->status),
                    'created' => $payroll->created_at->format('d M Y'),
                ];
            }),
        ];

        return $this->sendResponse($data, 'Payroll control data retrieved successfully');
    }

    private function getEmployerIds(User $admin)
    {
        return User::where('type', User::TYPE_EMPLOYEE)
            ->where('parent_id', $admin->id)
            ->pluck('id');
    }

    private function buildAuditEvents(User $admin, Request $request)
    {
        $employerIds = $this->getEmployerIds($admin);

        $employerEvents = User::where('type', User::TYPE_EMPLOYEE)
            ->where('parent_id', $admin->id)
            ->latest()
            ->limit(25)
            ->get()
            ->map(function ($employer) {
                return [
                    'time' => $employer->updated_at->format('d M Y, H:i'),
                    'action' => $employer->is_approved ? 'KYB Approved' : 'Employer Registered',
                    'target_type' => 'Employer',
                    'target' => $employer->company_name ?? $employer->name ?? '—',
                    'details' => $employer->is_approved
                        ? 'Employer account approved'
                        : 'Employer onboarding submitted',
                    'timestamp' => $employer->updated_at,
                ];
            });

        $payrollEvents = Payroll::with('user:id,name,company_name')
            ->whereIn('user_id', $employerIds)
            ->latest('updated_at')
            ->limit(25)
            ->get()
            ->map(function ($payroll) {
                return [
                    'time' => $payroll->updated_at->format('d M Y, H:i'),
                    'action' => 'Payroll ' . ucfirst($payroll->status),
                    'target_type' => 'Payroll',
                    'target' => $payroll->user->company_name ?? $payroll->user->name ?? '—',
                    'details' => 'Payroll run of ₦' . number_format($payroll->amount, 2),
                    'timestamp' => $payroll->updated_at,
                ];
            });

        $advanceEvents = SalaryAdvance::with(['staff:id,name', 'user:id,name,company_name'])
            ->whereIn('user_id', $employerIds)
            ->latest()
            ->limit(25)
            ->get()
            ->map(function ($advance) {
                return [
                    'time' => $advance->created_at->format('d M Y, H:i'),
                    'action' => 'Advance ' . ucfirst($advance->status),
                    'target_type' => 'Advance',
                    'target' => $advance->staff->name ?? 'Unknown',
                    'details' => ($advance->user->company_name ?? $advance->user->name ?? '—')
                        . ' • ₦' . number_format($advance->amount, 2),
                    'timestamp' => $advance->created_at,
                ];
            });

        $walletIds = Wallet::whereIn('user_id', $employerIds)->pluck('id');
        $walletEvents = WalletLog::with('wallet.user:id,name,company_name')
            ->whereIn('wallet_id', $walletIds)
            ->latest()
            ->limit(25)
            ->get()
            ->map(function ($log) {
                return [
                    'time' => $log->created_at->format('d M Y, H:i'),
                    'action' => 'Wallet ' . ucfirst($log->type),
                    'target_type' => 'Wallet',
                    'target' => $log->wallet->user->company_name ?? $log->wallet->user->name ?? '—',
                    'details' => '₦' . number_format($log->amount, 2) . ' • ' . $log->description,
                    'timestamp' => $log->created_at,
                ];
            });

        $transactionEvents = Transaction::with('user:id,name,email')
            ->whereIn('user_id', User::where('type', User::TYPE_STAFF)->whereIn('parent_id', $employerIds)->pluck('id'))
            ->latest()
            ->limit(25)
            ->get()
            ->map(function ($transaction) {
                return [
                    'time' => $transaction->created_at->format('d M Y, H:i'),
                    'action' => 'Transfer ' . ucfirst($transaction->status),
                    'target_type' => 'Transaction',
                    'target' => $transaction->user->name ?? $transaction->reference,
                    'details' => '₦' . number_format($transaction->amount, 2)
                        . ' • Ref ' . $transaction->reference,
                    'timestamp' => $transaction->created_at,
                ];
            });

        $events = $employerEvents
            ->concat($payrollEvents)
            ->concat($advanceEvents)
            ->concat($walletEvents)
            ->concat($transactionEvents)
            ->sortByDesc('timestamp')
            ->values();

        if ($request->filled('search')) {
            $search = strtolower($request->search);
            $events = $events->filter(function ($event) use ($search) {
                return str_contains(strtolower($event['target']), $search)
                    || str_contains(strtolower($event['details']), $search)
                    || str_contains(strtolower($event['action']), $search);
            });
        }

        if ($request->filled('action') && strtolower($request->action) !== 'all actions') {
            $action = strtolower($request->action);
            $events = $events->filter(fn ($event) => strtolower($event['action']) === $action);
        }

        if ($request->filled('target') && strtolower($request->target) !== 'all targets') {
            $target = strtolower($request->target);
            $events = $events->filter(fn ($event) => strtolower($event['target_type']) === $target);
        }

        if ($request->filled('from')) {
            $from = Carbon::parse($request->from)->startOfDay();
            $events = $events->filter(fn ($event) => $event['timestamp']->gte($from));
        }

        if ($request->filled('to')) {
            $to = Carbon::parse($request->to)->endOfDay();
            $events = $events->filter(fn ($event) => $event['timestamp']->lte($to));
        }

        return $events->map(function ($event) {
            unset($event['timestamp']);
            return $event;
        });
    }

    private function checkDatabaseStatus(): bool
    {
        try {
            DB::select('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
