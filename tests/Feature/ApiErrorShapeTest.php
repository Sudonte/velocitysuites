<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Guest API errors are plain and safe: right status code, no internal class names, no stack trace. */
class ApiErrorShapeTest extends ApiFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('api_tokens')) {
            Schema::create('api_tokens', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('token')->unique();
                $table->string('device_name')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function test_a_missing_booking_is_a_plain_404_that_does_not_name_the_internal_model(): void
    {
        [$user] = $this->makeGuestUser('ES'.uniqid());
        DB::table('api_tokens')->insert(['user_id' => $user->id, 'token' => hash('sha256', 'es-token'), 'created_at' => now(), 'updated_at' => now()]);

        foreach (['/api/guest/bookings/99999999', '/api/guest/reservations/99999999'] as $url) {
            $response = $this->withHeaders(['Authorization' => 'Bearer es-token'])->getJson($url)->assertStatus(404);
            $this->assertSame('Not found.', $response->json('message'));
            $this->assertStringNotContainsString('App\Models', $response->getContent());
            $this->assertArrayNotHasKey('trace', $response->json());
        }
    }

    public function test_the_guest_lists_need_a_token(): void
    {
        $this->getJson('/api/guest/bookings')->assertStatus(401);
        $this->getJson('/api/guest/reservations')->assertStatus(401);
    }
}
