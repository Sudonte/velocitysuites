<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/** Creating an account needs a holder who is 18 or older (judged from the date of birth) and a password of 8+ characters with a letter and a number. */
class RegistrationRulesTest extends ApiFlowTestCase
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

    private function payload(array $over = []): array
    {
        return array_merge([
            'first_name' => 'Ana', 'last_name' => 'Reyes', 'email' => 'ana'.uniqid().'@example.com',
            'password' => 'Str0ngPass', 'password_confirmation' => 'Str0ngPass',
            'age' => 30, 'gender' => 'female', 'date_of_birth' => now('Asia/Manila')->subYears(30)->toDateString(),
            'mobile_number' => '09171234567', 'address' => '1 Test St', 'country' => 'Philippines',
            'region' => 'NCR', 'city' => 'Manila', 'barangay' => 'Ermita', 'street' => '1 Test St', 'otp_channel' => 'email',
        ], $over);
    }

    public function test_someone_under_18_is_rejected_even_if_they_send_an_adult_age(): void
    {
        $dob = now('Asia/Manila')->subYears(17)->toDateString();
        $res = $this->postJson('/api/register', $this->payload(['date_of_birth' => $dob, 'age' => 17]));
        $res->assertStatus(422)->assertJsonValidationErrors('date_of_birth');
        $this->assertStringContainsString('18 or older', $res->json('errors.date_of_birth.0'));

        // sending a made-up adult age with an under-18 date of birth cannot get through either
        $this->postJson('/api/register', $this->payload(['date_of_birth' => $dob, 'age' => 30]))->assertStatus(422);
    }

    public function test_someone_who_turned_18_today_is_accepted(): void
    {
        $dob = now('Asia/Manila')->subYears(18)->toDateString();
        $res = $this->postJson('/api/register', $this->payload(['date_of_birth' => $dob, 'age' => 18]));
        $this->assertArrayNotHasKey('date_of_birth', $res->json('errors') ?? [], $res->getContent());
        $this->assertArrayNotHasKey('age', $res->json('errors') ?? [], $res->getContent());
    }

    public function test_the_password_needs_8_characters_a_letter_and_a_number(): void
    {
        foreach (['short1A' => 'short', 'allletters' => 'numbers', '12345678' => 'letters', 'abc12' => 'short'] as $weak => $why) {
            $res = $this->postJson('/api/register', $this->payload(['password' => $weak, 'password_confirmation' => $weak]));
            $res->assertStatus(422)->assertJsonValidationErrors('password');
        }
        $this->assertStringContainsString('letter', implode(' ', $this->postJson('/api/register', $this->payload(['password' => '12345678', 'password_confirmation' => '12345678']))->json('errors.password')));
        $this->assertStringContainsString('number', implode(' ', $this->postJson('/api/register', $this->payload(['password' => 'abcdefgh', 'password_confirmation' => 'abcdefgh']))->json('errors.password')));

        $ok = $this->postJson('/api/register', $this->payload(['password' => 'abcdefg1', 'password_confirmation' => 'abcdefg1']));
        $this->assertArrayNotHasKey('password', $ok->json('errors') ?? [], $ok->getContent());
    }

    public function test_signing_in_before_verifying_says_so_only_with_the_right_password(): void
    {
        $email = 'pending'.uniqid().'@example.com';
        \App\Models\RegistrationOtp::create(['email' => $email, 'otp' => '123456', 'payload' => ['first_name' => 'Ana', 'password' => 'Str0ngPass'], 'expires_at' => now()->addMinutes(10)]);

        // the right password of the unfinished sign-up: told it is not verified yet (and the app offers a new code)
        $this->postJson('/api/login', ['email' => $email, 'password' => 'Str0ngPass'])->assertStatus(401)->assertJson(['code' => 'ACCOUNT_NOT_VERIFIED']);

        // anyone else gets the one shared answer, exactly like an unknown address
        $wrong = $this->postJson('/api/login', ['email' => $email, 'password' => 'Wrong1234']);
        $unknown = $this->postJson('/api/login', ['email' => 'nobody'.uniqid().'@example.com', 'password' => 'Wrong1234']);
        $wrong->assertStatus(401)->assertJson(['code' => 'INVALID_CREDENTIALS']);
        $this->assertSame($wrong->getContent(), $unknown->getContent());
    }
}
