<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileGenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_reader_can_save_supported_gender_values_and_clear_gender(): void
    {
        $reader = User::factory()->create(['role' => 'Độc giả']);

        foreach (['male', 'female', 'other', 'prefer_not_to_say'] as $gender) {
            $this->actingAs($reader)->patchJson('/my/profile', [
                'name' => $reader->name,
                'email' => $reader->email,
                'gender' => $gender,
            ])->assertOk()->assertJsonPath('profile.gender', $gender);

            $this->assertSame($gender, $reader->fresh()->readerProfile->gender);
        }

        $this->patchJson('/my/profile', [
            'name' => $reader->name,
            'email' => $reader->email,
            'gender' => null,
        ])->assertOk()->assertJsonPath('profile.gender', null);

        $this->assertNull($reader->fresh()->readerProfile->gender);
    }

    public function test_staff_gender_is_preserved_when_the_account_changes_roles(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);
        $staff = User::factory()->create(['role' => 'Thủ thư']);

        $this->actingAs($staff)->patchJson('/my/profile', [
            'name' => $staff->name,
            'email' => $staff->email,
            'gender' => 'prefer_not_to_say',
        ])->assertOk()->assertJsonPath('profile.gender', 'prefer_not_to_say');

        $this->actingAs($admin)->patchJson("/staff/accounts/{$staff->id}", ['role' => 'Độc giả'])
            ->assertOk();
        $this->assertSame('prefer_not_to_say', $staff->fresh()->readerProfile->gender);

        $this->actingAs($staff->fresh())->patchJson('/my/profile', [
            'name' => $staff->name,
            'email' => $staff->email,
            'gender' => null,
        ])->assertOk()->assertJsonPath('profile.gender', null);

        $this->actingAs($admin)->patchJson("/staff/accounts/{$staff->id}", ['role' => 'Thủ thư'])
            ->assertOk();

        $this->assertNull($staff->fresh()->gender);
    }

    public function test_profile_rejects_gender_values_outside_the_supported_list(): void
    {
        $reader = User::factory()->create(['role' => 'Độc giả']);

        $this->actingAs($reader)->patchJson('/my/profile', [
            'name' => $reader->name,
            'email' => $reader->email,
            'gender' => 'undisclosed',
        ])->assertUnprocessable()->assertJsonValidationErrors('gender');

        $this->assertNull($reader->fresh()->readerProfile->gender);
    }
}
