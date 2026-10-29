<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customer_wallet_transactions', function (Blueprint $table): void {
            // A refund can now have one reward and one withdrawable credit.
            $table->dropUnique(['return_request_id']);
            $table->string('bucket', 16)->default('reward')->after('kind');
            $table->index(['customer_id', 'bucket']);
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->decimal('wallet_reward_amount', 12, 2)->nullable()->after('wallet_amount');
            $table->decimal('wallet_withdrawable_amount', 12, 2)->nullable()->after('wallet_reward_amount');
        });

        // A prior withdrawal (and its reversal) always belongs to cash that
        // can be withdrawn. Historical unknown credits remain conservative:
        // usable at checkout, but not cashable out.
        DB::table('customer_wallet_transactions')
            ->whereIn('kind', ['withdrawal', 'withdrawal_reversal'])
            ->update(['bucket' => 'withdrawable']);

        foreach (DB::table('customer_wallet_transactions')->where('kind', 'refund')->cursor() as $transaction) {
            $order = $transaction->order_id ? DB::table('orders')->find($transaction->order_id) : null;
            $manualAndReceived = $order
                && in_array($order->payment_method_code, ['bkash', 'nagad', 'rocket', 'upay', 'bank'], true)
                && $order->payment_received_at !== null;
            if ($manualAndReceived) DB::table('customer_wallet_transactions')->where('id', $transaction->id)->update(['bucket' => 'withdrawable']);
        }

        // Existing order spends predate the split, so retain the safe default.
        DB::table('orders')->whereNotNull('wallet_amount')->where('wallet_amount', '>', 0)
            ->update(['wallet_reward_amount' => DB::raw('wallet_amount'), 'wallet_withdrawable_amount' => 0]);
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['wallet_reward_amount', 'wallet_withdrawable_amount']));
        Schema::table('customer_wallet_transactions', function (Blueprint $table): void {
            $table->dropIndex(['customer_id', 'bucket']);
            $table->dropColumn('bucket');
            $table->unique('return_request_id');
        });
    }
};
