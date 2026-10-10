<?php

namespace Tests\Feature;

use App\Models\User;

/**
 * Revenue reporting is Admin-only: the Manager dashboard, report page and
 * report PDF data never carry revenue figures.
 */
class ManagerNoRevenueTest extends ApiFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Reports use MySQL's DATEDIFF; give the in-memory SQLite an equivalent.
        \Illuminate\Support\Facades\DB::connection()->getPdo()->sqliteCreateFunction(
            'DATEDIFF', fn ($a, $b) => (int) round((strtotime((string) $a) - strtotime((string) $b)) / 86400), 2
        );
    }

    private function manager(): User
    {
        return User::create([
            'first_name' => 'Mona', 'last_name' => 'Manager', 'email' => 'manager-norev@example.test',
            'password' => bcrypt('x'), 'role' => 'manager', 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    public function test_manager_report_page_has_no_revenue(): void
    {
        $response = $this->actingAs($this->manager())->get(route('manager.reports.index'));

        $response->assertOk();
        $response->assertDontSee('Revenue');
        $response->assertViewMissing('totalRevenue');
        $response->assertViewMissing('revenueByDay');
    }

    public function test_manager_dashboard_has_no_revenue(): void
    {
        $response = $this->actingAs($this->manager())->get(route('manager.dashboard'));

        $response->assertOk();
        $response->assertDontSee('Revenue');
        $response->assertViewMissing('periodRevenue');
    }
}
