<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use App\Services\Dae1aReportService;
use App\Support\Dae1aSpreadsheet;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * DAE-1A monthly form: per-room guests per night (highlighted on check-in
 * day), (5) guests checked in, (6) guest nights, (7) rooms occupied, totals,
 * averages, the Excel layout, and who may download it.
 */
class Dae1aReportTest extends ApiFlowTestCase
{
    private function stay(int $roomTypeId, array $roomIds, string $in, string $out, int $guests, string $status = Booking::STATUS_COMPLETED, ?string $checkedOutAt = null): Booking
    {
        $booking = Booking::create([
            'room_type_id' => $roomTypeId, 'rooms_requested' => count($roomIds),
            'check_in' => $in, 'check_out' => $out, 'adults' => $guests, 'children' => 0, 'number_of_guests' => $guests,
            'booking_status' => $status, 'payment_method' => 'cash',
        ]);
        foreach ($roomIds as $roomId) {
            DB::table('booking_rooms')->insert([
                'booking_id' => $booking->id, 'room_id' => $roomId,
                'checked_out_at' => $checkedOutAt ?? ($status === Booking::STATUS_COMPLETED ? $out . ' 11:00:00' : null),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $booking;
    }

    public function test_counts_guests_per_room_per_night(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        [$r1, $r2, $r3] = Room::orderBy('id')->pluck('id')->all();
        $this->stay($rt->id, [$r1], '2026-10-05', '2026-10-07', 2);            // 2 nights
        $this->stay($rt->id, [$r2, $r3], '2026-10-06', '2026-10-07', 3);       // 1 night, 3 guests over 2 rooms
        $this->stay($rt->id, [$r3], '2026-09-30', '2026-10-02', 1);            // carried over from September
        $this->stay($rt->id, [$r1], '2026-10-20', '2026-10-22', 2, Booking::STATUS_CANCELLED);

        $r = app(Dae1aReportService::class)->build(2026, 10);
        $day = fn (int $d) => $r['days'][$d - 1];

        $this->assertCount(31, $r['days']);
        $this->assertSame('Mon', $day(5)['weekday']);
        $this->assertSame(['guests' => 2, 'checked_in' => true], $day(5)['rooms'][$r1]);
        $this->assertSame(['guests' => 2, 'checked_in' => false], $day(6)['rooms'][$r1]);
        $this->assertNull($day(7)['rooms'][$r1]);                              // checked out on the 7th
        $this->assertSame(2, $day(6)['rooms'][$r2]['guests']);
        $this->assertSame(1, $day(6)['rooms'][$r3]['guests']);
        $this->assertSame(['guests' => 1, 'checked_in' => false], $day(1)['rooms'][$r3]);

        $this->assertSame([2, 2, 1], [$day(5)['checked_in'], $day(5)['overnight'], $day(5)['rooms_occupied']]);
        $this->assertSame([3, 5, 3], [$day(6)['checked_in'], $day(6)['overnight'], $day(6)['rooms_occupied']]);
        $this->assertSame([0, 1, 1], [$day(1)['checked_in'], $day(1)['overnight'], $day(1)['rooms_occupied']]);
        $this->assertSame(0, $day(20)['rooms_occupied']);                      // cancelled stay ignored

        $this->assertSame(['checked_in' => 5, 'overnight' => 8, 'rooms_occupied' => 5, 'rooms_available' => 93], $r['totals']);
        $this->assertEquals(1.6, $r['averages']['guest_nights']);
        $this->assertEquals(5.38, $r['averages']['occupancy_rate']);
        $this->assertEquals(1.6, $r['averages']['guests_per_room']);
    }

    public function test_excel_lays_out_the_form(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Std', 800, 2, 20);
        $rooms = Room::orderBy('id')->get();
        $this->stay($rt->id, [$rooms[0]->id], '2026-10-05', '2026-10-07', 2);

        $sheet = Dae1aSpreadsheet::make(app(Dae1aReportService::class)->build(2026, 10))->getActiveSheet();

        $this->assertSame('Form: DAE-1A', $sheet->getCell('W2')->getValue());   // 2 date cols + 20 rooms, then (5)-(7)
        $this->assertSame(20, $sheet->getCell('Y4')->getValue());
        $this->assertSame("Room No.\nStd-1", $sheet->getCell('C6')->getValue());
        $this->assertSame("Room No.\nStd-2", $sheet->getCell('D6')->getValue()); // natural order: Std-2 before Std-10
        $this->assertSame(2, $sheet->getCell('C11')->getValue());                // day 5 is row 11
        $this->assertSame('FFFF00', $sheet->getStyle('C11')->getFill()->getStartColor()->getRGB());
        $this->assertNotSame('FFFF00', $sheet->getStyle('C12')->getFill()->getStartColor()->getRGB());
        $this->assertSame('TOTAL OF THIS MONTH', $sheet->getCell('A38')->getValue());
        $this->assertEquals(2, $sheet->getCell('W38')->getCalculatedValue());
        $this->assertEquals(4, $sheet->getCell('X38')->getCalculatedValue());
        $this->assertEquals(2, $sheet->getCell('Y38')->getCalculatedValue());
        $this->assertEquals(2, $sheet->getCell('Y40')->getCalculatedValue());    // average guest-night
        // 20 rooms -> the 16th room starts a new printed page; the last 5 rooms leave room for (5)-(7)
        $this->assertSame(['R1'], array_keys($sheet->getColumnBreaks()));
        $this->assertSame('Room Identification', $sheet->getCell('R5')->getValue());
        $this->assertSame(['A', 'B'], $sheet->getPageSetup()->getColumnsToRepeatAtLeft());
    }

    public function test_totals_move_to_their_own_page_when_they_do_not_fit(): void
    {
        $this->makeRoomTypeWithRooms('Std', 800, 2, 29);
        $sheet = Dae1aSpreadsheet::make(app(Dae1aReportService::class)->build(2026, 10))->getActiveSheet();

        // rooms 1-15 | rooms 16-29 | (5)-(7) starting at column AF
        $this->assertSame(['R1', 'AF1'], array_keys($sheet->getColumnBreaks()));
    }

    public function test_uses_the_actual_check_in_and_out_in_hotel_time(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 1);
        $roomId = Room::value('id');
        // Planned Oct 5-7, but checked in early: 23:30 UTC on Oct 1 is 07:30 on Oct 2 in Manila,
        // and the room was checked out 03:00 UTC on Oct 4 (11:00 Manila).
        $booking = $this->stay($rt->id, [$roomId], '2026-10-05', '2026-10-07', 2, Booking::STATUS_COMPLETED, '2026-10-04 03:00:00');
        $booking->forceFill(['checked_in_at' => '2026-10-01 23:30:00'])->save();

        $r = app(Dae1aReportService::class)->build(2026, 10);
        $guests = fn (int $d) => $r['days'][$d - 1]['rooms'][$roomId]['guests'] ?? 0;

        $this->assertSame([0, 2, 2, 0, 0], [$guests(1), $guests(2), $guests(3), $guests(4), $guests(5)]);
        $this->assertTrue($r['days'][1]['rooms'][$roomId]['checked_in']);
        $this->assertSame(['checked_in' => 2, 'overnight' => 4, 'rooms_occupied' => 2, 'rooms_available' => 31], $r['totals']);
    }

    public function test_admin_and_manager_download_receptionist_cannot(): void
    {
        $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);
        $make = fn (string $role) => User::create(['first_name' => 'T', 'last_name' => $role, 'email' => "$role@t.test", 'role' => $role]);

        foreach (['manager' => '/manager/reports/dae-1a', 'admin' => '/admin/reports/dae-1a'] as $role => $url) {
            $response = $this->actingAs($make($role))->get($url . '?month=2026-10');
            $response->assertOk();
            $this->assertStringContainsString('Velocity-Suites-DAE-1A_2026-10.xlsx', $response->headers->get('Content-Disposition'));
            $file = tempnam(sys_get_temp_dir(), 'dae') . '.xlsx';
            file_put_contents($file, $response->streamedContent());
            $this->assertSame('Month of October    Year 2026', IOFactory::load($file)->getActiveSheet()->getCell('E3')->getValue());
            @unlink($file);
        }

        $denied = $this->actingAs($make('receptionist'))->get('/manager/reports/dae-1a');
        $denied->assertRedirect('/');
        $this->assertNull($denied->headers->get('Content-Disposition'));
    }

    public function test_month_parameter_falls_back_to_this_month(): void
    {
        $this->assertSame([2026, 2], Dae1aReportService::monthFrom('2026-02'));
        $this->assertSame([(int) now()->year, (int) now()->month], Dae1aReportService::monthFrom('2026-13'));
        $this->assertSame([(int) now()->year, (int) now()->month], Dae1aReportService::monthFrom(null));
    }
}
