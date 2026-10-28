<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/** The app can remove the guest's profile picture: the file goes, the initials come back. */
class ProfilePictureRemoveTest extends ApiFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasColumn('guests', 'profile_picture')) {
            Schema::table('guests', fn (Blueprint $table) => $table->string('profile_picture')->nullable());
        }
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

    public function test_a_guest_uploads_then_removes_their_profile_picture(): void
    {
        Storage::fake('public');
        [$user, $guest] = $this->makeGuestUser('Pic');
        DB::table('api_tokens')->insert(['user_id' => $user->id, 'token' => hash('sha256', 'pic-token'), 'created_at' => now(), 'updated_at' => now()]);
        $auth = ['Authorization' => 'Bearer pic-token', 'Accept' => 'application/json'];

        $this->post('/api/guest/profile/picture', ['profile_picture' => UploadedFile::fake()->image('me.jpg')], $auth)->assertOk();
        $path = $guest->fresh()->profile_picture;
        $this->assertNotEmpty($path);
        Storage::disk('public')->assertExists($path);

        $this->delete('/api/guest/profile/picture', [], $auth)->assertOk()->assertJsonPath('message', 'Profile photo removed.');
        $this->assertNull($guest->fresh()->profile_picture);
        Storage::disk('public')->assertMissing($path);

        // removing again is harmless
        $this->delete('/api/guest/profile/picture', [], $auth)->assertOk();
        // and it needs the guest's own token
        $this->delete('/api/guest/profile/picture', [], ['Accept' => 'application/json'])->assertStatus(401);
    }
}
