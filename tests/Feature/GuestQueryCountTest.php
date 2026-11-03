<?php

namespace Tests\Feature;

use App\Models\Amenity;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/** The slowest guest pages are the list endpoints behind them; their query count must not grow with the number of reservations (no N+1). */
class GuestQueryCountTest extends ApiFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'api_tokens' => function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('user_id'); $t->string('token')->unique(); $t->string('device_name')->nullable(); $t->timestamp('last_used_at')->nullable(); $t->timestamp('expires_at')->nullable(); $t->timestamps(); },
            'notifications' => function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('user_id'); $t->string('title'); $t->text('message'); $t->string('category')->nullable(); $t->unsignedBigInteger('reference_id')->nullable(); $t->string('target_audience')->nullable(); $t->string('receipt_number')->nullable(); $t->string('receipt_type')->nullable(); $t->boolean('is_read')->default(false); $t->timestamp('archived_at')->nullable(); $t->timestamps(); },
        ] as $name => $def) {
            if (! Schema::hasTable($name)) {
                Schema::create($name, $def);
            }
        }
        foreach (['failed_login_attempts' => 'integer', 'last_failed_login_at' => 'timestamp', 'last_login_at' => 'timestamp', 'theme' => 'string', 'must_change_password' => 'boolean', 'deactivated_at' => 'timestamp'] as $col => $type) {
            if (! Schema::hasColumn('users', $col)) {
                Schema::table('users', fn (Blueprint $t) => $t->{$type}($col)->nullable());
            }
        }
        Storage::fake('public');
    }

    private function queries(string $token, string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])->getJson($url)->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    public function test_the_guest_lists_do_not_run_more_queries_for_more_reservations(): void
    {
        [$user] = $this->makeGuestUser('Perf');
        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Test12345!', 'device_name' => 'Web'])->assertOk()->json('token');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 3500, 2, 30);
        $towel = Amenity::create(['amenity_name' => 'Towel', 'category' => 'Room', 'quantity' => 500, 'charge' => 50, 'status' => 'active']);
        $make = function (int $i) use ($token, $rt, $towel) {
            $in = now('Asia/Manila')->addDays(5 + $i * 3)->toDateString();
            $out = now('Asia/Manila')->addDays(7 + $i * 3)->toDateString();
            $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])->postJson('/api/guest/reservations', [
                'stays' => [['check_in' => $in, 'check_out' => $out, 'rooms' => [['room_type_id' => $rt->id, 'quantity' => 1]], 'amenities' => [['amenity_id' => $towel->id, 'quantity' => 1, 'room_type_id' => $rt->id, 'room_slot' => 1]]]],
                'adults' => 1, 'children' => 0, 'guest_first_name' => 'Maria', 'guest_last_name' => 'Santos', 'payment_method' => 'cash',
            ])->assertCreated();
        };

        $make(0);
        $urls = ['/api/guest/reservations?per_page=200', '/api/guest/bookings?per_page=200', '/api/notifications?per_page=200', '/api/rooms'];
        $before = [];
        foreach ($urls as $u) {
            $before[$u] = $this->queries($token, $u);
        }
        foreach ([1, 2, 3, 4, 5] as $i) {
            $make($i);
        }
        foreach ($urls as $u) {
            $after = $this->queries($token, $u);
            $this->assertLessThanOrEqual($before[$u] + 4, $after, "$u ran {$before[$u]} queries for 1 reservation but $after for 6 (N+1)");
        }
    }
}
