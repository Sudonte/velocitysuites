<?php

namespace Tests\Feature;

use App\Models\Manager;
use App\Models\Receptionist;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Receptionist and manager accounts carry personal details (home address,
 * date of birth & gender, contact number) in their own tables, referencing
 * the user account - entered by the admin when the account is created.
 */
class StaffPersonalDetailsTest extends ApiFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('last_login_at')->nullable();
            $table->string('remember_token', 100)->nullable();
        });
        foreach (['receptionists', 'managers'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->unique();
                $table->string('street')->nullable();
                $table->string('barangay')->nullable();
                $table->string('city')->nullable();
                $table->string('province')->nullable();
                $table->date('date_of_birth')->nullable();
                $table->string('gender')->nullable();
                $table->string('mobile_number', 20)->nullable()->unique();
                $table->timestamps();
            });
        }
    }

    private function admin(): User
    {
        return User::create([
            'first_name' => 'Pedro', 'last_name' => 'Reyes', 'email' => 'admin-staff@example.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    private function details(array $extra = []): array
    {
        return array_merge([
            'street' => '12 Rizal St', 'barangay' => 'Zone 3', 'city' => 'Koronadal City', 'province' => 'South Cotabato',
            'date_of_birth' => '1996-04-12', 'gender' => 'female', 'mobile_number' => '09171112222',
        ], $extra);
    }

    public function test_creating_a_receptionist_stores_personal_details_in_the_receptionists_table(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.users.create'))->assertOk()
            ->assertSee('Home Address')->assertSee('Date of Birth')->assertSee('Contact Details');

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'first_name' => 'Maria', 'last_name' => 'Santos', 'middle_name' => 'Cruz', 'role' => 'receptionist',
        ] + $this->details())->assertSessionHasNoErrors()->assertRedirect(route('admin.users.index'));

        $user = User::where('role', 'receptionist')->firstOrFail();
        $profile = Receptionist::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('Koronadal City', $profile->city);
        $this->assertSame('1996-04-12', $profile->date_of_birth->toDateString());
        $this->assertSame('09171112222', $profile->mobile_number);
        $this->assertSame(0, Manager::count());
        $this->assertTrue($user->staff_profile->is($profile));
    }

    public function test_personal_details_are_required_and_validated(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.users.store'), [
            'first_name' => 'Carlos', 'last_name' => 'Ramos', 'role' => 'manager',
        ])->assertSessionHasErrors(['street', 'barangay', 'city', 'province', 'date_of_birth', 'gender', 'mobile_number']);

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'first_name' => 'Carlos', 'last_name' => 'Ramos', 'role' => 'manager',
        ] + $this->details(['mobile_number' => '1234', 'date_of_birth' => now()->subYears(16)->toDateString()]))
            ->assertSessionHasErrors(['mobile_number', 'date_of_birth']);
        $this->assertSame(0, User::where('role', 'manager')->count());
    }

    public function test_editing_a_manager_updates_their_personal_details_and_the_pages_show_them(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.users.store'), [
            'first_name' => 'Carlos', 'last_name' => 'Ramos', 'role' => 'manager',
        ] + $this->details(['gender' => 'male', 'mobile_number' => '09183334444']))->assertSessionHasNoErrors();
        $manager = User::where('role', 'manager')->firstOrFail();

        $this->actingAs($admin)->put(route('admin.users.update', $manager), [
            'first_name' => 'Carlos', 'last_name' => 'Ramos', 'status' => 'active',
        ] + $this->details(['gender' => 'male', 'mobile_number' => '09183334444', 'city' => 'Tupi']))->assertSessionHasNoErrors();

        $this->assertSame('Tupi', Manager::where('user_id', $manager->id)->value('city'));
        $this->actingAs($admin)->get(route('admin.users.show', $manager))->assertOk()
            ->assertSee('12 Rizal St, Zone 3, Tupi, South Cotabato')->assertSee('09183334444');
    }
}
