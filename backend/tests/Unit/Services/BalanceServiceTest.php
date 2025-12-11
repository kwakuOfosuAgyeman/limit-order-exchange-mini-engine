<?php

namespace Tests\Unit\Services;

use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\OptimisticLockException;
use App\Models\Asset;
use App\Models\BalanceLedger;
use App\Models\User;
use App\Services\BalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BalanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private BalanceService $balanceService;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->balanceService = app(BalanceService::class);
        $this->user = User::factory()->create([
            'balance' => '10000.00000000',
            'locked_balance' => '0.00000000',
            'version' => 1,
        ]);
    }

    // ==================== USD LOCK TESTS ====================

    public function test_lock_usd_funds_reduces_available_balance(): void
    {
        $this->balanceService->lockUsdFunds($this->user, '5000.00000000', 1, 'Test lock');

        $this->user->refresh();
        $this->assertEquals('5000.00000000', $this->user->balance);
    }

    public function test_lock_usd_funds_increases_locked_balance(): void
    {
        $this->balanceService->lockUsdFunds($this->user, '5000.00000000', 1, 'Test lock');

        $this->user->refresh();
        $this->assertEquals('5000.00000000', $this->user->locked_balance);
    }

    public function test_lock_usd_funds_increments_version(): void
    {
        $initialVersion = $this->user->version;

        $this->balanceService->lockUsdFunds($this->user, '5000.00000000', 1, 'Test lock');

        $this->user->refresh();
        $this->assertEquals($initialVersion + 1, $this->user->version);
    }

    public function test_lock_usd_funds_creates_ledger_entry(): void
    {
        $this->balanceService->lockUsdFunds($this->user, '5000.00000000', 1, 'Test lock');

        $this->assertDatabaseHas('balance_ledger', [
            'user_id' => $this->user->id,
            'currency' => 'USD',
            'reference_type' => BalanceLedger::TYPE_ORDER_LOCK,
            'reference_id' => 1,
        ]);
    }

    public function test_lock_usd_fails_with_insufficient_balance(): void
    {
        $this->expectException(InsufficientBalanceException::class);

        $this->balanceService->lockUsdFunds($this->user, '15000.00000000', 1, 'Test lock');
    }

    public function test_lock_usd_fails_with_stale_version(): void
    {
        // Simulate another process updating the user
        User::where('id', $this->user->id)->update(['version' => 99]);

        $this->expectException(OptimisticLockException::class);

        $this->balanceService->lockUsdFunds($this->user, '5000.00000000', 1, 'Test lock');
    }

    public function test_lock_usd_records_balance_before_and_after(): void
    {
        $this->balanceService->lockUsdFunds($this->user, '3000.00000000', 1, 'Test lock');

        $ledger = BalanceLedger::where('user_id', $this->user->id)
            ->where('reference_type', BalanceLedger::TYPE_ORDER_LOCK)
            ->first();

        $this->assertEquals('10000.00000000', $ledger->balance_before);
        $this->assertEquals('7000.00000000', $ledger->balance_after);
        $this->assertEquals('0.00000000', $ledger->locked_before);
        $this->assertEquals('3000.00000000', $ledger->locked_after);
    }

    // ==================== USD UNLOCK TESTS ====================

    public function test_unlock_usd_funds_increases_available_balance(): void
    {
        // First lock some funds
        $this->balanceService->lockUsdFunds($this->user, '5000.00000000', 1, 'Lock');

        // Then unlock
        $this->balanceService->unlockUsdFunds($this->user, '5000.00000000', 1, 'Unlock');

        $this->user->refresh();
        $this->assertEquals('10000.00000000', $this->user->balance);
    }

    public function test_unlock_usd_funds_decreases_locked_balance(): void
    {
        // First lock some funds
        $this->balanceService->lockUsdFunds($this->user, '5000.00000000', 1, 'Lock');

        // Then unlock
        $this->balanceService->unlockUsdFunds($this->user, '5000.00000000', 1, 'Unlock');

        $this->user->refresh();
        $this->assertEquals('0.00000000', $this->user->locked_balance);
    }

    public function test_unlock_usd_creates_ledger_entry(): void
    {
        $this->balanceService->lockUsdFunds($this->user, '5000.00000000', 1, 'Lock');
        $this->balanceService->unlockUsdFunds($this->user, '5000.00000000', 1, 'Unlock');

        $this->assertDatabaseHas('balance_ledger', [
            'user_id' => $this->user->id,
            'currency' => 'USD',
            'reference_type' => BalanceLedger::TYPE_ORDER_UNLOCK,
        ]);
    }

    public function test_partial_unlock_leaves_remainder_locked(): void
    {
        $this->balanceService->lockUsdFunds($this->user, '5000.00000000', 1, 'Lock');
        $this->balanceService->unlockUsdFunds($this->user, '2000.00000000', 1, 'Partial unlock');

        $this->user->refresh();
        $this->assertEquals('7000.00000000', $this->user->balance);
        $this->assertEquals('3000.00000000', $this->user->locked_balance);
    }

    // ==================== ASSET LOCK TESTS ====================

    public function test_lock_asset_reduces_available_amount(): void
    {
        $asset = Asset::create([
            'user_id' => $this->user->id,
            'symbol' => 'BTC/USD',
            'amount' => '10.00000000',
            'locked_amount' => '0.00000000',
            'version' => 1,
        ]);

        $this->balanceService->lockAsset($this->user, 'BTC/USD', '5.00000000', 1, 'Test lock');

        $asset->refresh();
        $this->assertEquals('5.00000000', $asset->amount);
    }

    public function test_lock_asset_increases_locked_amount(): void
    {
        $asset = Asset::create([
            'user_id' => $this->user->id,
            'symbol' => 'BTC/USD',
            'amount' => '10.00000000',
            'locked_amount' => '0.00000000',
            'version' => 1,
        ]);

        $this->balanceService->lockAsset($this->user, 'BTC/USD', '5.00000000', 1, 'Test lock');

        $asset->refresh();
        $this->assertEquals('5.00000000', $asset->locked_amount);
    }

    public function test_lock_asset_fails_with_insufficient_balance(): void
    {
        Asset::create([
            'user_id' => $this->user->id,
            'symbol' => 'BTC/USD',
            'amount' => '5.00000000',
            'locked_amount' => '0.00000000',
            'version' => 1,
        ]);

        $this->expectException(InsufficientBalanceException::class);

        $this->balanceService->lockAsset($this->user, 'BTC/USD', '10.00000000', 1, 'Test lock');
    }

    public function test_lock_asset_fails_when_asset_does_not_exist(): void
    {
        $this->expectException(InsufficientBalanceException::class);

        $this->balanceService->lockAsset($this->user, 'BTC/USD', '1.00000000', 1, 'Test lock');
    }

    public function test_lock_asset_creates_ledger_entry(): void
    {
        Asset::create([
            'user_id' => $this->user->id,
            'symbol' => 'BTC/USD',
            'amount' => '10.00000000',
            'locked_amount' => '0.00000000',
            'version' => 1,
        ]);

        $this->balanceService->lockAsset($this->user, 'BTC/USD', '5.00000000', 1, 'Test lock');

        $this->assertDatabaseHas('balance_ledger', [
            'user_id' => $this->user->id,
            'currency' => 'BTC/USD',
            'reference_type' => BalanceLedger::TYPE_ORDER_LOCK,
        ]);
    }

    // ==================== ASSET UNLOCK TESTS ====================

    public function test_unlock_asset_increases_available_amount(): void
    {
        $asset = Asset::create([
            'user_id' => $this->user->id,
            'symbol' => 'BTC/USD',
            'amount' => '5.00000000',
            'locked_amount' => '5.00000000',
            'version' => 1,
        ]);

        $this->balanceService->unlockAsset($this->user, 'BTC/USD', '5.00000000', 1, 'Test unlock');

        $asset->refresh();
        $this->assertEquals('10.00000000', $asset->amount);
    }

    public function test_unlock_asset_decreases_locked_amount(): void
    {
        $asset = Asset::create([
            'user_id' => $this->user->id,
            'symbol' => 'BTC/USD',
            'amount' => '5.00000000',
            'locked_amount' => '5.00000000',
            'version' => 1,
        ]);

        $this->balanceService->unlockAsset($this->user, 'BTC/USD', '5.00000000', 1, 'Test unlock');

        $asset->refresh();
        $this->assertEquals('0.00000000', $asset->locked_amount);
    }

    public function test_unlock_asset_creates_ledger_entry(): void
    {
        Asset::create([
            'user_id' => $this->user->id,
            'symbol' => 'BTC/USD',
            'amount' => '5.00000000',
            'locked_amount' => '5.00000000',
            'version' => 1,
        ]);

        $this->balanceService->unlockAsset($this->user, 'BTC/USD', '5.00000000', 1, 'Test unlock');

        $this->assertDatabaseHas('balance_ledger', [
            'user_id' => $this->user->id,
            'currency' => 'BTC/USD',
            'reference_type' => BalanceLedger::TYPE_ORDER_UNLOCK,
        ]);
    }

    // ==================== USD CREDIT TESTS ====================

    public function test_credit_usd_increases_available_balance(): void
    {
        $this->balanceService->creditUsd(
            $this->user,
            '5000.00000000',
            BalanceLedger::TYPE_TRADE_CREDIT,
            1,
            'Test credit'
        );

        $this->user->refresh();
        $this->assertEquals('15000.00000000', $this->user->balance);
    }

    public function test_credit_usd_creates_ledger_entry(): void
    {
        $this->balanceService->creditUsd(
            $this->user,
            '5000.00000000',
            BalanceLedger::TYPE_TRADE_CREDIT,
            1,
            'Test credit'
        );

        $this->assertDatabaseHas('balance_ledger', [
            'user_id' => $this->user->id,
            'currency' => 'USD',
            'reference_type' => BalanceLedger::TYPE_TRADE_CREDIT,
            'amount' => '5000.00000000',
        ]);
    }

    // ==================== USD DEBIT FROM LOCKED TESTS ====================

    public function test_debit_locked_usd_reduces_locked_balance(): void
    {
        // First lock some funds
        $this->balanceService->lockUsdFunds($this->user, '5000.00000000', 1, 'Lock');

        $this->balanceService->debitLockedUsd(
            $this->user,
            '5000.00000000',
            BalanceLedger::TYPE_TRADE_DEBIT,
            1,
            'Test debit'
        );

        $this->user->refresh();
        $this->assertEquals('0.00000000', $this->user->locked_balance);
    }

    public function test_debit_locked_usd_does_not_affect_available_balance(): void
    {
        $this->balanceService->lockUsdFunds($this->user, '5000.00000000', 1, 'Lock');
        $availableAfterLock = $this->user->balance;

        $this->balanceService->debitLockedUsd(
            $this->user,
            '5000.00000000',
            BalanceLedger::TYPE_TRADE_DEBIT,
            1,
            'Test debit'
        );

        $this->user->refresh();
        $this->assertEquals($availableAfterLock, $this->user->balance);
    }

    // ==================== ASSET CREDIT TESTS ====================

    public function test_credit_asset_creates_asset_if_not_exists(): void
    {
        $this->assertDatabaseMissing('assets', [
            'user_id' => $this->user->id,
            'symbol' => 'BTC/USD',
        ]);

        $this->balanceService->creditAsset(
            $this->user,
            'BTC/USD',
            '1.00000000',
            BalanceLedger::TYPE_TRADE_CREDIT,
            1,
            'Test credit'
        );

        $asset = Asset::where('user_id', $this->user->id)
            ->where('symbol', 'BTC/USD')
            ->first();

        $this->assertNotNull($asset);
        $this->assertEquals('1.00000000', $asset->amount);
    }

    public function test_credit_asset_increases_existing_asset_amount(): void
    {
        Asset::create([
            'user_id' => $this->user->id,
            'symbol' => 'BTC/USD',
            'amount' => '5.00000000',
            'locked_amount' => '0.00000000',
            'version' => 1,
        ]);

        $this->balanceService->creditAsset(
            $this->user,
            'BTC/USD',
            '2.50000000',
            BalanceLedger::TYPE_TRADE_CREDIT,
            1,
            'Test credit'
        );

        $asset = Asset::where('user_id', $this->user->id)
            ->where('symbol', 'BTC/USD')
            ->first();

        $this->assertEquals('7.50000000', $asset->amount);
    }

    // ==================== ASSET DEBIT FROM LOCKED TESTS ====================

    public function test_debit_locked_asset_reduces_locked_amount(): void
    {
        Asset::create([
            'user_id' => $this->user->id,
            'symbol' => 'BTC/USD',
            'amount' => '5.00000000',
            'locked_amount' => '5.00000000',
            'version' => 1,
        ]);

        $this->balanceService->debitLockedAsset(
            $this->user,
            'BTC/USD',
            '5.00000000',
            BalanceLedger::TYPE_TRADE_DEBIT,
            1,
            'Test debit'
        );

        $asset = Asset::where('user_id', $this->user->id)
            ->where('symbol', 'BTC/USD')
            ->first();

        $this->assertEquals('0.00000000', $asset->locked_amount);
    }

    public function test_debit_locked_asset_does_not_affect_available_amount(): void
    {
        Asset::create([
            'user_id' => $this->user->id,
            'symbol' => 'BTC/USD',
            'amount' => '5.00000000',
            'locked_amount' => '3.00000000',
            'version' => 1,
        ]);

        $this->balanceService->debitLockedAsset(
            $this->user,
            'BTC/USD',
            '3.00000000',
            BalanceLedger::TYPE_TRADE_DEBIT,
            1,
            'Test debit'
        );

        $asset = Asset::where('user_id', $this->user->id)
            ->where('symbol', 'BTC/USD')
            ->first();

        $this->assertEquals('5.00000000', $asset->amount);
    }

    // ==================== LEDGER AUDIT TRAIL ====================

    public function test_ledger_records_description(): void
    {
        $description = 'Test description for audit';

        $this->balanceService->lockUsdFunds($this->user, '1000.00000000', 1, $description);

        $this->assertDatabaseHas('balance_ledger', [
            'user_id' => $this->user->id,
            'description' => $description,
        ]);
    }

    public function test_ledger_records_reference_id(): void
    {
        $referenceId = 12345;

        $this->balanceService->lockUsdFunds($this->user, '1000.00000000', $referenceId, 'Test');

        $this->assertDatabaseHas('balance_ledger', [
            'user_id' => $this->user->id,
            'reference_id' => $referenceId,
        ]);
    }

    // ==================== PRECISION TESTS ====================

    public function test_balance_operations_maintain_precision(): void
    {
        $this->balanceService->lockUsdFunds($this->user, '1234.56789012', 1, 'Precision test');

        $this->user->refresh();
        $this->assertEquals('8765.43210988', $this->user->balance);
        $this->assertEquals('1234.56789012', $this->user->locked_balance);
    }

    public function test_small_amount_operations_work_correctly(): void
    {
        $this->balanceService->lockUsdFunds($this->user, '0.00000001', 1, 'Small amount test');

        $this->user->refresh();
        $this->assertEquals('9999.99999999', $this->user->balance);
        $this->assertEquals('0.00000001', $this->user->locked_balance);
    }
}
