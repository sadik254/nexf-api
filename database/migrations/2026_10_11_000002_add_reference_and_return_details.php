<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->string('reference', 24)->nullable()->unique();
        });
        Schema::table('return_requests', function (Blueprint $table): void {
            $table->string('reference', 24)->nullable()->unique();
            $table->string('refund_to', 20)->default('balance');
            $table->string('return_courier', 150)->nullable();
            $table->string('return_address_name', 150)->nullable();
            $table->string('return_address_phone', 32)->nullable();
            $table->string('return_address_email', 255)->nullable();
            $table->text('return_address')->nullable();
            $table->string('return_address_area', 150)->nullable();
            $table->string('return_address_district', 150)->nullable();
            $table->text('staff_note')->nullable();
        });
        Schema::table('withdrawal_requests', function (Blueprint $table): void {
            $table->string('reference', 24)->nullable()->unique();
            $table->string('transaction_id', 255)->nullable();
        });
        Schema::table('customer_wallet_transactions', function (Blueprint $table): void {
            $table->string('kind', 32)->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
        });

        foreach (DB::table('support_tickets')->select('id')->orderBy('id')->cursor() as $row) {
            DB::table('support_tickets')->where('id', $row->id)->update(['reference' => 'SUP-' . str_pad((string) $row->id, 5, '0', STR_PAD_LEFT)]);
        }
        foreach (DB::table('return_requests')->select('id')->orderBy('id')->cursor() as $row) {
            DB::table('return_requests')->where('id', $row->id)->update(['reference' => 'RET-' . str_pad((string) $row->id, 5, '0', STR_PAD_LEFT)]);
        }
        foreach (DB::table('withdrawal_requests')->select('id')->orderBy('id')->cursor() as $row) {
            DB::table('withdrawal_requests')->where('id', $row->id)->update(['reference' => 'WDR-' . str_pad((string) $row->id, 5, '0', STR_PAD_LEFT)]);
        }
        DB::table('customer_wallet_transactions')->whereNotNull('return_request_id')->update(['kind' => 'refund']);
        DB::table('customer_wallet_transactions')->whereNotNull('withdrawal_request_id')->where('type', 'debit')->update(['kind' => 'withdrawal']);
        DB::table('customer_wallet_transactions')->whereNotNull('withdrawal_request_id')->where('type', 'credit')->update(['kind' => 'withdrawal_reversal']);
    }

    public function down(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table): void {
            $table->dropUnique(['reference']);
            $table->dropColumn(['reference', 'transaction_id']);
        });
        Schema::table('customer_wallet_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('order_id');
            $table->dropColumn('kind');
        });
        Schema::table('return_requests', function (Blueprint $table): void {
            $table->dropUnique(['reference']);
            $table->dropColumn(['reference', 'refund_to', 'return_courier', 'return_address_name', 'return_address_phone', 'return_address_email', 'return_address', 'return_address_area', 'return_address_district', 'staff_note']);
        });
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->dropUnique(['reference']);
            $table->dropColumn('reference');
        });
    }
};
