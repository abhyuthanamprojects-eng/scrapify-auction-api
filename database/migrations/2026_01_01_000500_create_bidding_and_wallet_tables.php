<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('vendor_name');
            $table->decimal('amount', 15, 2);
            $table->boolean('is_proxy')->default(false);
            $table->string('ip')->nullable();
            $table->timestamps();

            // Hot paths: "highest bid for this auction/lot" and bid history.
            $table->index(['auction_id', 'amount']);
            $table->index(['lot_id', 'amount']);
            $table->index(['auction_id', 'created_at']);
            $table->index('vendor_id');
        });

        Schema::create('proxy_bids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // forward auctions: ceiling. reverse auctions: floor.
            $table->decimal('max_amount', 15, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['auction_id', 'lot_id', 'vendor_id']);
        });

        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            // Ledger mirror only — this is not a real money store. Settlement
            // happens off-platform; these columns are derived from wallet_transactions.
            $table->decimal('balance', 15, 2)->default(0);
            $table->decimal('locked', 15, 2)->default(0);
            $table->string('currency', 3)->default('INR');
            $table->timestamps();

            $table->unique('user_id');
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            // add_money | emd_locked | emd_released | emd_forfeited
            // | payment | refund | payout
            $table->string('type');
            // Signed: positive credit, negative debit — matches the mobile ledger.
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_after', 15, 2)->nullable();
            $table->string('note')->nullable();
            $table->string('method')->nullable();       // UPI | Card | Net Banking | Wallet | Bank Transfer
            $table->string('status')->default('success'); // success | pending | failed
            $table->string('reference')->nullable();
            $table->foreignId('auction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['wallet_id', 'created_at']);
            $table->index('type');
            $table->index('reference');
        });

        Schema::create('emd_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('status')->default('locked'); // locked | released | forfeited
            $table->string('reference')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['auction_id', 'lot_id', 'vendor_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emd_transactions');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
        Schema::dropIfExists('proxy_bids');
        Schema::dropIfExists('bids');
    }
};
