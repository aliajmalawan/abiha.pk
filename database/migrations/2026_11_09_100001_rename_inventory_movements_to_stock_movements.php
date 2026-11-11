<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('inventory_movements', 'stock_movements');   // the append-only stock ledger; keys and indexes move with the table
    }

    public function down(): void
    {
        Schema::rename('stock_movements', 'inventory_movements');
    }
};
