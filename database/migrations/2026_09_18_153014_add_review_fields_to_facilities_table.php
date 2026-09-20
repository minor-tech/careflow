<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            // "rejected" is kept distinct from a later "suspended".
            $table->enum('status', ['pending_review', 'active', 'suspended', 'rejected'])
                ->default('pending_review')
                ->change();

            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
        });
    }

    /**
     * Reverse the migrations. Rejected facilities fall back to suspended,
     * the closest status in the narrower schema.
     */
    public function down(): void
    {
        DB::table('facilities')->where('status', 'rejected')->update(['status' => 'suspended']);

        Schema::table('facilities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['reviewed_at', 'rejection_reason']);
            $table->enum('status', ['pending_review', 'active', 'suspended'])
                ->default('pending_review')
                ->change();
        });
    }
};
