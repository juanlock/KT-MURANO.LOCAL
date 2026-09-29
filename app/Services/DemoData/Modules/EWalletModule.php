<?php

namespace App\Services\DemoData\Modules;

use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoModule;
use App\Services\DemoData\Modules\Concerns\DemoRefs;
use Illuminate\Support\Facades\DB;

/**
 * Customer e-wallets: a wallet per demo customer with a consistent ledger
 * (credits/debits whose running balance_after ends at the wallet balance) and
 * a few withdrawal requests. wallets.client_id is unique, so customers that
 * already own a wallet are skipped. Wallet tables have no company_id; they're
 * tied to the company through the client.
 */
class EWalletModule extends DemoModule
{
    use DemoRefs;

    public function key(): string
    {
        return 'EWallet';
    }

    public function label(): string
    {
        return 'E-Wallet';
    }

    public function dataTables(): array
    {
        return ['wallets', 'wallet_transactions', 'wallet_withdrawals'];
    }

    public function generate(DemoContext $ctx): array
    {
        $taken = DB::table('wallets')->pluck('client_id')->map(fn ($v) => (int) $v)->all();
        $clients = array_values(array_diff($this->clientIds($ctx, 10), $taken));
        while (count($clients) < 3) {
            $clients[] = $this->createDemoClient($ctx);
        }
        $clients = array_slice($clients, 0, 4);

        $wallets = $txCount = $wdCount = 0;
        $ledgers = [
            [['credit', 200, 'adjustment', 'Welcome credit'], ['debit', 45.5, 'checkout', 'Online order payment'], ['credit', 30, 'refund', 'Refund for returned item']],
            [['credit', 500, 'gift_card', 'Gift card redeemed'], ['debit', 120, 'pos_sale', 'In-store purchase'], ['debit', 80, 'checkout', 'Online order payment']],
            [['credit', 150, 'adjustment', 'Loyalty goodwill credit'], ['debit', 150, 'withdrawal', 'Withdrawal to bank account']],
            [['credit', 75, 'refund', 'Refund for cancelled order']],
        ];

        foreach ($clients as $i => $clientId) {
            $ledger = $ledgers[$i % count($ledgers)];
            $balance = 0.0;
            foreach ($ledger as [$type, $amount]) {
                $balance += $type === 'credit' ? $amount : -$amount;
            }
            $walletId = $ctx->insert('wallets', [
                'client_id' => $clientId,
                'balance' => round($balance, 2),
                'currency' => 'USD',
                'status' => $i === 3 ? 'frozen' : 'active',
            ]);
            $wallets++;

            $running = 0.0;
            $when = now()->subDays(60 - $i * 5);
            foreach ($ledger as [$type, $amount, $source, $note]) {
                $running += $type === 'credit' ? $amount : -$amount;
                $when = $when->copy()->addDays(random_int(2, 12))->setTime(random_int(9, 18), random_int(0, 59));
                $ctx->insert('wallet_transactions', [
                    'wallet_id' => $walletId,
                    'type' => $type,
                    'amount' => $amount,
                    'balance_after' => round($running, 2),
                    'source' => $source,
                    'note' => $note,
                    'created_by' => $ctx->userId,
                    'created_at' => $when,
                    'updated_at' => $when,
                ]);
                $txCount++;
            }

            // Withdrawals: the approved one matches the 'withdrawal' debit above.
            $withdrawal = match ($i) {
                0 => ['amount' => 50, 'status' => 'pending'],
                2 => ['amount' => 150, 'status' => 'approved', 'processed_by' => $ctx->userId, 'processed_at' => $when],
                1 => ['amount' => 1000, 'status' => 'rejected', 'processed_by' => $ctx->userId, 'processed_at' => now()->subDays(3), 'note' => 'Amount exceeds wallet balance'],
                default => null,
            };
            if ($withdrawal) {
                $ctx->insert('wallet_withdrawals', $withdrawal + [
                    'wallet_id' => $walletId,
                    'method' => 'bank_transfer',
                    'destination' => 'IBAN ****'.random_int(1000, 9999),
                ]);
                $wdCount++;
            }
        }

        return ['Wallets' => $wallets, 'Wallet transactions' => $txCount, 'Withdrawals' => $wdCount];
    }
}
