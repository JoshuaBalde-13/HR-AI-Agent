<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applicants', function (Blueprint $table) {

            // Nullable so the applicants you already have keep working.
            if (!Schema::hasColumn('applicants', 'email')) {
                $table->string('email')->nullable()->unique();
            }

            if (!Schema::hasColumn('applicants', 'password')) {
                $table->string('password')->nullable();
            }

            if (!Schema::hasColumn('applicants', 'remember_token')) {
                $table->rememberToken();
            }
        });
    }

    public function down(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->dropColumn(['email', 'password', 'remember_token']);
        });
    }
};