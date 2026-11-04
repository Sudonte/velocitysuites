<?php

namespace Tests\Feature;

/** A missing page is a friendly page with a way back, in the guest layout for guests; per_page and filters never end in a server error. */
class FriendlyNotFoundTest extends ApiFlowTestCase
{
    public function test_a_guest_sees_the_friendly_404_in_the_guest_layout(): void
    {
        [$user] = $this->makeGuestUser('NF1');
        $html = $this->actingAs($user)->get('/guest/receipts/NOPE-1/preview')->assertNotFound()->getContent();
        $this->assertStringContainsString("We couldn&rsquo;t find that page", $html);
        $this->assertStringContainsString('href="' . route('guest.dashboard') . '"', $html);
        $this->assertStringContainsString('/guest/reservations', $html);
        $this->assertStringContainsString('vs-shell', $html, 'inside the guest layout (sidebar and header)');
    }

    public function test_a_visitor_sees_a_plain_friendly_404_and_json_stays_json(): void
    {
        $this->get('/rooms/99999')->assertNotFound()->assertSee('We couldn&rsquo;t find that page', false)->assertSee('Browse rooms');
        $this->getJson('/api/rooms/99999')->assertNotFound()->assertJsonMissing(['We couldn&rsquo;t find that page']);
    }

    public function test_array_valued_filters_are_not_a_server_error(): void
    {
        $this->makeRoomTypeWithRooms('Deluxe', 3500, 2, 2);
        foreach (['/rooms?capacity[]=2', '/rooms?check_in[]=x&check_out[]=y', '/rooms?min_price[]=1&room_type[]=a'] as $url) {
            $this->assertSame(200, $this->get($url)->getStatusCode(), $url);
        }
        $this->getJson('/api/rooms?capacity[]=2&min_price[]=1')->assertOk();
    }

    public function test_per_page_is_capped_and_never_below_one(): void
    {
        [$user] = $this->makeGuestUser('PP');
        if (! \Illuminate\Support\Facades\Schema::hasTable('api_tokens')) {
            \Illuminate\Support\Facades\Schema::create('api_tokens', function (\Illuminate\Database\Schema\Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('user_id'); $t->string('token')->unique(); $t->string('device_name')->nullable(); $t->timestamp('last_used_at')->nullable(); $t->timestamp('expires_at')->nullable(); $t->timestamps();
            });
        }
        \Illuminate\Support\Facades\DB::table('api_tokens')->insert(['user_id' => $user->id, 'token' => hash('sha256', 'pp-token'), 'created_at' => now(), 'updated_at' => now()]);
        $this->withHeaders(['Authorization' => 'Bearer pp-token']);
        foreach (['/api/guest/reservations', '/api/guest/bookings', '/api/notifications'] as $url) {
            $big = $this->getJson($url . '?per_page=99999')->assertOk()->json();
            $this->assertLessThanOrEqual(200, $big['per_page'], $url);
            $neg = $this->getJson($url . '?per_page=-5')->assertOk()->json();
            $this->assertGreaterThanOrEqual(1, $neg['per_page'], $url);
            $this->getJson($url . '?per_page=abc')->assertOk();
        }
    }
}
