<?php

namespace Tests\Feature;

use App\Models\User;

/**
 * Admin/Manager tables use length-aware pagination with a validated page
 * size (10/25/50) that survives alongside search filters.
 */
class AdminTablePaginationTest extends ApiFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The users page shows a pending staff password-reset badge.
        \Illuminate\Support\Facades\Schema::create('staff_password_reset_requests', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('processed_by')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamps();
        });
    }

    private function admin(): User
    {
        return User::create([
            'first_name' => 'Ada', 'last_name' => 'Admin', 'email' => 'admin-pages@example.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    public function test_page_size_is_validated_and_totals_are_shown(): void
    {
        $admin = $this->admin();
        for ($i = 1; $i <= 12; $i++) {
            User::create([
                'first_name' => 'Guest', 'last_name' => "Number{$i}", 'email' => "g{$i}@example.test",
                'password' => bcrypt('x'), 'role' => 'guest', 'status' => 'active',
            ]);
        }

        $this->actingAs($admin)->get(route('admin.users.index'))
            ->assertOk()
            ->assertViewHas('users', fn ($users) => $users->perPage() === 10 && $users->total() === 13)
            ->assertSee('Showing 1');

        $this->get(route('admin.users.index', ['per_page' => 25]))
            ->assertViewHas('users', fn ($users) => $users->perPage() === 25 && $users->lastPage() === 1);

        $this->get(route('admin.users.index', ['per_page' => 7]))
            ->assertViewHas('users', fn ($users) => $users->perPage() === 10);
    }

    public function test_page_links_keep_the_search_filter(): void
    {
        $admin = $this->admin();
        for ($i = 1; $i <= 12; $i++) {
            User::create([
                'first_name' => 'Zed', 'last_name' => "Match{$i}", 'email' => "z{$i}@example.test",
                'password' => bcrypt('x'), 'role' => 'guest', 'status' => 'active',
            ]);
        }

        $html = $this->actingAs($admin)->get(route('admin.users.index', ['search' => 'Zed']))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/search=Zed[^"]*page=2|page=2[^"]*search=Zed/', html_entity_decode($html));
    }
}
