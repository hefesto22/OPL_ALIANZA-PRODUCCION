<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->enum('status', ['pending', 'approved', 'rejected'])
                ->default('pending')
                ->after('type');

            $table->text('rejection_reason')->nullable()->after('status');

            $table->foreignId('reviewed_by')->nullable()
                ->constrained('users')->nullOnDelete()
                ->after('rejection_reason');

            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropColumn(['status', 'rejection_reason', 'reviewed_at']);
            $table->dropForeignIdFor(\App\Models\User::class, 'reviewed_by');
        });
    }
};
