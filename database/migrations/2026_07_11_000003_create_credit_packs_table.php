<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master-editable Business Hunt credit packs (name, how many credits, price in
 * fils). Deliberately a separate table from `pricing` (the Lens subscription
 * price) so the two billing models never entangle. Editing a pack changes what
 * is sold going forward; it does not touch any shop's existing balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_packs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->unsignedInteger('credits');
            $table->unsignedInteger('price_fils');   // 100 fils = AED 1
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_packs');
    }
};
