<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->default(0);
            $table->foreignId('customer_id')->default(0);
            $table->foreignId('agent_id')->default(0);
            $table->string('deal_title')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0.5);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('status')->default('Open'); // ['Open', 'Negotiation', 'Closed-Won', 'Closed-Lost']
            $table->date('expected_close_date')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deals');
    }
};
