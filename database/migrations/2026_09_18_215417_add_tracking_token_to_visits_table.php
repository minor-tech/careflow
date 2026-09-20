<?php

use App\Support\TrackingToken;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The secret in a patient's tracking link (/t/{token}): the page needs no
     * login, so this is what keeps it private. It is kept apart from the visit
     * id, which is guessable, and existing visits get one so their links work.
     * The column stays nullable (nulls don't clash in a unique index); the
     * Visit model gives every new visit a token.
     */
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->string('tracking_token', TrackingToken::LENGTH)->nullable()->unique()->after('queue_number');
        });

        DB::table('visits')->whereNull('tracking_token')->orderBy('id')->each(function (object $visit): void {
            DB::table('visits')->where('id', $visit->id)->update(['tracking_token' => TrackingToken::generate()]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn('tracking_token');
        });
    }
};
