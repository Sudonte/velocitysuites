<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Announcements are fully System Administrator-managed and shown
     * across several surfaces (public Home page, Guest/Manager/
     * Receptionist web dashboards, the Velocity Suites mobile app) based
     * on `target_audience` - a nullable JSON array of role strings
     * ('public'/'guest'/'manager'/'receptionist'). Null/empty means "all
     * of those audiences", not "no one" - see App\Models\Announcement's
     * visibleTo() scope. Admin is deliberately never a valid audience
     * value; announcements are not shown on the Admin dashboard.
     */
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content');
            $table->json('images')->nullable();
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->date('published_at')->nullable();
            $table->json('target_audience')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
