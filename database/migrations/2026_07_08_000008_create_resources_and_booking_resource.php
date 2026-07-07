<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('resources', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id')->index();
            $table->string('name');
            $table->string('type')->default('room'); // room | chair | machine | ...
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('catalogs', function (Blueprint $table) {
            $table->string('requires_resource_type')->nullable()->after('buffer_minutes');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedBigInteger('resource_id')->nullable()->index()->after('staff_id');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn('resource_id'));
        Schema::table('catalogs', fn (Blueprint $table) => $table->dropColumn('requires_resource_type'));
        Schema::dropIfExists('resources');
    }
};
