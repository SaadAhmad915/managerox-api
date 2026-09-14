<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Splits the overloaded `leads` table into three objects.
 *
 * A lead was carrying both the person and the opportunity: it had a pipeline
 * stage and a value. That fuses two lifetimes — a person outlives any single
 * deal, and one person may hold several. So:
 *
 *   lead     an enquiry, with a status, that is converted once
 *   contact  the person, kept forever
 *   deal     one opportunity, carrying the stage and the money
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable()->index();
            $table->string('phone')->nullable();
            $table->string('company')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            // Where this person came from, when they came from an enquiry.
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stage')->default('new')->index();
            $table->unsignedBigInteger('value')->default(0);
            $table->date('expected_close_on')->nullable();
            $table->timestamp('closed_at')->nullable()->index();
            // A lost deal keeps its stage for history but leaves the funnel.
            $table->timestamp('lost_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->string('status')->default('new')->index()->after('detail');
            $table->timestamp('converted_at')->nullable()->index()->after('status');
            // The opportunity moved to `deals`; a lead no longer owns either.
            // SQLite refuses to drop an indexed column, so the index goes first.
            $table->dropIndex('leads_stage_index');
            $table->dropColumn(['stage', 'value']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('contact_id')->nullable()->after('lead_id')
                ->constrained()->nullOnDelete();
            $table->foreignId('deal_id')->nullable()->after('contact_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contact_id');
            $table->dropConstrainedForeignId('deal_id');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['status', 'converted_at']);
            $table->string('stage')->default('new');
            $table->unsignedBigInteger('value')->default(0);
        });

        Schema::dropIfExists('deals');
        Schema::dropIfExists('contacts');
    }
};
