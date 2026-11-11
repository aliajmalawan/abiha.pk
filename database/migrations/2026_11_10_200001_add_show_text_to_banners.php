<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $t) {
            // true = print the headline, text and button over the picture (the original look); false = the picture already carries its own design
            $t->boolean('show_text')->default(true)->after('cta_url');
        });
    }

    public function down(): void
    {
        Schema::table('banners', fn (Blueprint $t) => $t->dropColumn('show_text'));
    }
};
