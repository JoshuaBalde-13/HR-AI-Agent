<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * employee_name stays for now, so nothing that works today breaks.
     * employee_id is filled in by the StaffSeeder.
     */
    public function up(): void
    {
        Schema::table('employee_documents', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_id')->nullable()->index();
        });

        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_id')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employee_documents', function (Blueprint $table) {
            $table->dropIndex(['employee_id']);
            $table->dropColumn('employee_id');
        });

        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropIndex(['employee_id']);
            $table->dropColumn('employee_id');
        });
    }
};