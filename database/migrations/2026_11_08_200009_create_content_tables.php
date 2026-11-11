<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $t) {
            $t->string('key', 100)->primary();
            $t->json('value')->nullable();
            $t->timestamps();
        });

        Schema::create('home_sections', function (Blueprint $t) {
            $t->id();
            $t->string('type', 30);                                 // hero|categories|products|collections|campaign|brand|testimonials|newsletter|social
            $t->string('title')->nullable();
            $t->string('subtitle', 500)->nullable();
            $t->json('settings')->nullable();
            $t->unsignedInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index(['is_active', 'sort_order']);
        });

        Schema::create('banners', function (Blueprint $t) {
            $t->id();
            $t->string('placement', 20);                            // hero | campaign
            $t->string('eyebrow')->nullable();
            $t->string('title');
            $t->string('subtitle', 500)->nullable();
            $t->string('image')->nullable();
            $t->string('cta_label')->nullable();
            $t->string('cta_url')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('is_sample')->default(false);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
            $t->index(['placement', 'is_active', 'sort_order']);
        });

        Schema::create('testimonials', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('location')->nullable();
            $t->unsignedTinyInteger('rating')->default(5);
            $t->text('body');
            $t->boolean('is_active')->default(true);
            $t->boolean('is_sample')->default(false);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
        });

        Schema::create('pages', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->string('slug')->unique();
            $t->longText('body')->nullable();
            $t->string('meta_title')->nullable();
            $t->string('meta_description')->nullable();
            $t->boolean('is_published')->default(false);
            $t->boolean('show_in_footer')->default(false);
            $t->boolean('is_sample')->default(false);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
        });

        Schema::create('newsletters', function (Blueprint $t) {
            $t->id();
            $t->string('email')->unique();
            $t->timestamp('subscribed_at')->useCurrent();
            $t->timestamp('unsubscribed_at')->nullable();
            $t->timestamps();
        });

        Schema::create('contact_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name');
            $t->string('email');
            $t->string('phone', 32)->nullable();
            $t->string('subject')->nullable();
            $t->text('message');
            $t->string('status', 10)->default('new');               // new | read | replied
            $t->string('ip_address', 45)->nullable();
            $t->timestamps();
            $t->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['contact_messages', 'newsletters', 'pages', 'testimonials', 'banners', 'home_sections', 'settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
