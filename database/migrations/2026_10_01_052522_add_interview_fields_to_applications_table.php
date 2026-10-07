<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->timestamp('interview_at')->nullable();
            $table->string('interview_location')->nullable();
            $table->timestamp('interview_completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn([
                'interview_at',
                'interview_location',
                'interview_completed_at',
            ]);
        });
    }
};