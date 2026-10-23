<?php

namespace Tests\Feature;

use App\Support\MobileApp;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Android app download in the Home page hero: the facts come from one config place, the file is
 * sent as a download, and a missing file hides the button instead of leading to a 404.
 */
class AppDownloadTest extends ApiFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // the Home page also lists announcements (the base test schema has no such table)
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content');
            $table->json('images')->nullable();
            $table->string('status')->default('draft');
            $table->date('published_at')->nullable();
            $table->json('target_audience')->nullable();
            $table->timestamps();
        });
    }

    public function test_hero_offers_the_real_apk_with_its_facts(): void
    {
        $this->assertFileExists(MobileApp::path(), 'the APK must be committed at the configured path');

        $this->get(route('home'))->assertOk()
            ->assertSee('Your comfort is our service')
            ->assertSee('Your stay, in your pocket')
            ->assertSee('Download for Android')
            ->assertSee('href="' . route('app.download') . '"', false)
            ->assertSee('download="velocity-suites-v1.55.0.apk"', false)
            ->assertSee('Version 1.55.0 · 18.2 MB · Android 10 or newer')
            ->assertSee('How to install')
            ->assertSee('Scan to download on your phone')
            ->assertSee('data-app-qr="' . route('app.download') . '"', false)
            ->assertDontSee('The app download is coming soon.');
    }

    public function test_the_size_is_read_from_the_file_and_the_facts_come_from_config(): void
    {
        $this->assertSame(number_format(filesize(MobileApp::path()) / 1048576, 1) . ' MB', MobileApp::sizeLabel());

        config(['services.mobile_app.version' => '9.9.9', 'services.mobile_app.min_android' => 12]);
        $this->assertSame('Version 9.9.9 · ' . MobileApp::sizeLabel() . ' · Android 12 or newer', MobileApp::infoLine());
        $this->assertSame('velocity-suites-v9.9.9.apk', MobileApp::downloadName());
    }

    public function test_the_apk_is_sent_as_an_android_package_download(): void
    {
        $response = $this->get(route('app.download'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.android.package-archive');
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('velocity-suites-v1.55.0.apk', $response->headers->get('Content-Disposition'));
        $this->assertSame(filesize(MobileApp::path()), (int) $response->baseResponse->getFile()->getSize());
    }

    public function test_a_missing_apk_hides_the_button_and_says_coming_soon(): void
    {
        config(['services.mobile_app.file' => 'downloads/not-built-yet.apk', 'services.mobile_app.size_mb' => 21.6]);

        $this->get(route('home'))->assertOk()
            ->assertSee('The app download is coming soon.')
            ->assertDontSee('Download for Android')
            ->assertDontSee('data-app-qr', false)
            ->assertDontSee('How to install');
        $this->get(route('app.download'))->assertNotFound();
        $this->assertSame('21.6 MB', MobileApp::sizeLabel());
    }

    public function test_hero_shows_the_six_app_features_the_version_line_and_the_trust_row(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        foreach ([
            'Book or reserve' => 'Pick your dates and rooms, then book now or reserve and pay later.',
            'Pay with GCash' => 'Pay in the app and upload your receipt for quick verification.',
            'Track every step' => 'See your booking timeline from payment to check-out, updated live.',
            'Request amenities' => 'Ask for extra pillows, towels and more for each of your rooms.',
            'Stay calendar' => 'View your stays by day, week or month at a glance.',
            'Instant updates' => 'Get notified about payments, approvals, promotions and announcements.',
        ] as $title => $line) {
            $this->assertStringContainsString('<strong>' . $title . '</strong><span>' . $line . '</span>', $html);
        }
        $this->assertSame(6, substr_count($html, 'class="hero-feature-icon"'));
        $this->assertStringContainsString('Version 1.55.0 · 18.2 MB · Android 10 or newer', $html);
        foreach (['Free to download', 'Secure GCash payments', 'Android 10 or newer'] as $fact) {
            $this->assertStringContainsString($fact, $html);
        }
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString('Explore rooms', $html);
        $this->assertStringContainsString('Sign in', $html);
        $this->assertStringContainsString('hero-building.webp', $html, 'the hero photo is offered as WebP');
        $this->assertStringContainsString('rel="preload" as="image"', $html);
    }

    public function test_the_phone_mockup_shows_the_real_app_screen(): void
    {
        $this->assertFileExists(public_path('images/app-landing.png'));
        $this->assertFileExists(public_path('images/app-landing.webp'));
        $this->assertLessThan(200 * 1024, filesize(public_path('images/app-landing.webp')));
        $this->assertSame([1080, 2172], array_slice(getimagesize(public_path('images/app-landing.png')), 0, 2));

        $html = $this->get(route('home'))->assertOk()->getContent();
        $this->assertStringContainsString('type="image/webp" srcset="', $html);
        $this->assertStringContainsString('app-landing.webp', $html);
        $this->assertStringContainsString('app-landing.png" width="1080" height="2172" alt="Velocity Suites Android app home screen"', $html);
        $this->assertStringNotContainsString('app-dashboard', $html);
    }

    public function test_the_page_keeps_its_main_actions(): void
    {
        $this->get(route('home'))->assertOk()
            ->assertSee(route('public.rooms.index'), false)
            ->assertSee(route('login'), false);
    }
}
