<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/** Sign-up accepts only Gmail addresses (case and spaces ignored); malformed ones are rejected too. */
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

    public function test_only_gmail_addresses_can_sign_up_and_the_message_names_the_rule(): void
    {
        foreach (['ana@yahoo.com', 'ana.reyes@outlook.ph', 'team@mail.example.co.uk', 'ana@hotel.com', 'ana@gmail.com.ph', 'ana@notgmail.com'] as $email) {
            $this->postJson('/api/register', $this->payload($email))->assertStatus(422)
                ->assertJsonPath('errors.email.0', 'Please use a Gmail address (@gmail.com) to create your account.');
        }
    }

    public function test_gmail_passes_whatever_the_case_or_spaces_and_malformed_addresses_are_rejected(): void
    {
        foreach (['ana@gmail.com', '  Ana.Reyes@GMAIL.com '] as $email) {
            $ok = $this->postJson('/api/register', $this->payload($email));
            $this->assertArrayNotHasKey('email', $ok->json('errors') ?? [], $email);
        }
        $this->assertDatabaseHas('registration_otps', ['email' => 'ana.reyes@gmail.com']);

        foreach (['plain', 'a@', '@example.com', 'a b@example.com'] as $bad) {
            $this->postJson('/api/register', $this->payload($bad))->assertStatus(422)->assertJsonValidationErrors('email');
        }
    }
}
