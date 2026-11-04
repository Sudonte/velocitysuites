<?php

namespace Tests\Feature;

use App\Models\Amenity;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/** Payment from every way in: the 20-50% rule at the API the pages call, no second payment while one waits, cash reservations, and the entry points on the pages. */
class GuestPaymentEntryPointsTest extends ApiFlowTestCase
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


    private function reservation(string $token, string $method): array
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 3500, 2, 3);
        $towel = Amenity::create(['amenity_name' => 'Towel', 'category' => 'Room', 'quantity' => 50, 'charge' => 50, 'status' => 'active']);
        $created = $this->bearer($token)->postJson('/api/guest/reservations', $this->wizardPayload($rt->id, $towel->id) + ['payment_method' => $method])->assertCreated()->json();

        return $created['reservation'] ?? $created;
    }

    private function pay(string $token, int $id, float $amount, string $ref, string $key)
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])->post('/api/guest/reservations/'.$id.'/payments', [
            'payment_method' => 'gcash', 'payment_type' => 'partial', 'amount_paid' => $amount, 'gcash_number' => '9171234567', 'reference_number' => $ref,
            'idempotency_key' => $key, 'receipt' => UploadedFile::fake()->image('r.jpg'),
        ]);
    }

    public function test_pay_now_on_a_gcash_reservation_takes_20_to_50_percent_and_never_twice_at_once(): void
    {
        [$user] = $this->makeGuestUser('PayEntry');
        $token = $this->webSignIn($user);
        $res = $this->reservation($token, 'gcash');
        $id = $res['id'];
        $total = (float) $res['total_amount_due'];

        $this->pay($token, $id, $total * 0.19, '2000000000001', 'k1')->assertStatus(422);     // under 20%
        $this->pay($token, $id, $total * 0.51, '2000000000002', 'k2')->assertStatus(422);     // over 50%
        $this->pay($token, $id, $total, '2000000000003', 'k3')->assertStatus(422);            // all of it, never online
        $first = $this->pay($token, $id, $total * 0.20, '2000000000004', 'k4');
        $this->assertContains($first->getStatusCode(), [200, 201]);

        // the same press again (same key) and a second payment while the first waits: no second payment is created
        $this->pay($token, $id, $total * 0.20, '2000000000004', 'k4');
        $this->pay($token, $id, $total * 0.30, '2000000000005', 'k5');
        $this->assertSame(1, \App\Models\Payment::where('reservation_id', $id)->count(), 'one payment, however many presses');

        $shown = $this->bearer($token)->getJson('/api/guest/reservations/'.$id)->assertOk()->json();
        $this->assertEquals(0.0, $shown['payment_summary']['total_amount_paid'], 'waiting for verification is not paid');
        $this->assertEqualsWithDelta($total * 0.20, $shown['payment_summary']['waiting_for_verification'], 0.01);
    }

    public function test_a_cash_reservation_is_paid_at_the_hotel_not_online(): void
    {
        [$user] = $this->makeGuestUser('PayCash');
        $token = $this->webSignIn($user);
        $res = $this->reservation($token, 'cash');
        $this->pay($token, $res['id'], (float) $res['total_amount_due'] * 0.3, '3000000000001', 'c1')->assertStatus(422);
        $this->assertSame(0, \App\Models\Payment::where('reservation_id', $res['id'])->count());
    }

    public function test_every_way_into_payment_uses_the_one_rule_and_the_pay_page_has_the_apps_steps(): void
    {
        $stays = file_get_contents(public_path('js/portal/stays.js'));
        $dash = file_get_contents(public_path('js/portal/dashboard.js'));
        $pay = file_get_contents(public_path('js/portal/pay.js'));
        $wiz = file_get_contents(public_path('js/portal/wizard.js'));
        $page = file_get_contents(resource_path('views/portal/pay.blade.php'));

        foreach (["S.payAction = function", "a: 'PAY_NOW'", "a: 'UNDER_REVIEW'", "a: 'FRONT_DESK'", "text: 'Pay at the hotel'", "text: 'Payment under review'", "Pay now</a>'"] as $n) {
            $this->assertStringContainsString($n, $stays, $n);
        }
        $this->assertSame(1, substr_count($stays, 'S.payAction = function'), 'one rule, not a copy per page');
        $this->assertStringContainsString('S.payAction(', $dash);
        $this->assertStringContainsString('payRows(live)', $dash);
        $this->assertStringContainsString("S.payAction({ kind: kind, it: it })", $stays);

        foreach (['Review billing', 'Check amount', 'Send via GCash', 'Enter reference', 'Upload receipt', "'Submit'", 'Hotel GCash number', 'Copy', 'GCash number copied', 'gcash-official-qr.jpg',
            'Replace', 'Remove', 'Submit payment of ', 'Yes, submit', 'Payment type', 'Partial payment (', 'MIN = 0.20', 'MAX = 0.50', 'Step '] as $n) {
            $this->assertStringContainsString($n, $pay, $n);
        }
        $this->assertStringContainsString('Waiting for verification', $pay);
        $this->assertStringContainsString("window.VSPay.run(main", $wiz);
        $this->assertStringContainsString("VSPay.run(host", $page);
        $this->assertStringContainsString("'/guest/reservations/' + id + '/payments'", $page);
        $this->assertStringContainsString("'/guest/bookings'", $wiz);
        foreach (['Pay at the hotel', 'Payment under review', 'CONVERTED_TO_BOOKING', 'notes: notes', 'Expected discount'] as $n) {
            $this->assertStringContainsString($n, $page, $n);
        }
        $this->get(route('guest.pay', ['kind' => 'reservation', 'id' => 5]))->assertRedirect(route('login'));
        [$user] = $this->makeGuestUser('PayPage');
        $this->actingAs($user)->get(route('guest.pay', ['kind' => 'reservation', 'id' => 5]))->assertOk()->assertSee('js/portal/pay.js', false);
    }
}
