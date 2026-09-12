<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('Sales Executive')->after('email');
            // Monthly revenue target, used to compute attainment on the dashboard.
            $table->unsignedBigInteger('monthly_target')->default(0)->after('role');
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            // Free-text descriptor shown in lists, e.g. "Villa - DHA Islamabad".
            $table->string('detail')->nullable();
            $table->string('stage')->default('new')->index();
            $table->unsignedBigInteger('value')->default(0);
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->timestamp('due_at')->index();
            $table->boolean('done')->default(false);
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('leads');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'monthly_target']);
        });
    }
};
