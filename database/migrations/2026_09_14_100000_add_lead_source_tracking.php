<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('source')->default('manual')->index()->after('stage');
            // Meta's leadgen_id. Unique so a replayed webhook cannot duplicate
            // a lead — Meta retries delivery until it receives a 200.
            $table->string('external_id')->nullable()->unique()->after('source');
            $table->string('form_id')->nullable()->after('external_id');
            $table->string('page_id')->nullable()->after('form_id');
            // Every answer Meta sent, including custom questions we do not map.
            $table->json('payload')->nullable()->after('page_id');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['source', 'external_id', 'form_id', 'page_id', 'payload']);
        });
    }
};
