<?php

namespace App\Services;

use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\OptimisticLockException;
use App\Models\Asset;
use App\Models\BalanceLedger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BalanceService
{
    /**
     * Lock USD funds for a buy order.
     *
     * @throws InsufficientBalanceException
     * @throws OptimisticLockException
     */
    public function lockUsdFunds(User $user, string $amount, int $referenceId, string $description = null): void
    {
        DB::transaction(function () use ($user, $amount, $referenceId, $description) {
            // Reload user with lock
            $lockedUser = User::where('id', $user->id)
                ->where('version', $user->version)
                ->lockForUpdate()
                ->first();

            if (!$lockedUser) {
                throw new OptimisticLockException();
            }

            // Check sufficient balance
            if (bccomp($lockedUser->balance, $amount, 8) < 0) {
                throw new InsufficientBalanceException(
                    'Insufficient USD balance',
                    'USD',
                    $amount,
                    $lockedUser->balance
                );
            }

            $balanceBefore = $lockedUser->balance;
            $lockedBefore = $lockedUser->locked_balance;

            // Deduct from available, add to locked
            $lockedUser->balance = bcsub($lockedUser->balance, $amount, 8);
            $lockedUser->locked_balance = bcadd($lockedUser->locked_balance, $amount, 8);
            $lockedUser->version++;
            $lockedUser->save();

            // Record ledger entry
            $this->recordLedgerEntry(
                $lockedUser->id,
                'USD',
                bcmul($amount, '-1', 8), // Negative for debit from available
                $balanceBefore,
                $lockedUser->balance,
                $amount, // Positive for addition to locked
                $lockedBefore,
                $lockedUser->locked_balance,
                BalanceLedger::TYPE_ORDER_LOCK,
                $referenceId,
                $description ?? 'Lock funds for buy order'
            );

            // Update the original user instance
            $user->balance = $lockedUser->balance;
            $user->locked_balance = $lockedUser->locked_balance;
            $user->version = $lockedUser->version;
        });
    }

    /**
     * Unlock USD funds when a buy order is cancelled.
     *
     * @throws OptimisticLockException
     */
    public function unlockUsdFunds(User $user, string $amount, int $referenceId, string $description = null): void
    {
        DB::transaction(function () use ($user, $amount, $referenceId, $description) {
            $lockedUser = User::where('id', $user->id)
                ->where('version', $user->version)
                ->lockForUpdate()
                ->first();

            if (!$lockedUser) {
                throw new OptimisticLockException();
            }

            $balanceBefore = $lockedUser->balance;
            $lockedBefore = $lockedUser->locked_balance;

            // Add back to available, deduct from locked
            $lockedUser->balance = bcadd($lockedUser->balance, $amount, 8);
            $lockedUser->locked_balance = bcsub($lockedUser->locked_balance, $amount, 8);
            $lockedUser->version++;
            $lockedUser->save();

            // Record ledger entry
            $this->recordLedgerEntry(
                $lockedUser->id,
                'USD',
                $amount, // Positive for credit to available
                $balanceBefore,
                $lockedUser->balance,
                bcmul($amount, '-1', 8), // Negative for deduction from locked
                $lockedBefore,
                $lockedUser->locked_balance,
                BalanceLedger::TYPE_ORDER_UNLOCK,
                $referenceId,
                $description ?? 'Unlock funds for cancelled buy order'
            );

            $user->balance = $lockedUser->balance;
            $user->locked_balance = $lockedUser->locked_balance;
            $user->version = $lockedUser->version;
        });
    }

    /**
     * Lock asset for a sell order.
     *
     * @throws InsufficientBalanceException
     * @throws OptimisticLockException
     */
    public function lockAsset(User $user, string $symbol, string $amount, int $referenceId, string $description = null): void
    {
        DB::transaction(function () use ($user, $symbol, $amount, $referenceId, $description) {
            // Get or create asset with lock
            $asset = Asset::where('user_id', $user->id)
                ->where('symbol', $symbol)
                ->lockForUpdate()
                ->first();

            if (!$asset || bccomp($asset->amount, $amount, 8) < 0) {
                throw new InsufficientBalanceException(
                    "Insufficient {$symbol} balance",
                    $symbol,
                    $amount,
                    $asset?->amount ?? '0'
                );
            }

            $balanceBefore = $asset->amount;
            $lockedBefore = $asset->locked_amount;

            // Deduct from available, add to locked
            $asset->amount = bcsub($asset->amount, $amount, 8);
            $asset->locked_amount = bcadd($asset->locked_amount, $amount, 8);
            $asset->version++;
            $asset->save();

            // Record ledger entry
            $this->recordLedgerEntry(
                $user->id,
                $symbol,
                bcmul($amount, '-1', 8),
                $balanceBefore,
                $asset->amount,
                $amount,
                $lockedBefore,
                $asset->locked_amount,
                BalanceLedger::TYPE_ORDER_LOCK,
                $referenceId,
                $description ?? "Lock {$symbol} for sell order"
            );
        });
    }

    /**
     * Unlock asset when a sell order is cancelled.
     *
     * @throws OptimisticLockException
     */
    public function unlockAsset(User $user, string $symbol, string $amount, int $referenceId, string $description = null): void
    {
        DB::transaction(function () use ($user, $symbol, $amount, $referenceId, $description) {
            $asset = Asset::where('user_id', $user->id)
                ->where('symbol', $symbol)
                ->lockForUpdate()
                ->first();

            if (!$asset) {
                return;
            }

            $balanceBefore = $asset->amount;
            $lockedBefore = $asset->locked_amount;

            $asset->amount = bcadd($asset->amount, $amount, 8);
            $asset->locked_amount = bcsub($asset->locked_amount, $amount, 8);
            $asset->version++;
            $asset->save();

            $this->recordLedgerEntry(
                $user->id,
                $symbol,
                $amount,
                $balanceBefore,
                $asset->amount,
                bcmul($amount, '-1', 8),
                $lockedBefore,
                $asset->locked_amount,
                BalanceLedger::TYPE_ORDER_UNLOCK,
                $referenceId,
                $description ?? "Unlock {$symbol} for cancelled sell order"
            );
        });
    }

    /**
     * Credit USD to user's available balance.
     */
    public function creditUsd(User $user, string $amount, string $referenceType, int $referenceId, string $description = null): void
    {
        DB::transaction(function () use ($user, $amount, $referenceType, $referenceId, $description) {
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->first();

            $balanceBefore = $lockedUser->balance;

            $lockedUser->balance = bcadd($lockedUser->balance, $amount, 8);
            $lockedUser->version++;
            $lockedUser->save();

            $this->recordLedgerEntry(
                $lockedUser->id,
                'USD',
                $amount,
                $balanceBefore,
                $lockedUser->balance,
                '0',
                $lockedUser->locked_balance,
                $lockedUser->locked_balance,
                $referenceType,
                $referenceId,
                $description
            );

            $user->balance = $lockedUser->balance;
            $user->version = $lockedUser->version;
        });
    }

    /**
     * Debit USD from user's locked balance (for trade execution).
     */
    public function debitLockedUsd(User $user, string $amount, string $referenceType, int $referenceId, string $description = null): void
    {
        DB::transaction(function () use ($user, $amount, $referenceType, $referenceId, $description) {
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->first();

            $lockedBefore = $lockedUser->locked_balance;

            $lockedUser->locked_balance = bcsub($lockedUser->locked_balance, $amount, 8);
            $lockedUser->version++;
            $lockedUser->save();

            $this->recordLedgerEntry(
                $lockedUser->id,
                'USD',
                '0', // No change to available
                $lockedUser->balance,
                $lockedUser->balance,
                bcmul($amount, '-1', 8), // Deduction from locked
                $lockedBefore,
                $lockedUser->locked_balance,
                $referenceType,
                $referenceId,
                $description
            );

            $user->locked_balance = $lockedUser->locked_balance;
            $user->version = $lockedUser->version;
        });
    }

    /**
     * Credit asset to user.
     */
    public function creditAsset(User $user, string $symbol, string $amount, string $referenceType, int $referenceId, string $description = null): void
    {
        DB::transaction(function () use ($user, $symbol, $amount, $referenceType, $referenceId, $description) {
            $asset = Asset::firstOrCreate(
                ['user_id' => $user->id, 'symbol' => $symbol],
                ['amount' => '0', 'locked_amount' => '0', 'version' => 1]
            );

            $asset = Asset::where('id', $asset->id)->lockForUpdate()->first();

            $balanceBefore = $asset->amount;

            $asset->amount = bcadd($asset->amount, $amount, 8);
            $asset->version++;
            $asset->save();

            $this->recordLedgerEntry(
                $user->id,
                $symbol,
                $amount,
                $balanceBefore,
                $asset->amount,
                '0',
                $asset->locked_amount,
                $asset->locked_amount,
                $referenceType,
                $referenceId,
                $description
            );
        });
    }

    /**
     * Debit asset from locked balance (for trade execution).
     */
    public function debitLockedAsset(User $user, string $symbol, string $amount, string $referenceType, int $referenceId, string $description = null): void
    {
        DB::transaction(function () use ($user, $symbol, $amount, $referenceType, $referenceId, $description) {
            $asset = Asset::where('user_id', $user->id)
                ->where('symbol', $symbol)
                ->lockForUpdate()
                ->first();

            if (!$asset) {
                return;
            }

            $lockedBefore = $asset->locked_amount;

            $asset->locked_amount = bcsub($asset->locked_amount, $amount, 8);
            $asset->version++;
            $asset->save();

            $this->recordLedgerEntry(
                $user->id,
                $symbol,
                '0',
                $asset->amount,
                $asset->amount,
                bcmul($amount, '-1', 8),
                $lockedBefore,
                $asset->locked_amount,
                $referenceType,
                $referenceId,
                $description
            );
        });
    }

    /**
     * Record a ledger entry for audit trail.
     */
    private function recordLedgerEntry(
        int $userId,
        string $currency,
        string $amount,
        string $balanceBefore,
        string $balanceAfter,
        string $lockedAmount,
        string $lockedBefore,
        string $lockedAfter,
        string $referenceType,
        ?int $referenceId,
        ?string $description
    ): BalanceLedger {
        return BalanceLedger::create([
            'user_id' => $userId,
            'currency' => $currency,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'locked_amount' => $lockedAmount,
            'locked_before' => $lockedBefore,
            'locked_after' => $lockedAfter,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'description' => $description,
        ]);
    }
}
