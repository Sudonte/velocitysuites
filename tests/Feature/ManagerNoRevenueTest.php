<?php

namespace Tests\Feature;

use App\Models\User;

/**
 * Managers see room revenue, ADR and RevPAR [REQ050, REQ061], but never the
 * Admin-only cash figures: Payments Collected and Other Charges - on the
 * dashboard, the report page or the report data.
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

    public function test_manager_report_has_room_revenue_but_no_cash_figures(): void
    {
        $response = $this->actingAs($this->manager())->get(route('manager.reports.index'));

        $response->assertOk()
            ->assertSee('Room Revenue')->assertSee('RevPAR')
            ->assertDontSee('Payments Collected')->assertDontSee('Other Charges')
            ->assertViewHas('report', fn ($r) => array_key_exists('room_revenue', $r['summary'])
                && ! array_key_exists('payments_collected', $r['summary'])
                && ! array_key_exists('other_charges', $r['summary']));
    }

    public function test_manager_dashboard_has_room_revenue_but_no_cash_figures(): void
    {
        $response = $this->actingAs($this->manager())->get(route('manager.dashboard'));

        $response->assertOk()
            ->assertSee('Room Revenue')
            ->assertDontSee('Payments Collected')
            ->assertViewHas('hotel', fn ($h) => isset($h['adr'], $h['revpar']) && ! isset($h['payments_collected']));
    }
}
