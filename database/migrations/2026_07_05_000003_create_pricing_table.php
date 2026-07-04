<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pricing', function (Blueprint $table) {
            $table->id();
            $table->string('plan', 16)->unique();       // monthly|annual
            $table->integer('price_fils');
            $table->timestamps();
        });

        DB::table('pricing')->insert([
            ['plan' => 'monthly', 'price_fils' => 14900,  'created_at' => now(), 'updated_at' => now()],
            ['plan' => 'annual',  'price_fils' => 100000, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing');
    }
};
