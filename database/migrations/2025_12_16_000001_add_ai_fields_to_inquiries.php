<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->string('location')->nullable()->after('region'); // mesto/naselje (Pefkohori, Stavros...)
            $table->string('month_hint')->nullable()->after('date_to'); // npr "sredina jula", "druga polovina juna"

            $table->string('children_ages')->nullable()->after('children'); // [5, 3] ili []
            $table->string('language')->nullable()->after('special_requirements');

            $table->string('extraction_mode')->nullable()->after('reply_mode'); // 'ai' | 'fallback'
            $table->text('extraction_debug')->nullable()->after('extraction_mode'); // optional: šta je AI/fallback vratio
        });
    }

    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropColumn([
                'location',
                'month_hint',
                'children_ages',
                'language',
                'extraction_mode',
                'extraction_debug',
            ]);
        });
    }
};
