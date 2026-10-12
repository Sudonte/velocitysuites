<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/** Registration accepts any well-formed email address, not only Gmail; malformed ones are still rejected. */
class AnyEmailRegistrationTest extends ApiFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('registration_otps', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('otp', 6);
            $table->json('payload');
            $table->timestamp('expires_at');
            $table->timestamps();
        });
        Mail::fake();
    }

    private function payload(string $email): array
    {
        return [
            'first_name' => 'Ana', 'last_name' => 'Reyes', 'email' => $email,
            'password' => 'Str0ngPass!', 'password_confirmation' => 'Str0ngPass!',
            'age' => 30, 'gender' => 'female', 'date_of_birth' => '1996-01-01',
            'mobile_number' => '09171234567', 'address' => '1 Test St', 'country' => 'Philippines',
            'region' => 'NCR', 'city' => 'Manila', 'barangay' => 'Ermita', 'street' => '1 Test St',
            'otp_channel' => 'email',
        ];
    }

    public function test_non_gmail_addresses_pass_email_validation(): void
    {
        foreach (['ana@yahoo.com', 'ana.reyes@outlook.ph', 'team@mail.example.co.uk'] as $email) {
            $response = $this->postJson('/api/register', $this->payload($email));
            $this->assertArrayNotHasKey('email', $response->json('errors') ?? [], "$email was rejected: ".$response->getContent());
        }
    }

    public function test_gmail_still_passes_and_malformed_addresses_are_rejected(): void
    {
        $ok = $this->postJson('/api/register', $this->payload('ana@gmail.com'));
        $this->assertArrayNotHasKey('email', $ok->json('errors') ?? []);

        foreach (['plain', 'a@', '@example.com', 'a b@example.com'] as $bad) {
            $this->postJson('/api/register', $this->payload($bad))->assertStatus(422)->assertJsonValidationErrors('email');
        }
    }
}
