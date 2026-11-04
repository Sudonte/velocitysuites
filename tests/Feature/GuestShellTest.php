<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest web, second pass: the sign-in page keeps its earlier look, sign-up has the brand header and steps, the guest area wears
 * the same shell as the staff roles (sidebar, header, "Log out", notification dropdown), and the Rooms pages carry the date
 * picker, the quantity steppers and the selection that survives sign-in.
 */
class GuestShellTest extends ApiFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'announcements' => function (Blueprint $t) { $t->id(); $t->string('title'); $t->text('content'); $t->json('images')->nullable(); $t->string('status')->default('draft'); $t->date('published_at')->nullable(); $t->json('target_audience')->nullable(); $t->timestamp('notified_at')->nullable(); $t->timestamps(); },
            'api_tokens' => function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('user_id'); $t->string('token')->unique(); $t->string('device_name')->nullable(); $t->timestamp('last_used_at')->nullable(); $t->timestamp('expires_at')->nullable(); $t->timestamps(); },
        ] as $name => $def) {
            if (! Schema::hasTable($name)) {
                Schema::create($name, $def);
            }
        }
        foreach (['failed_login_attempts' => 'integer', 'last_failed_login_at' => 'timestamp', 'last_login_at' => 'timestamp', 'theme' => 'string', 'must_change_password' => 'boolean', 'remember_token' => 'string'] as $col => $type) {
            if (! Schema::hasColumn('users', $col)) {
                Schema::table('users', fn (Blueprint $t) => $t->{$type}($col)->nullable());
            }
        }
    }

    public function test_the_sign_in_page_has_its_earlier_look_and_every_function(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();
        // the earlier design: gradient auth card, logo badge, brand name, "Sign In", icons, Remember me, Register here
        foreach (['auth-card', 'logo-badge', 'Velocity Suites', 'Sign In', 'name="remember"', 'Remember me', 'Forgot password?', 'Register here', 'fa-envelope', 'password-toggle-icon'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        // ... and the functions: the API sign-in, the six-box code screens, the form that still posts for staff
        foreach (['VSAuth.login', 'id="vsLoginForm"', 'id="vsCodePanel"', 'action="'.route('login.post').'"'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $script = file_get_contents(public_path('js/portal/auth.js'));
        foreach (['INVALID_CREDENTIALS', 'Incorrect email or password.', 'ACCOUNT_NOT_VERIFIED', 'ACCOUNT_DEACTIVATED', ">= 3", '/forgot-password?email=', 'remember', '/auth/portal-session'] as $needle) {
            $this->assertStringContainsString($needle, $script, $needle);
        }
    }

    public function test_remember_me_on_the_guest_exchange_sets_the_remember_cookie_and_staff_sign_in_is_unchanged(): void
    {
        [$user] = $this->makeGuestUser('Remember');
        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Test12345!', 'device_name' => 'Web'])->assertOk()->json('token');

        $plain = $this->post('/auth/portal-session', ['token' => $token], ['Accept' => 'application/json']);
        $plain->assertOk();
        $this->assertEmpty(collect($plain->headers->getCookies())->filter(fn ($c) => str_starts_with($c->getName(), 'remember_web_')));

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $kept = $this->post('/auth/portal-session', ['token' => $token, 'remember' => true], ['Accept' => 'application/json']);
        $kept->assertOk();
        $this->assertNotEmpty(collect($kept->headers->getCookies())->filter(fn ($c) => str_starts_with($c->getName(), 'remember_web_')));

        // staff sign in on the same page, one message for a wrong password
        $staff = User::create(['first_name' => 'Rec', 'last_name' => 'Eption', 'email' => 'rec_shell@example.test', 'password' => bcrypt('Staff12345!'), 'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now()]);
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post(route('login.post'), ['email' => $staff->email, 'password' => 'wrong'])->assertSessionHas('error');
        $this->post(route('login.post'), ['email' => $staff->email, 'password' => 'Staff12345!'])->assertRedirect();
    }

    public function test_sign_up_has_the_brand_header_one_card_the_step_line_and_the_progress_bar(): void
    {
        $html = $this->get(route('register'))->assertOk()->getContent();
        foreach (['images/logo.jpg', 'Velocity Suites', 'Your comfort is our service', 'id="vsRegLine"', 'role="progressbar"', 'data-back', 'data-next', 'id="vsRegSubmit"', 'class="auth-card'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertSame(6, substr_count($html, 'data-step'), 'the same six steps');
        $script = file_get_contents(public_path('js/portal/auth.js'));
        $this->assertStringContainsString("'Step ' + (n + 1) + ' of ' + names.length", $script);
        foreach (['Your name', 'About you', 'Contact', 'Address', 'Password and photo', 'Review and terms'] as $title) {
            $this->assertStringContainsString($title, $script);
        }
    }

    public function test_the_guest_area_uses_the_staff_shell_with_the_guest_menu_and_log_out_wording(): void
    {
        [$user] = $this->makeGuestUser('Shell');
        foreach ([route('guest.dashboard'), route('guest.rooms'), route('guest.reservations.index'), route('guest.history'), route('guest.profile.show'), route('guest.notifications.index')] as $url) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            foreach (['app-shell', 'app-header', 'mobileSidebar', 'sidebar'] as $needle) {
                $this->assertStringContainsString($needle, $html, "$needle on $url");
            }
            // the menu, in order
            $order = ['Dashboard', 'Room Browsing', 'Booking &amp; Reservation', 'Transaction History', 'Profile Management'];
            $at = -1;
            foreach ($order as $label) {
                $pos = strpos($html, '<span class="link-text">'.$label.'</span>');
                $this->assertNotFalse($pos, "$label on $url");
                $this->assertGreaterThan($at, $pos, "$label is in order on $url");
                $at = $pos;
            }
            $this->assertStringContainsString('Log out of Velocity Suites?', $html);
            $this->assertStringNotContainsString('<span class="link-text">Log out</span>', $html, 'Log out lives in the header menu only');
            $this->assertStringContainsString('id="navLogoutLink"', $html);
            $this->assertStringNotContainsString('Sign out', $html, $url);
            $this->assertStringNotContainsString('> Logout<', $html, $url);
            // the bell opens a dropdown with Mark all as read and View all notifications
            foreach (['id="vsBell"', 'vs-bell-menu', 'Mark all as read', 'View all notifications', 'js/portal/shell.js'] as $needle) {
                $this->assertStringContainsString($needle, $html, "$needle on $url");
            }
        }
        // the current page is highlighted
        $this->assertMatchesRegularExpression('/nav-link active"[^>]*title="Room Browsing"/', $this->actingAs($user)->get(route('guest.rooms'))->getContent());
        $this->assertMatchesRegularExpression('/nav-link active"[^>]*title="Transaction History"/', $this->actingAs($user)->get(route('guest.history'))->getContent());
        $this->assertMatchesRegularExpression('/nav-link active"[^>]*title="Booking &amp; Reservation"/', $this->actingAs($user)->get(route('guest.reservations.index'))->getContent());
        // visitors still go to sign in
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->get(route('guest.rooms'))->assertRedirect(route('login'));
    }

    public function test_the_staff_pages_use_the_same_log_out_wording_and_keep_their_own_menus(): void
    {
        $rec = User::create(['first_name' => 'Rec', 'last_name' => 'Shell', 'email' => 'rec_wording@example.test', 'password' => bcrypt('x'), 'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now()]);
        $html = $this->actingAs($rec)->get('/receptionist/dashboard')->getContent();
        $this->assertStringContainsString('Log out of Velocity Suites?', $html);
        $this->assertStringContainsString('Log out', $html);
        $this->assertStringNotContainsString('Sign out', $html);
        $this->assertStringNotContainsString('Room Browsing', $html);
        $this->assertStringNotContainsString('vs-bell-menu', $html, 'staff keep their own bell link');
        $this->assertStringContainsString('Check-In', $html);
    }

    public function test_the_rooms_pages_carry_the_date_picker_steppers_and_a_selection_that_survives_sign_in(): void
    {
        $this->makeRoomTypeWithRooms('Deluxe', 3500, 2, 3);
        // the public Rooms page matches the other public pages (same navigation, banner, footer) and has the picker
        $html = $this->get(route('public.rooms.index'))->assertOk()->getContent();
        foreach (['Contact Us', 'section-badge', 'Available <span class="text-brand">Rooms</span>', 'name="check_in"', 'name="check_out"', 'Velocity Suites. All rights reserved', 'data-signed="0"'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertStringContainsString('data-signed="1"', $this->actingAs($this->makeGuestUser('Pub')[0])->get(route('public.rooms.index'))->getContent());

        $script = file_get_contents(public_path('js/portal/rooms.js'));
        foreach (['data-inc', 'data-dec', "' available for these dates.'", 'vs_selection', '30 * 60 * 1000', 'Book now', 'Reserve', 'room type', 'Sign in to continue', '/guest/', "'Only '"] as $needle) {
            $this->assertStringContainsString($needle, $script, $needle);
        }
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        // the landing page's Featured rooms have the same picker, the sign-in returns to the wizard with the rooms, which are re-checked
        $this->assertStringContainsString('vsFeaturedDates', $this->get(route('home'))->getContent());
        $auth = file_get_contents(public_path('js/portal/auth.js'));
        $this->assertStringContainsString("vs_selection", $auth);
        $this->assertStringContainsString("'/new?sel=1'", $auth);
        $wizard = file_get_contents(public_path('js/portal/wizard.js'));
        foreach (['applySelection', 'roomsFor(sv.check_in, sv.check_out)', 'no longer available', 'clearSelection'] as $needle) {
            $this->assertStringContainsString($needle, $wizard, $needle);
        }
    }

    public function test_the_api_the_app_uses_is_unchanged_by_the_shell(): void
    {
        [$user] = $this->makeGuestUser('ApiSame');
        $this->makeRoomTypeWithRooms('Deluxe', 3500, 2, 3);
        $rooms = $this->getJson('/api/rooms?check_in='.now()->addDays(3)->toDateString().'&check_out='.now()->addDays(4)->toDateString())->assertOk();
        $this->assertSame(3, $rooms->json('room_types.data.0.available_count') ?? $rooms->json('room_types.0.available_count'));
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'nope', 'device_name' => 'Pixel'])->assertStatus(401)->assertJsonPath('code', 'INVALID_CREDENTIALS');
    }

    public function test_featured_rooms_load_with_or_without_the_apk_download(): void
    {
        $this->makeRoomTypeWithRooms('Deluxe', 3500, 2, 3);
        foreach (['downloads/this-file-does-not-exist.apk', 'favicon.ico'] as $file) {
            config(['services.mobile_app.file' => $file]);
            $html = $this->get(route('home'))->assertOk()->getContent();
            $this->assertStringContainsString('VSRooms.featured', $html, $file);
            $this->assertStringContainsString('js/portal/rooms.js', $html, $file);
            $this->assertStringContainsString('id="vsFeaturedDates"', $html, $file);
        }
    }

    public function test_the_profile_page_is_a_menu_and_the_theme_is_kept_on_the_account_and_painted_before_the_page(): void
    {
        [$user] = $this->makeGuestUser('Theme');
        $this->actingAs($user)->putJson(route('profile.theme'), ['theme' => 'dark'])->assertSuccessful();
        $this->assertSame('dark', $user->fresh()->theme);
        // the very first tag of the page already carries the theme: no flash of the light page
        $html = $this->actingAs($user->fresh())->get('/guest/profile')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<html lang="en" data-bs-theme="dark"/', $html);
        $this->assertStringContainsString('vsProfile', $html);
        // the profile script builds the menu in this order: Personal, Contact, Address, Theme, Change password, Log out
        $js = file_get_contents(public_path('js/portal/account.js'));
        $order = array_map(fn ($needle) => strpos($js, $needle), ["mi('personal'", "mi('contact'", "mi('address'", 'id="pTheme"', "mi('password'", "mi('logout'"]);
        $this->assertNotContains(false, $order);
        $sorted = $order; sort($sorted);
        $this->assertSame($sorted, $order, 'menu order');
        foreach (['Edit profile', 'Review changes', 'Discard your changes?', 'Log out of Velocity Suites?'] as $text) {
            $this->assertStringContainsString($text, $js, $text);
        }
    }

    public function test_the_booking_and_reservation_page_has_one_new_button_a_tab_summary_and_full_cards(): void
    {
        [$user] = $this->makeGuestUser('Stays4');
        $html = $this->actingAs($user)->get('/guest/reservations')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'id="vsNewBtn"'), 'one new button that follows the tab');
        $this->assertStringContainsString('id="vsStaySummary"', $html);
        $js = file_get_contents(public_path('js/portal/stays.js'));
        foreach (["' active \u00b7 '", "' completed \u00b7 '", "' cancelled'", 'Waiting for verification', 'Waiting for front desk verification', 'Pay at check-in', 'View details', 'data-cancel', 'data-delete', 'Pay now', 'guestsText'] as $needle) {
            $this->assertStringContainsString($needle, $js, $needle);
        }
    }

    public function test_the_receipt_paper_stays_white_with_black_text_in_dark_mode(): void
    {
        $css = file_get_contents(public_path('css/portal.css'));
        $this->assertStringContainsString('[data-bs-theme="dark"] .vs-paper .rcpt-doc td', $css);
        $this->assertMatchesRegularExpression('/\.vs-paper \{ background: #fff; color: #000;/', $css);
        $doc = file_get_contents(resource_path('views/portal/receipt-doc.blade.php'));
        $this->assertStringContainsString('.rcpt-doc .brand { font-size: 1.9em; font-weight: bold; margin: 0; line-height: 1.1; color: #000; }', $doc);
    }

    public function test_a_room_type_whose_standard_rooms_are_full_but_another_option_is_free_is_not_called_fully_booked(): void
    {
        $js = file_get_contents(public_path('js/portal/rooms.js'));
        foreach (["' rooms available</span>'", 'R.otherFree = function', "'<span class=\"vs-chip ok vs-room-avail\">Other ' + VS.esc(rt.name) + ' rooms available</span>'",
            '(stdFull(rt) && R.otherFree(rt))', "|| R.otherFree(r); });", 'See the other '] as $needle) {
            $this->assertStringContainsString($needle, $js, $needle);
        }
        // "Fully booked" stays for the case where every option is full
        $this->assertStringContainsString('<span class="vs-chip bad vs-room-avail">Fully booked</span>', $js);
    }

    public function test_the_dashboard_greets_by_the_full_name_and_support_opens_gmail_with_the_guests_details(): void
    {
        [$user] = $this->makeGuestUser('FullName');
        $user->forceFill(['first_name' => 'John Pauls', 'middle_name' => 'Abe', 'last_name' => 'Ombid'])->save();
        $html = $this->actingAs($user->fresh())->get('/guest/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('"John Pauls Abe Ombid"', $html, 'the full name as saved goes to the greeting');
        $this->assertStringContainsString('js/portal/nextstay.js', $html);

        $dash = file_get_contents(public_path('js/portal/dashboard.js'));
        foreach (["https://mail.google.com/mail/?view=cm&fs=1&to=", "'Support request \u2013 Guest ID '", "velocitysuites@gmail.com", 'Use another email app', "target=\"_blank\" rel=\"noopener noreferrer\"",
            'Member since', 'Email verified', 'Last profile update', 'Next update allowed', 'Edit profile', 'Change password', 'Next booking: '] as $needle) {
            $this->assertStringContainsString($needle, $dash, $needle);
        }
        $ns = file_get_contents(public_path('js/portal/nextstay.js'));
        foreach (['Yesterday', 'Tomorrow', "'Last week'", "'This week'", "'Next week'", 'Show cancelled', 'Your next stay · in ', 'nearestStayDay', 'forWindow', 'This month', 'Nothing booked for this day. Pick another day above.'] as $needle) {
            $this->assertStringContainsString($needle, $ns, $needle);
        }
    }
}
