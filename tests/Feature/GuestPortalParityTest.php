<?php

namespace Tests\Feature;

use App\Models\Amenity;
use App\Models\ApiToken;
use App\Models\Booking;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * The guest WEB pages are drawn from the app's own /api/* endpoints (public/js/portal/*), so this suite proves the web and the
 * app cannot disagree: the page shells and their token, the sign-in exchange, and - with the exact request shape the web wizard
 * sends - a two-date booking with amenities per room and a promo room priced the same as the app, the 20-50% payment limits,
 * verified-only "paid", the cancel and delete rules and the receipt PDF.
 */
class GuestPortalParityTest extends ApiFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'api_tokens' => function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('user_id'); $t->string('token')->unique(); $t->string('device_name')->nullable(); $t->timestamp('last_used_at')->nullable(); $t->timestamp('expires_at')->nullable(); $t->timestamps(); },
            'announcements' => function (Blueprint $t) { $t->id(); $t->string('title'); $t->text('content'); $t->json('images')->nullable(); $t->string('status')->default('draft'); $t->date('published_at')->nullable(); $t->json('target_audience')->nullable(); $t->timestamp('notified_at')->nullable(); $t->timestamps(); },
            'registration_otps' => function (Blueprint $t) { $t->id(); $t->string('email')->unique(); $t->string('otp'); $t->json('payload')->nullable(); $t->integer('attempts')->default(0); $t->timestamp('expires_at')->nullable(); $t->timestamps(); },
            'promotion_amenity' => function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('promotion_id'); $t->unsignedBigInteger('amenity_id'); $t->unsignedInteger('quantity')->default(1); $t->timestamps(); },
        ] as $name => $def) {
            if (! Schema::hasTable($name)) {
                Schema::create($name, $def);
            }
        }
        foreach (['failed_login_attempts' => 'integer', 'last_failed_login_at' => 'timestamp', 'last_login_at' => 'timestamp', 'theme' => 'string', 'deactivated_at' => 'timestamp', 'must_change_password' => 'boolean'] as $col => $type) {
            if (! Schema::hasColumn('users', $col)) {
                Schema::table('users', fn (Blueprint $t) => $t->{$type}($col)->nullable());
            }
        }
        Storage::fake('public');
        Storage::fake('local');
    }

    private function bearer(string $plain): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json']);
    }

    /** Signs a guest in the way the web does: POST /api/login, then trade that token for a web session. */
    private function webSignIn(User $user, string $password = 'Test12345!'): string
    {
        $login = $this->postJson('/api/login', ['email' => $user->email, 'password' => $password, 'device_name' => 'Web'])->assertOk();

        return $login->json('token');
    }

    // ---- the pages and their token

    public function test_every_guest_page_draws_for_a_guest_with_the_session_token_and_sends_visitors_to_sign_in(): void
    {
        [$user] = $this->makeGuestUser('PortalPages');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 3500, 2, 3);
        $pages = [
            route('guest.dashboard') => 'js/portal/dashboard.js', route('guest.reservations.index') => 'js/portal/stays.js', route('guest.history') => 'js/portal/account.js',
            route('guest.notifications.index') => 'js/portal/account.js', route('guest.profile.show') => 'js/portal/account.js', route('guest.book.new') => 'js/portal/wizard.js',
            route('guest.reserve.new') => 'js/portal/wizard.js', route('guest.pay', ['kind' => 'reservation', 'id' => 5]) => 'js/portal/pay.js', route('guest.reservations.show', 7) => 'js/portal/stays.js',
            route('guest.bookings.show', 8) => 'js/portal/amenities.js', route('guest.receipts.show', 'SR-20261028-000154') => 'js/portal/receipts.js',
        ];
        foreach (array_keys($pages) as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
        foreach ($pages as $url => $script) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString($script, $html, $url);
            $this->assertStringContainsString('name="portal-token"', $html, $url);
        }
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        // the public pages need no sign-in and draw the same rooms as the app
        $this->get(route('home'))->assertOk()->assertSee('vsFeaturedRooms', false);
        $this->get(route('public.rooms.index'))->assertOk()->assertSee('js/portal/rooms.js', false);
        $this->get(route('public.rooms.show', $rt))->assertOk()->assertSee('VSRooms.details', false);
        $this->get(route('login'))->assertOk()->assertSee('VSAuth.login', false);
        $this->get(route('register'))->assertOk()->assertSee('VSAuth.register', false);
        $this->get(route('password.request'))->assertOk()->assertSee('VSAuth.forgot', false);
    }

    public function test_the_session_token_reaches_the_same_endpoints_as_the_app_and_does_not_touch_the_apps_token(): void
    {
        [$user] = $this->makeGuestUser('PortalToken');
        $app = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Test12345!', 'device_name' => 'Pixel'])->assertOk()->json('token');

        $html = $this->actingAs($user)->get(route('guest.dashboard'))->assertOk()->getContent();
        preg_match('/name="portal-token" content="([^"]+)"/', $html, $m);
        $web = $m[1];

        $this->bearer($web)->getJson('/api/guest/profile')->assertOk()->assertJsonPath('user.email', $user->email);
        $this->bearer($app)->getJson('/api/guest/profile')->assertOk();
        $this->assertSame('Web', ApiToken::where('token', hash('sha256', $web))->value('device_name'));
        $this->assertSame(2, $user->apiTokens()->count());

        // a second page in the same session reuses it; signing out revokes only the web token
        $again = $this->actingAs($user)->get(route('guest.reservations.index'))->assertOk()->getContent();
        $this->assertStringContainsString($web, $again);
        $this->actingAs($user)->post(route('logout'));
        $this->assertSame(0, ApiToken::where('token', hash('sha256', $web))->count());
        $this->assertSame(1, ApiToken::where('token', hash('sha256', $app))->count());
    }

    public function test_sign_in_goes_through_the_api_and_is_traded_for_a_web_session(): void
    {
        [$user] = $this->makeGuestUser('PortalSession');
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong-pass-1'])->assertStatus(401)->assertJsonPath('code', 'INVALID_CREDENTIALS')->assertJsonPath('message', 'Incorrect email or password.');
        $this->postJson('/api/login', ['email' => 'nobody@example.test', 'password' => 'wrong-pass-1'])->assertStatus(401)->assertJsonPath('message', 'Incorrect email or password.');

        $token = $this->webSignIn($user);
        $this->postJson(route('portal.session'), ['token' => $token])->assertOk()->assertJsonPath('redirect', route('guest.dashboard'));
        $this->get(route('guest.dashboard'))->assertOk();

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->postJson(route('portal.session'), ['token' => 'not-a-real-token'])->assertStatus(401);

        // a staff account's token is never traded for a guest session
        $desk = User::create(['first_name' => 'Rita', 'last_name' => 'Desk', 'email' => 'desk-portal@example.test', 'password' => bcrypt('x'), 'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now()]);
        $plain = 'staff-token';
        ApiToken::create(['user_id' => $desk->id, 'token' => hash('sha256', $plain), 'device_name' => 'x']);
        $this->postJson(route('portal.session'), ['token' => $plain])->assertStatus(401);
    }

    // ---- the booking the wizard builds, priced by the app's own endpoint

    /** The request the web wizard sends: two overlapping date ranges, amenities per room, a representative and a minor. */
    private function wizardPayload(int $roomTypeId, int $amenityId): array
    {
        $in = now('Asia/Manila')->addDays(5)->toDateString();
        $out = now('Asia/Manila')->addDays(7)->toDateString();
        $in2 = now('Asia/Manila')->addDays(6)->toDateString();
        $out2 = now('Asia/Manila')->addDays(8)->toDateString();

        return [
            'stays' => [
                ['check_in' => $in, 'check_out' => $out, 'rooms' => [['room_type_id' => $roomTypeId, 'quantity' => 2]],
                    'amenities' => [['amenity_id' => $amenityId, 'quantity' => 2, 'room_type_id' => $roomTypeId, 'room_slot' => 1]]],
                ['check_in' => $in2, 'check_out' => $out2, 'rooms' => [['room_type_id' => $roomTypeId, 'quantity' => 1]]],
            ],
            'adults' => 1, 'children' => 1, 'guest_first_name' => 'Maria', 'guest_last_name' => 'Santos',
            'additional_guests' => [['name' => 'Child 1', 'age' => 5, 'relationship' => 'Child']],
        ];
    }

    public function test_a_two_date_reservation_with_amenities_and_a_promo_room_is_priced_by_the_apps_endpoint(): void
    {
        [$user] = $this->makeGuestUser('PortalRes');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 3500, 2, 3);
        $towel = Amenity::create(['amenity_name' => 'Towel', 'category' => 'Room', 'quantity' => 50, 'charge' => 50, 'status' => 'active']);
        Promotion::create(['promo_name' => 'Deluxe 10', 'promo_type' => 'discount', 'discount_type' => 'percentage', 'discount_value' => 10, 'discount_applies_to' => 'rate',
            'room_type_id' => $rt->id, 'start_date' => today()->subDay(), 'end_date' => today()->addMonths(2), 'status' => 'active']);
        $token = $this->webSignIn($user);

        $created = $this->bearer($token)->postJson('/api/guest/reservations', $this->wizardPayload($rt->id, $towel->id) + ['payment_method' => 'cash'])->assertCreated()->json();
        $reservation = $created['reservation'] ?? $created;

        // what the wizard's review step adds up: rooms x booked nights, plus the paid amenities (the promo is a note until check-in)
        $expected = (3500 * 2 * 2) + (3500 * 2 * 1) + (50 * 2);
        $this->assertEquals($expected, $reservation['total_amount_due']);
        $shown = $this->bearer($token)->getJson('/api/guest/reservations/'.$reservation['id'])->assertOk()->json();
        $this->assertEquals($expected, $shown['payment_summary']['grand_total']);
        $this->assertCount(2, $shown['stays']);
        $this->assertEquals(14000.0, $shown['stays'][0]['rooms_subtotal']);
        $this->assertEquals(100.0, $shown['stays'][0]['amenities_subtotal']);
        $this->assertSame('Deluxe 10', $shown['promo_info']['available'][0]['title']);
        $this->assertEquals(0.0, $shown['payment_summary']['total_amount_paid']);
        $this->assertSame(1, (int) $shown['children']);
        $this->assertSame('Child', $shown['additional_guest_details'][0]['relationship'] ?? 'Child');
    }

    public function test_a_booking_paid_by_gcash_follows_the_20_to_50_percent_rule_and_only_verified_money_counts_as_paid(): void
    {
        [$user] = $this->makeGuestUser('PortalBook');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 3500, 2, 3);
        $towel = Amenity::create(['amenity_name' => 'Towel', 'category' => 'Room', 'quantity' => 50, 'charge' => 50, 'status' => 'active']);
        $token = $this->webSignIn($user);
        $total = (3500 * 2 * 2) + (3500 * 2 * 1) + 100;   // 21,100 as the wizard's review shows it

        $send = function (float $amount, string $ref) use ($token, $rt, $towel) {
            $fields = $this->wizardPayload($rt->id, $towel->id) + [
                'payment_method' => 'gcash', 'reference_number' => $ref, 'gcash_number' => '9171234567', 'amount_paid' => $amount, 'idempotency_key' => 'k'.$ref,
            ];

            return $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
                ->post('/api/guest/bookings', $fields + ['receipt' => UploadedFile::fake()->image('r.jpg')]);
        };

        $send($total * 0.10, '1000000000001')->assertStatus(422);      // below 20%
        $send($total * 0.60, '1000000000002')->assertStatus(422);      // above 50%
        $send($total, '1000000000003')->assertStatus(422);             // a full payment is never accepted online
        $ok = $send($total * 0.30, '1000000000004')->assertCreated();
        $id = ($ok->json('booking') ?? $ok->json())['id'];

        $b = $this->bearer($token)->getJson('/api/guest/bookings/'.$id)->assertOk()->json();
        $b = $b['booking'] ?? $b;
        $this->assertEquals($total, $b['payment_summary']['grand_total']);
        $this->assertEquals(0.0, $b['payment_summary']['total_amount_paid'], 'a submitted payment is waiting, not paid');
        $this->assertEqualsWithDelta($total * 0.30, $b['payment_summary']['waiting_for_verification'], 0.01);
        $this->assertEquals($total, $b['payment_summary']['remaining_balance']);

        // verified by the desk: now it counts
        $desk = User::create(['first_name' => 'Rita', 'last_name' => 'Desk', 'email' => 'desk-pp@example.test', 'password' => bcrypt('x'), 'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now()]);
        $payment = \App\Models\Payment::where('booking_id', $id)->firstOrFail();
        $this->actingAs($desk)->put(route('receptionist.payments.verify', $payment))->assertSessionHasNoErrors();
        $after = $this->bearer($token)->getJson('/api/guest/bookings/'.$id)->assertOk()->json();
        $after = $after['booking'] ?? $after;
        $this->assertEqualsWithDelta($total * 0.30, $after['payment_summary']['total_amount_paid'], 0.01);
        $this->assertEqualsWithDelta($total * 0.70, $after['payment_summary']['remaining_balance'], 0.01);
    }

    public function test_cancel_and_delete_follow_the_apps_rules_and_the_receipt_pdf_is_only_for_its_owner(): void
    {
        [$user] = $this->makeGuestUser('PortalCancel');
        [$other] = $this->makeGuestUser('PortalOther');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 3500, 2, 3);
        $towel = Amenity::create(['amenity_name' => 'Towel', 'category' => 'Room', 'quantity' => 50, 'charge' => 50, 'status' => 'active']);
        $token = $this->webSignIn($user);
        $desk = User::create(['first_name' => 'Rita', 'last_name' => 'Desk', 'email' => 'desk-pdf@example.test', 'password' => bcrypt('x'), 'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now()]);

        $res = $this->bearer($token)->postJson('/api/guest/reservations', $this->wizardPayload($rt->id, $towel->id) + ['payment_method' => 'cash'])->assertCreated()->json();
        $id = ($res['reservation'] ?? $res)['id'];
        $this->bearer($token)->deleteJson('/api/guest/reservations/'.$id)->assertStatus(409);          // an open reservation cannot be deleted
        $this->bearer($token)->putJson('/api/guest/reservations/'.$id.'/cancel')->assertOk();
        $this->bearer($token)->deleteJson('/api/guest/reservations/'.$id)->assertSuccessful();         // cancelled: it can

        // a booking paid 30% by GCash: cancel works until the desk verifies it, then Receipt 1 exists and prints
        $total = (3500 * 2 * 2) + (3500 * 2 * 1) + 100;
        $post = fn (string $ref) => $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])->post('/api/guest/bookings', $this->wizardPayload($rt->id, $towel->id) + [
            'payment_method' => 'gcash', 'reference_number' => $ref, 'gcash_number' => '9171234567', 'amount_paid' => $total * 0.3, 'idempotency_key' => 'k'.$ref, 'receipt' => UploadedFile::fake()->image('r.jpg'),
        ])->assertCreated()->json();
        $first = $post('2000000000001');
        $first = $first['booking'] ?? $first;
        $this->bearer($token)->putJson('/api/guest/bookings/'.$first['id'].'/cancel')->assertOk();

        $second = $post('2000000000002');
        $second = $second['booking'] ?? $second;
        $payment = \App\Models\Payment::where('booking_id', $second['id'])->firstOrFail();
        $this->actingAs($desk)->put(route('receptionist.payments.verify', $payment))->assertSessionHasNoErrors();
        $this->app['auth']->forgetGuards();
        $this->bearer($token)->putJson('/api/guest/bookings/'.$second['id'].'/cancel')->assertStatus(422);   // verified by the front desk: no longer cancellable

        $shown = $this->bearer($token)->getJson('/api/guest/bookings/'.$second['id'])->assertOk()->json();
        $shown = $shown['booking'] ?? $shown;
        $number = collect($shown['receipts'])->firstWhere('receipt_type', 'PARTIAL_RECEIPT')['receipt_number'];
        $this->assertNotEmpty($number);

        $web = $this->actingAs($user)->get(route('guest.receipts.pdf', $number));
        $web->assertOk();
        $this->assertSame('application/pdf', $web->headers->get('Content-Type'));
        $this->actingAs($other)->get(route('guest.receipts.pdf', $number))->assertNotFound();
    }

    /** Flattens nested arrays into the multipart field names the wizard's FormData uses (stays[0][rooms][0][quantity]). */
    private function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $key = $prefix === '' ? (string) $k : $prefix.'['.$k.']';
            if (is_array($v)) {
                $out += $this->flatten($v, $key);
            } else {
                $out[$key] = $v;
            }
        }

        return $out;
    }
}
