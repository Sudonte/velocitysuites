<?php

namespace Tests\Feature;

use App\Http\Controllers\Receptionist\CheckOutController;
use App\Models\Billing;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression coverage for a real historical bug: refreshStayCharges() only
 * ever re-parented a reservation-derived payment_stage='deposit' payment
 * onto the Billing generated at checkout, on the mistaken assumption that
 * a 'final'-stage one (a guest who paid their entire quoted total upfront)
 * was "already re-parented at conversion time" - conversion only ever
 * marks that payment completed, it never touches billing_id, since no
 * Billing exists yet at conversion time. Found live on production bookings
 * #96/#133 (and 14 further historical bookings) - the checkout balance
 * calculation ignored the guest's real prior payment entirely and asked
 * for the full amount again.
 */
class CheckoutFinalStagePaymentReparentingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('room_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('rate', 10, 2);
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('room_number')->unique();
            $table->string('room_name');
            $table->unsignedBigInteger('room_type_id');
            $table->integer('room_capacity')->default(2);
            $table->decimal('rate_override', 10, 2)->nullable();
            $table->string('status', 20)->default('available');
            $table->timestamps();
        });

        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key')->nullable()->unique();
            $table->unsignedBigInteger('guest_id')->nullable();
            $table->string('guest_first_name')->nullable();
            $table->string('guest_middle_name')->nullable();
            $table->string('guest_last_name')->nullable();
            $table->unsignedBigInteger('room_type_id');
            $table->unsignedTinyInteger('rooms_requested')->default(1);
            $table->unsignedBigInteger('room_id')->nullable();
            $table->dateTime('check_in');
            $table->dateTime('check_out');
            $table->integer('number_of_guests')->default(1);
            $table->unsignedInteger('adults')->default(1);
            $table->unsignedInteger('children')->default(0);
            $table->string('status', 40);
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->string('payment_preference', 20)->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->decimal('selected_payment_percentage', 5, 2)->nullable();
            $table->decimal('required_payment_amount', 10, 2)->nullable();
            $table->timestamp('payment_method_locked_at')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('payment_reminder_sent_at')->nullable();
            $table->boolean('discount_requested')->default(false);
            $table->string('discount_verification_status', 20)->default('not_requested');
            $table->string('rejection_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('reservation_room_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reservation_id');
            $table->unsignedBigInteger('room_type_id')->nullable();
            $table->string('room_type_name');
            $table->integer('quantity');
            $table->decimal('price_per_night', 10, 2);
            $table->integer('number_of_nights');
            $table->decimal('subtotal', 10, 2);
            $table->timestamps();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key')->nullable()->unique();
            $table->unsignedBigInteger('reservation_id')->nullable()->unique();
            $table->unsignedBigInteger('guest_id')->nullable();
            $table->string('guest_first_name')->nullable();
            $table->string('guest_middle_name')->nullable();
            $table->string('guest_last_name')->nullable();
            $table->string('checkin_permanent_address')->nullable();
            $table->string('checkin_current_address')->nullable();
            $table->string('checkin_contact_number', 20)->nullable();
            $table->unsignedBigInteger('room_type_id');
            $table->unsignedTinyInteger('rooms_requested')->default(1);
            $table->unsignedBigInteger('room_id')->nullable();
            $table->dateTime('check_in')->nullable();
            $table->timestamp('checkin_reminder_sent_at')->nullable();
            $table->dateTime('check_out')->nullable();
            $table->integer('adults')->nullable();
            $table->integer('children')->nullable();
            $table->integer('number_of_guests')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('booking_status', 40);
            $table->string('payment_method', 20)->nullable();
            $table->decimal('selected_payment_percentage', 5, 2)->nullable();
            $table->decimal('required_payment_amount', 10, 2)->nullable();
            $table->string('id_card_type')->nullable();
            $table->string('id_card_image_path')->nullable();
            $table->text('additional_guest_details')->nullable();
            $table->boolean('discount_requested')->default(false);
            $table->string('discount_verification_status')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('booking_room_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id');
            $table->unsignedBigInteger('room_type_id')->nullable();
            $table->string('room_type_name');
            $table->integer('quantity');
            $table->decimal('price_per_night', 10, 2);
            $table->integer('number_of_nights');
            $table->decimal('subtotal', 10, 2);
            $table->timestamps();
        });

        Schema::create('booking_rooms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id');
            $table->unsignedBigInteger('room_id');
            $table->timestamps();
        });

        Schema::create('billings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id')->unique();
            $table->decimal('room_charge', 10, 2)->default(0);
            $table->decimal('additional_guest_fee', 10, 2)->default(0);
            $table->decimal('amenity_charge', 10, 2)->default(0);
            $table->decimal('discount', 10, 2)->default(0);
            $table->unsignedBigInteger('discount_id')->nullable();
            $table->unsignedBigInteger('discount_verified_by')->nullable();
            $table->timestamp('discount_verified_at')->nullable();
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->string('billing_status')->default('pending');
            $table->string('receipt_number')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('billing_id')->nullable();
            $table->unsignedBigInteger('reservation_id')->nullable();
            $table->unsignedBigInteger('booking_id')->nullable();
            $table->string('payment_method', 20);
            $table->string('reference_number')->nullable();
            $table->string('receipt_path')->nullable();
            $table->string('gcash_number', 15)->nullable();
            $table->decimal('amount_paid', 10, 2);
            $table->string('payment_status', 20);
            $table->string('payment_stage', 20);
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->timestamp('payment_date')->nullable();
            $table->string('receipt_number')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('amenity_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('guest_id')->nullable();
            $table->unsignedBigInteger('reservation_id')->nullable();
            $table->unsignedBigInteger('booking_id')->nullable();
            $table->unsignedBigInteger('room_type_id')->nullable();
            $table->unsignedBigInteger('amenity_id')->nullable();
            $table->string('amenity_name')->nullable();
            $table->string('category')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('charge', 10, 2)->default(0);
            $table->string('status', 20)->default('pending');
            $table->timestamps();
        });

        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('additional_charges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('billing_id');
            $table->string('description')->nullable();
            $table->decimal('amount', 10, 2);
            $table->timestamps();
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->text('description')->nullable();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamps();
        });
    }

    private function makeRoomType(): RoomType
    {
        return RoomType::create(['name' => 'Test Room', 'rate' => 2000, 'status' => 'active']);
    }

    private function makeRoom(int $roomTypeId): Room
    {
        return Room::create([
            'room_number' => '101', 'room_name' => 'Room 101',
            'room_type_id' => $roomTypeId, 'room_capacity' => 2, 'status' => 'occupied',
        ]);
    }

    private function makeConvertedBooking(RoomType $roomType, Room $room, string $paymentMethod): Booking
    {
        $reservation = Reservation::create([
            'room_type_id' => $roomType->id,
            'rooms_requested' => 1,
            'check_in' => now()->subDay(),
            'check_out' => now(),
            'number_of_guests' => 1,
            'adults' => 1,
            'children' => 0,
            'status' => 'CONVERTED_TO_BOOKING',
            'payment_method' => $paymentMethod,
        ]);

        $booking = Booking::create([
            'reservation_id' => $reservation->id,
            'room_type_id' => $roomType->id,
            'rooms_requested' => 1,
            'check_in' => $reservation->check_in,
            'check_out' => $reservation->check_out,
            'adults' => 1,
            'children' => 0,
            'number_of_guests' => 1,
            'confirmed_at' => now(),
            'booking_status' => Booking::STATUS_CHECKED_IN,
            'payment_method' => $paymentMethod,
            'verified_at' => now(),
        ]);
        $booking->rooms()->attach($room->id);

        return $booking;
    }

    /** The bug: a full-upfront ('final'-stage) reservation payment must not be charged again at checkout. */
    public function test_final_stage_payment_is_reparented_and_checkout_does_not_charge_again(): void
    {
        $roomType = $this->makeRoomType();
        $room = $this->makeRoom($roomType->id);
        $booking = $this->makeConvertedBooking($roomType, $room, 'gcash');

        $payment = Payment::create([
            'reservation_id' => $booking->reservation_id,
            'payment_method' => 'gcash',
            'amount_paid' => 2000,
            'payment_status' => 'completed',
            'payment_stage' => 'final',
            'payment_date' => now(),
        ]);

        $controller = app(CheckOutController::class);
        $controller->checkOutBilling($booking->fresh());

        $billing = Billing::where('booking_id', $booking->id)->firstOrFail();
        $payment->refresh();
        $this->assertEquals($billing->id, $payment->billing_id, 'the final-stage payment must be re-parented onto the new billing');
        $this->assertEquals('paid', $billing->billing_status, 'the full upfront payment already settles the bill');

        // Checkout must not require any further amount - the balance is
        // already zero, so a $0 submission must complete cleanly.
        $request = new Request();
        $request->merge(['payment_method' => 'cash', 'amount_paid' => 0]);
        $response = $controller->recordPayment($request, $billing->fresh());
        $body = json_decode($response->getContent(), true);
        $this->assertTrue($body['completed']);
        $this->assertEquals(0, $body['balance']);

        // Exactly one payment must exist for this billing - never a second,
        // duplicate one created to "cover" a balance that was never real.
        $this->assertEquals(1, Payment::where('billing_id', $billing->id)->count());
        $this->assertEquals(2000.0, (float) Payment::where('billing_id', $billing->id)->sum('amount_paid'));
    }

    /** The previously-valid path must still work: a deposit-stage payment reparents and the true remaining balance is still collected. */
    public function test_deposit_stage_payment_still_reparents_and_remaining_balance_is_collected(): void
    {
        $roomType = $this->makeRoomType();
        $room = $this->makeRoom($roomType->id);
        $booking = $this->makeConvertedBooking($roomType, $room, 'cash');

        $deposit = Payment::create([
            'reservation_id' => $booking->reservation_id,
            'payment_method' => 'cash',
            'amount_paid' => 500,
            'payment_status' => 'completed',
            'payment_stage' => 'deposit',
            'payment_date' => now(),
        ]);

        $controller = app(CheckOutController::class);
        $controller->checkOutBilling($booking->fresh());

        $billing = Billing::where('booking_id', $booking->id)->firstOrFail();
        $deposit->refresh();
        $this->assertEquals($billing->id, $deposit->billing_id);
        $this->assertEquals('partial', $billing->billing_status, 'only the 500 deposit is in - 1500 of the 2000 total is still genuinely due');

        $request = new Request();
        $request->merge(['payment_method' => 'cash', 'amount_paid' => 1500]);
        $response = $controller->recordPayment($request, $billing->fresh());
        $body = json_decode($response->getContent(), true);
        $this->assertTrue($body['completed']);

        $this->assertEquals(2, Payment::where('billing_id', $billing->id)->count());
        $this->assertEquals(2000.0, (float) Payment::where('billing_id', $billing->id)->sum('amount_paid'));
    }

    /** A direct booking (no reservation) never had this bug - guard against a future regression there too. */
    public function test_direct_booking_final_stage_payment_still_reparents(): void
    {
        $roomType = $this->makeRoomType();
        $room = $this->makeRoom($roomType->id);

        $booking = Booking::create([
            'reservation_id' => null,
            'room_type_id' => $roomType->id,
            'rooms_requested' => 1,
            'check_in' => now()->subDay(),
            'check_out' => now(),
            'adults' => 1,
            'children' => 0,
            'number_of_guests' => 1,
            'confirmed_at' => now(),
            'booking_status' => Booking::STATUS_CHECKED_IN,
            'payment_method' => 'gcash',
            'verified_at' => now(),
        ]);
        $booking->rooms()->attach($room->id);

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'payment_method' => 'gcash',
            'amount_paid' => 2000,
            'payment_status' => 'completed',
            'payment_stage' => 'final',
            'payment_date' => now(),
        ]);

        $controller = app(CheckOutController::class);
        $controller->checkOutBilling($booking->fresh());

        $billing = Billing::where('booking_id', $booking->id)->firstOrFail();
        $payment->refresh();
        $this->assertEquals($billing->id, $payment->billing_id);
        $this->assertEquals('paid', $billing->billing_status);
    }
}
