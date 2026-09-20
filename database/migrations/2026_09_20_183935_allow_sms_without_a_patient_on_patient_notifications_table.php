<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Someone whose queue request is declined never became a patient, but is
     * still owed a message saying so. Such a message has no patient, so it
     * carries the number it was sent to; every other message keeps using its
     * patient's number.
     */
    public function up(): void
    {
        Schema::table('patient_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('patient_id')->nullable()->change();
            $table->string('phone', 20)->nullable()->after('patient_id');
        });
    }

    /**
     * Reverse the migrations. Messages with no patient can't exist under the
     * old shape, so they are removed first.
     */
    public function down(): void
    {
        DB::table('patient_notifications')->whereNull('patient_id')->delete();

        Schema::table('patient_notifications', function (Blueprint $table) {
            $table->dropColumn('phone');
            $table->unsignedBigInteger('patient_id')->nullable(false)->change();
        });
    }
};
