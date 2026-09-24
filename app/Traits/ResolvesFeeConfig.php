<?php

namespace App\Traits;

use App\Models\FeeConfig;
use App\Models\User;

trait ResolvesFeeConfig
{
    public function resolveFee(User $walletOwner, string $event): array
    {
        if ($walletOwner->type === User::TYPE_EMPLOYEE) {
            $override = FeeConfig::active()
                ->forEvent($event)
                ->forScope(FeeConfig::SCOPE_EMPLOYER, $walletOwner->id)
                ->first();

            if ($override) {
                return $this->buildResolutionResult($override);
            }

            $merchantId = $walletOwner->parent_id;

            if ($merchantId) {
                $merchantDefault = FeeConfig::active()
                    ->forEvent($event)
                    ->forScope(FeeConfig::SCOPE_MERCHANT, $merchantId)
                    ->first();

                if ($merchantDefault) {
                    return $this->buildResolutionResult($merchantDefault);
                }
            }

            $platformDefault = FeeConfig::active()
                ->forEvent($event)
                ->forScope(FeeConfig::SCOPE_PLATFORM)
                ->first();

            if ($platformDefault) {
                return $this->buildResolutionResult($platformDefault);
            }

            return [
                'fee' => null,
                'amount' => 0.0,
                'breakdown' => 'No matching FeeConfig row — fee defaults to 0.',
                'scope_label' => 'none',
                'calculation_label' => 'none',
            ];
        }

        if ($walletOwner->type === User::TYPE_PARTNER) {
            $partnerOverride = FeeConfig::active()
                ->forEvent($event)
                ->forScope(FeeConfig::SCOPE_PARTNER, $walletOwner->id)
                ->first();

            if ($partnerOverride) {
                return $this->buildResolutionResult($partnerOverride);
            }

            $platformDefault = FeeConfig::active()
                ->forEvent($event)
                ->forScope(FeeConfig::SCOPE_PLATFORM)
                ->first();

            if ($platformDefault) {
                return $this->buildResolutionResult($platformDefault);
            }

            return [
                'fee' => null,
                'amount' => 0.0,
                'breakdown' => 'No matching FeeConfig row — fee defaults to 0.',
                'scope_label' => 'none',
                'calculation_label' => 'none',
            ];
        }

        return [
            'fee' => null,
            'amount' => 0.0,
            'breakdown' => sprintf(
                'User type "%s" has no fee resolution rules — fee defaults to 0.',
                $walletOwner->type ?? 'null'
            ),
            'scope_label' => 'none',
            'calculation_label' => 'none',
        ];
    }

    public function resolveAndComputeFee(User $walletOwner, string $event, float $baseAmount): array
    {
        $resolution = $this->resolveFee($walletOwner, $event);
        $feeAmount = 0.0;

        if ($resolution['fee'] instanceof FeeConfig) {
            $feeAmount = $this->computeFee($baseAmount, $resolution['fee']);
        }

        $resolution['amount'] = $feeAmount;

        if ($resolution['fee'] instanceof FeeConfig) {
            $resolution['breakdown'] = sprintf(
                'Resolved via scope_type=%s scope_id=%s event=%s calculation_type=%s value=%s cap_amount=%s — computed fee=%.2f on base=%.2f',
                $resolution['fee']->scope_type,
                $resolution['fee']->scope_id ?? 'null',
                $resolution['fee']->event,
                $resolution['fee']->calculation_type,
                $resolution['fee']->value,
                $resolution['fee']->cap_amount ?? 'null',
                $feeAmount,
                $baseAmount
            );
        }

        return $resolution;
    }

    public function computeFee(float $amount, FeeConfig $fee): float
    {
        if ($fee->calculation_type === FeeConfig::CALC_FLAT) {
            $computed = (float) $fee->value;
        } elseif ($fee->calculation_type === FeeConfig::CALC_PERCENTAGE) {
            $computed = (((float) $amount) * ((float) $fee->value)) / 100.0;
            if ($fee->cap_amount !== null) {
                $cap = (float) $fee->cap_amount;
                if ($computed > $cap) {
                    $computed = $cap;
                }
            }
        } else {
            $computed = 0.0;
        }

        return $this->roundMoney($computed);
    }

    private function roundMoney(float $value): float
    {
        return round($value, 2, PHP_ROUND_HALF_UP);
    }

    private function buildResolutionResult(FeeConfig $fee): array
    {
        return [
            'fee' => $fee,
            'amount' => 0.0,
            'breakdown' => sprintf(
                'Resolved via scope_type=%s scope_id=%s event=%s calculation_type=%s value=%s cap_amount=%s',
                $fee->scope_type,
                $fee->scope_id ?? 'null',
                $fee->event,
                $fee->calculation_type,
                $fee->value,
                $fee->cap_amount ?? 'null'
            ),
            'scope_label' => method_exists($fee, 'labelForScope')
                ? $fee->labelForScope()
                : ($fee->scope_label ?? 'unknown'),
            'calculation_label' => method_exists($fee, 'labelForCalculation')
                ? $fee->labelForCalculation()
                : ($fee->calculation_label ?? 'unknown'),
        ];
    }
}
