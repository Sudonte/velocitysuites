<?php

namespace Tests\Feature;

use App\Support\SafeDate;

/** A date typed into a URL (or a probe such as "'><asdf>-f3f3") used to end in a 500 on the public and API room lists. */
class SafeDateTest extends ApiFlowTestCase
{
    public function test_only_real_dates_are_dates(): void
    {
        $this->assertSame('2026-11-20', SafeDate::parse('2026-11-20')->toDateString());
        $this->assertSame('2026-11-20', SafeDate::parse(' 2026-11-20 ')->toDateString());
        foreach (["'><asdf alt=\"\">-f3f3", '%27%3E', '273e3c61', '2026-13-45', 'zzz', '99999-99-99', '1999-01-01', '', null, ['2026-11-20']] as $bad) {
            $this->assertNull(SafeDate::parse($bad), json_encode($bad));
        }
    }

    public function test_the_room_lists_answer_200_whatever_is_in_the_date_parameters(): void
    {
        $this->makeRoomTypeWithRooms('Deluxe', 3500, 2, 2);
        $probe = rawurlencode("'><asdf alt=\"\">-f3f3");
        foreach (["/rooms?check_in={$probe}", "/rooms?check_in=2026-13-45&check_out=zzz", "/rooms/1?check_in={$probe}&check_out={$probe}", "/api/rooms?check_in={$probe}", "/api/rooms?check_out={$probe}", "/api/rooms/1?check_in=99999-99-99"] as $url) {
            $response = $this->get($url, ['Accept' => str_starts_with($url, '/api') ? 'application/json' : 'text/html']);
            $this->assertSame(200, $response->getStatusCode(), $url);
        }
        // a real range still gives the dates back as before
        $json = $this->getJson('/api/rooms?check_in=2030-05-01&check_out=2030-05-04')->assertOk()->json();
        $this->assertSame('2030-05-01', $json['check_in']);
        $this->assertSame('2030-05-04', $json['check_out']);
    }
}
