<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Guest web, third pass: the six booking/reservation totals and where they link, the Booking & Reservation tabs, Profile Management
 * (every section's payload and the app's rules, which the page sends to the same /api/guest/profile), the shell (Log out only in the
 * header menu, footer rule, "Guest" label), Featured rooms without the APK, and that no preview/example pages exist.
 */
class GuestParityV3Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('email')->unique();
            $table->enum('role', ['admin', 'manager', 'receptionist', 'guest'])->default('guest');
            $table->string('status', 20)->default('active');
            $table->string('theme')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->integer('failed_login_attempts')->default(0);
            $table->timestamp('last_failed_login_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('restore_deadline')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('guests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('mobile_number', 20)->nullable()->unique();
            $table->string('gender')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->integer('age')->nullable();
            $table->string('address', 500)->nullable();
            $table->string('country')->nullable();
            $table->string('region')->nullable();
            $table->string('province')->nullable();
            $table->string('city')->nullable();
            $table->string('barangay')->nullable();
            $table->string('street')->nullable();
            $table->string('zip_code')->nullable();
            $table->string('timezone')->nullable();
            $table->string('profile_picture')->nullable();
            $table->timestamp('profile_last_updated_at')->nullable();
            $table->timestamp('profile_picture_changed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('email_change_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->string('new_email');
            $table->string('otp');
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->text('description')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamps();
        });
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('token')->unique();
            $table->string('device_name')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('title');
            $table->text('message');
            $table->string('category')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('target_audience')->nullable();
            $table->string('receipt_number')->nullable();
            $table->string('receipt_type')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
    }

    private function guest(string $password = 'Correct123'): array
    {
        $user = User::create(['first_name' => 'Ana', 'last_name' => 'Cruz', 'email' => 'ana'.uniqid().'@example.test', 'password' => Hash::make($password), 'role' => 'guest', 'status' => 'active', 'email_verified_at' => now()]);
        DB::table('guests')->insert(['user_id' => $user->id, 'country' => 'Philippines', 'mobile_number' => '09170000001', 'gender' => 'female', 'date_of_birth' => '1990-01-01', 'age' => 36, 'address' => 'Somewhere', 'created_at' => now(), 'updated_at' => now()]);
        $plain = 'tok-'.$user->id.'-'.uniqid();
        DB::table('api_tokens')->insert(['user_id' => $user->id, 'token' => hash('sha256', $plain), 'created_at' => now(), 'updated_at' => now()]);

        return [$user, ['Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json']];
    }

    private function resetCooldown(User $user): void
    {
        DB::table('guests')->where('user_id', $user->id)->update(['profile_last_updated_at' => null]);
    }

    // ================================================================ the six totals

    public function test_the_dashboard_shows_the_six_totals_in_pairs_and_each_links_to_its_tab_and_filter(): void
    {
        $js = file_get_contents(public_path('js/portal/dashboard.js'));
        $stays = file_get_contents(public_path('js/portal/stays.js'));
        // bookings left, reservations right: Active, Past, Cancelled
        $order = ["tile('booking', 'active', 'Active bookings')", "tile('reservation', 'active', 'Active reservations')", "tile('booking', 'completed', 'Past bookings')",
            "tile('reservation', 'completed', 'Past reservations')", "tile('booking', 'cancelled', 'Cancelled bookings')", "tile('reservation', 'cancelled', 'Cancelled reservations')"];
        $at = -1;
        foreach ($order as $piece) {
            $pos = strpos($js, $piece);
            $this->assertNotFalse($pos, $piece);
            $this->assertGreaterThan($at, $pos, "$piece is in order");
            $at = $pos;
        }
        $this->assertStringContainsString("/guest/reservations?tab=' + (tab === 'booking' ? 'bookings' : 'reservations') + '&status=' + cat", $js);
        // the same grouping the app uses: a converted reservation is a Booking and also a finished Reservation; Active holds upcoming stays too
        foreach (["CONVERTED_TO_BOOKING: 'Confirmed'", "COMPLETED_BOOKING: 'Checked-Out'", "x.it.booking) ? 'booking' : 'reservation'", "historical: true", "snapshot_hidden_at", "return 'cancelled'", "return 'completed'"] as $needle) {
            $this->assertStringContainsString($needle, $stays, $needle);
        }
        // the list page reads the tab and the Status filter from the link
        $this->assertStringContainsString("qs.get('tab') === 'reservations'", $stays);
        $this->assertStringContainsString('(active|completed|cancelled)', $stays);
        foreach (['Cancelled / rejected', 'Bookings', 'Reservations', 'Newest first', 'Oldest first', 'Clear filters'] as $text) {
            $this->assertTrue(str_contains($stays, $text) || str_contains(file_get_contents(resource_path('views/portal/stays.blade.php')), $text), $text);
        }
    }

    public function test_the_booking_and_reservation_page_has_the_two_tabs_and_the_old_stays_wording_is_gone(): void
    {
        [$user] = $this->guest();
        $html = $this->actingAs($user)->get(route('guest.reservations.index', ['tab' => 'reservations', 'status' => 'active']))->assertOk()->getContent();
        foreach (['role="tablist"', 'data-tab="booking"', 'data-tab="reservation"', 'id="vsStayFilters"', 'Booking &amp; Reservation'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertStringNotContainsString('My stays', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html, 'the header title is not escaped twice');
        $this->assertStringContainsString('app-header-title fw-bold mb-0 text-truncate" style="min-width: 0;">Booking &amp; Reservation</span>', $html);
    }

    // ================================================================ Profile Management

    public function test_profile_page_has_a_section_per_group_with_edit_and_the_address_widget(): void
    {
        [$user] = $this->guest();
        $html = $this->actingAs($user)->get(route('guest.profile.show'))->assertOk()->getContent();
        foreach (['Profile Management', 'id="vsAddrTpl"', 'data-address-cascade', 'js/address-cascade.js', 'VSAccount.profile'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $js = file_get_contents(public_path('js/portal/account.js'));
        foreach (['Personal information', 'Contact information', "card('Address'", 'Change password', 'Save changes', "'Save these changes?'",
            'First name is required', 'Last name is required', 'Date of birth is required', 'You must be at least 18 years old to create an account.', 'Passwords do not match',
            'Use at least 8 characters with a letter and a number', 'Strength: Weak', 'Strength: Strong', '1 Verify', '2 New password', '3 Done', 'Password updated', 'Profile updated successfully.',
            '/guest/profile/email/request', '/guest/password/change', '/verify-reset-otp', 'Remove your profile photo?'] as $needle) {
            $this->assertStringContainsString($needle, $js, $needle);
        }
        // the old layout's logout form id no longer exists - the password step logs out through the header's form
        $this->assertStringContainsString("getElementById('navLogoutForm')", $js);
        $this->assertStringNotContainsString("getElementById('vsLogoutForm')", $js);
    }

    public function test_each_section_saves_only_its_own_fields_and_the_thirty_day_wait_is_shared(): void
    {
        [$user, $h] = $this->guest();

        // Personal information: the payload the page sends (no address fields) leaves the address and mobile alone
        $this->putJson('/api/guest/profile', ['first_name' => 'Anna', 'middle_name' => null, 'last_name' => 'Cruz', 'gender' => 'female', 'date_of_birth' => '1992-03-04'], $h)->assertOk()
            ->assertJsonPath('user.first_name', 'Anna')->assertJsonPath('guest.address', 'Somewhere')->assertJsonPath('guest.mobile_number', '09170000001')
            ->assertJsonPath('profile_update.can_update', false);
        $this->assertSame((int) now()->diffInYears('1992-03-04', true), (int) DB::table('guests')->where('user_id', $user->id)->value('age'), 'age is recalculated from the date of birth');

        // ... and another section inside the 30 days is refused with the app's date message
        $this->putJson('/api/guest/profile', ['first_name' => 'Anna', 'last_name' => 'Cruz', 'mobile_number' => '09171112222'], $h)->assertStatus(422)
            ->assertJsonPath('profile_update.can_update', false)->assertJsonFragment(['message' => 'You can update your profile again on '.now()->addDays(30)->format('F j, Y').'.']);
        $this->getJson('/api/guest/profile', $h)->assertOk()->assertJsonPath('profile_update.can_update', false);

        // Contact information
        $this->resetCooldown($user);
        $this->putJson('/api/guest/profile', ['first_name' => 'Anna', 'last_name' => 'Cruz', 'mobile_number' => '09171112222'], $h)->assertOk()->assertJsonPath('guest.mobile_number', '09171112222');

        // Address (outside the Philippines the Philippine parts are cleared, the composed text is kept)
        $this->resetCooldown($user);
        $this->putJson('/api/guest/profile', ['first_name' => 'Anna', 'last_name' => 'Cruz', 'country' => 'Singapore', 'address' => 'Singapore', 'region' => null, 'province' => null, 'city' => null, 'barangay' => null, 'street' => null, 'zip_code' => null, 'timezone' => null], $h)
            ->assertOk()->assertJsonPath('guest.country', 'Singapore')->assertJsonPath('guest.address', 'Singapore')->assertJsonPath('guest.region', null);
    }

    public function test_profile_validation_matches_the_app(): void
    {
        [$user, $h] = $this->guest();
        $this->putJson('/api/guest/profile', ['first_name' => '', 'last_name' => 'Cruz'], $h)->assertStatus(422)->assertJsonValidationErrors('first_name');
        $this->putJson('/api/guest/profile', ['first_name' => 'Ana', 'last_name' => ''], $h)->assertStatus(422)->assertJsonValidationErrors('last_name');
        $this->putJson('/api/guest/profile', ['first_name' => 'Ana', 'last_name' => 'Cruz', 'date_of_birth' => now()->addDay()->toDateString()], $h)->assertStatus(422)
            ->assertJsonPath('errors.date_of_birth.0', 'Date of Birth cannot be in the future.');
        $this->putJson('/api/guest/profile', ['first_name' => 'Ana', 'last_name' => 'Cruz', 'mobile_number' => '12345'], $h)->assertStatus(422)->assertJsonValidationErrors('mobile_number');

        // a number that belongs to another account is refused with the app's message
        DB::table('guests')->insert(['user_id' => 999, 'mobile_number' => '09175550000', 'created_at' => now(), 'updated_at' => now()]);
        $this->putJson('/api/guest/profile', ['first_name' => 'Ana', 'last_name' => 'Cruz', 'mobile_number' => '09175550000'], $h)->assertStatus(422)
            ->assertJsonPath('errors.mobile_number.0', 'This mobile number is already registered to another account.');
        $this->assertSame(0, DB::table('guests')->where('user_id', $user->id)->whereNotNull('profile_last_updated_at')->count(), 'a refused save does not start the wait');
    }

    public function test_changing_the_email_needs_the_password_and_a_code_and_the_password_change_needs_a_verified_code(): void
    {
        [$user, $h] = $this->guest('Correct123');
        $this->postJson('/api/guest/profile/email/request', ['new_email' => 'new'.uniqid().'@example.test', 'current_password' => 'nope'], $h)->assertStatus(422)->assertJsonPath('message', 'Incorrect password.');
        $this->postJson('/api/guest/profile/email/confirm', ['otp' => '123456'], $h)->assertStatus(422);
        $before = $user->email;
        $this->putJson('/api/guest/profile', ['first_name' => 'Ana', 'last_name' => 'Cruz', 'email' => 'sneaky@example.test'], $h);
        $this->assertSame($before, $user->refresh()->email, 'the email never changes without the code');
        // the password changes only with the reset token from a verified code
        $this->postJson('/api/guest/password/change', ['reset_token' => 'made-up', 'password' => 'Brandnew123', 'password_confirmation' => 'Brandnew123'], $h)->assertStatus(422);
        $this->assertTrue(Hash::check('Correct123', $user->refresh()->password));
    }

    // ================================================================ shell

    public function test_log_out_is_only_in_the_header_menu_the_label_says_guest_and_the_footer_is_never_fixed(): void
    {
        [$user] = $this->guest();
        $html = $this->actingAs($user)->get(route('guest.dashboard'))->assertOk()->getContent();
        // the side menu has no Log out; the header's account menu does, with the confirmation
        $side = substr($html, strpos($html, '<nav class="d-none d-md-flex sidebar"'), 6000);
        $this->assertStringNotContainsString('Log out', substr($side, 0, strpos($side, '</nav>') ?: 6000));
        $this->assertStringNotContainsString('<span class="link-text">Log out</span>', $html);
        $this->assertStringContainsString('id="navLogoutLink"', $html);
        $this->assertStringContainsString('Log out of Velocity Suites?', $html);
        $this->assertStringContainsString('<div class="sidebar-role-badge fw-semibold">Guest</div>', $html);
        $this->assertStringContainsString('<span class="navbar-role-text">Guest</span>', $html);
        $this->assertStringNotContainsString('Guest/User', $html);
        // the footer is the last item of the scrolling column and the page area fills the space before it
        $this->assertGreaterThan(strpos($html, 'id="main"'), strpos($html, 'class="app-footer"'));
        $css = file_get_contents(public_path('css/portal.css'));
        $this->assertMatchesRegularExpression('/\.vs-shell-main\.vs-page\s*\{[^}]*flex:\s*1 0 auto/', $css);
        $this->assertDoesNotMatchRegularExpression('/\.app-footer\s*\{[^}]*position:\s*(fixed|sticky)/', $css.file_get_contents(public_path('css/app.css')));

        // staff keep their uppercase role label
        $rec = User::create(['first_name' => 'Rec', 'last_name' => 'Label', 'email' => 'rec_label@example.test', 'password' => bcrypt('x'), 'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now()]);
        $this->assertStringContainsString('RECEPTIONIST', strtoupper($this->actingAs($rec)->get('/receptionist/dashboard')->getContent()));
    }

    public function test_the_calendar_page_and_the_notification_filters_follow_the_app(): void
    {
        [$user] = $this->guest();
        $this->actingAs($user)->get(route('guest.calendar'))->assertOk()->assertSee('VSDash.calendarPage', false);
        $js = file_get_contents(public_path('js/portal/account.js'));
        foreach (['All notifications', 'Unread', 'Promotions & discounts', 'Check-in', 'System', "'Mark all as read?'"] as $needle) {
            $this->assertStringContainsString($needle, $js, $needle);
        }
        foreach (['Transaction', 'Booking status', 'Room type', 'Check-in date', 'Check-out date', 'Partially paid', 'Checked out'] as $needle) {
            $this->assertTrue(str_contains($js, $needle) || str_contains(file_get_contents(resource_path('views/portal/history.blade.php')), $needle), $needle);
        }
    }

    // ================================================================ small fixes

    public function test_there_are_no_example_or_preview_pages_that_could_show_made_up_or_real_guest_data(): void
    {
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            $this->assertDoesNotMatchRegularExpression('/example|preview|demo|mock|sample/i', $route->uri(), $route->uri());
        }
        // a transaction page needs its owner: a visitor goes to sign in and another guest cannot read it through the API
        $this->get('/guest/bookings/99')->assertRedirect(route('login'));
        $this->get('/guest/receipts/SR-20261028-000154')->assertRedirect(route('login'));
    }

    // ================================================================ Edit profile: one combined save

    public function test_the_combined_save_counts_the_thirty_days_once_and_saves_all_three_sections_together(): void
    {
        [$user, $h] = $this->guest();
        $body = ['first_name' => 'Anna', 'last_name' => 'Cruz', 'middle_name' => 'M', 'gender' => 'female', 'date_of_birth' => '1992-03-04',
            'mobile_number' => '+6591234567', 'country' => 'Singapore', 'address' => 'Singapore', 'region' => null, 'province' => null, 'city' => null, 'barangay' => null, 'street' => null, 'zip_code' => null];
        $this->putJson('/api/guest/profile/all', $body, $h)->assertOk()
            ->assertJsonPath('user.first_name', 'Anna')->assertJsonPath('guest.mobile_number', '+6591234567')->assertJsonPath('guest.country', 'Singapore')
            ->assertJsonPath('profile_update.can_update', false);
        // the wait started once, for the whole save; a second save is refused with the app's date message and changes nothing
        $this->putJson('/api/guest/profile/all', ['first_name' => 'Other', 'last_name' => 'Name'] , $h)->assertStatus(422)
            ->assertJsonFragment(['message' => 'You can update your profile again on '.now()->addDays(30)->format('F j, Y').'.']);
        $this->assertSame('Anna', $user->refresh()->first_name);
        $this->assertSame(1, DB::table('guests')->where('user_id', $user->id)->whereNotNull('profile_last_updated_at')->count());
    }

    public function test_one_refused_field_in_the_combined_save_saves_nothing_and_does_not_start_the_wait(): void
    {
        [$user, $h] = $this->guest();
        DB::table('guests')->insert(['user_id' => 998, 'mobile_number' => '09175559999', 'created_at' => now(), 'updated_at' => now()]);
        $this->putJson('/api/guest/profile/all', ['first_name' => 'Changed', 'last_name' => 'Cruz', 'mobile_number' => '09175559999'], $h)->assertStatus(422)
            ->assertJsonPath('errors.mobile_number.0', 'This mobile number is already registered to another account.');
        $this->assertSame('Ana', $user->refresh()->first_name, 'the name of the other section was not saved');
        $this->assertNull(DB::table('guests')->where('user_id', $user->id)->value('profile_last_updated_at'));
        $this->putJson('/api/guest/profile/all', ['first_name' => '', 'last_name' => 'Cruz'], $h)->assertStatus(422)->assertJsonValidationErrors('first_name');
    }

    public function test_a_combined_save_with_an_email_change_needs_the_right_code_and_a_wrong_code_saves_nothing(): void
    {
        [$user, $h] = $this->guest('Correct123');
        $new = 'fresh'.uniqid().'@example.test';
        $this->postJson('/api/guest/profile/email/request', ['new_email' => $new, 'current_password' => 'Correct123'], $h)->assertOk();
        DB::table('email_change_requests')->where('user_id', $user->id)->update(['otp' => Hash::make('654321')]);
        $base = ['first_name' => 'Changed', 'last_name' => 'Cruz', 'mobile_number' => '09170000009'];

        $this->putJson('/api/guest/profile/all', $base + ['email_otp' => '111111'], $h)->assertStatus(422)->assertJsonPath('errors.email_otp.0', 'Invalid verification code.');
        $fresh = $user->refresh();
        $this->assertSame('Ana', $fresh->first_name, 'a wrong code rolls the whole save back');
        $this->assertNotSame($new, $fresh->email);
        $this->assertNull(DB::table('guests')->where('user_id', $user->id)->value('profile_last_updated_at'), 'and does not start the wait');
        $this->assertSame('09170000001', DB::table('guests')->where('user_id', $user->id)->value('mobile_number'));

        $this->putJson('/api/guest/profile/all', $base + ['email_otp' => '654321'], $h)->assertOk()->assertJsonPath('email_changed', true)->assertJsonPath('user.email', $new);
        $this->assertSame('Changed', $user->refresh()->first_name);
        $this->assertSame($new, $user->email);
    }

    public function test_the_edit_profile_page_is_one_button_and_one_save_bar(): void
    {
        $js = file_get_contents(public_path('js/portal/account.js'));
        foreach (['id="pEdit"', 'Edit profile', 'id="pSave"', 'id="pCancel"', "/guest/profile/all", "'Save these changes?'", 'You can edit your profile again after 30 days.', 'Nothing was saved.', 'Confirm your password', 'Profile updated successfully.', 'You can edit your profile again on '] as $needle) {
            $this->assertStringContainsString($needle, $js, $needle);
        }
        $this->assertStringNotContainsString('data-edit', $js, 'no per-section Edit buttons any more');
    }

    // ================================================================ stabilization pass

    public function test_edit_reservation_page_cancel_rule_and_wording_follow_the_app(): void
    {
        [$user] = $this->guest();
        $this->get('/guest/reserve/5/edit')->assertRedirect(route('login'));
        $this->actingAs($user)->get(route('guest.reserve.edit', 5))->assertOk()->assertSee('Edit reservation #5', false)->assertSee("VSWizard.init(document.getElementById('vsWizRoot'), 'reservation', 5)", false);
        $js = file_get_contents(public_path('js/portal/wizard.js'));
        foreach (['/guest/reservations/\' + editId', "method: 'PUT', body: editBody()", '/id-card', '/switch-to-', 'Reservation updated', 'Already edited', 'This reservation can no longer be edited'] as $n) {
            $this->assertStringContainsString($n, $js, $n);
        }
        $stays = file_get_contents(public_path('js/portal/stays.js'));
        foreach (['S.canEdit = function', 'S.cancelRule = function', 'Verified by the front desk \u2014 contact us to make changes', 'Your stay has started', "'Keep ' + kind", 'is non-refundable.', 'You can book again at any time.', "label: 'Awaiting verification'"] as $n) {
            $this->assertStringContainsString($n, $stays, $n);
        }
    }
}
