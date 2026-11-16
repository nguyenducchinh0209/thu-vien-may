<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StaffProfileMigrationTest extends TestCase
{
    public function test_staff_profile_migration_backfills_existing_converted_accounts_only(): void
    {
        $originalDefault = config('database.default');
        $connection = config('database.connections.sqlite');
        $connection['database'] = ':memory:';
        config([
            'database.connections.staff_profile_migration_test' => $connection,
            'database.default' => 'staff_profile_migration_test',
        ]);
        DB::purge('staff_profile_migration_test');

        try {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('email');
                $table->string('role');
            });
            Schema::create('reader_profiles', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->unique();
                $table->string('reader_code', 32)->unique();
                $table->string('phone', 20)->nullable();
                $table->text('address')->nullable();
                $table->date('date_of_birth')->nullable();
                $table->string('avatar_path')->nullable();
            });

            DB::table('users')->insert([
                ['id' => 1, 'name' => 'Converted Staff', 'email' => 'converted@example.test', 'role' => 'Thủ thư'],
                ['id' => 2, 'name' => 'Reader', 'email' => 'reader@example.test', 'role' => 'Độc giả'],
                ['id' => 3, 'name' => 'New Staff', 'email' => 'new@example.test', 'role' => 'Admin'],
            ]);
            DB::table('reader_profiles')->insert([
                ['user_id' => 1, 'reader_code' => 'RD000001', 'phone' => '0901111111', 'address' => 'Old staff address', 'date_of_birth' => '1985-03-04', 'avatar_path' => 'avatars/staff.png'],
                ['user_id' => 2, 'reader_code' => 'RD000002', 'phone' => '0902222222', 'address' => 'Reader address', 'date_of_birth' => '2000-05-06', 'avatar_path' => 'avatars/reader.png'],
            ]);

            $migration = require base_path('database/migrations/2026_10_20_100000_add_staff_profile_fields_to_users_table.php');
            $migration->up();

            $staff = DB::table('users')->where('id', 1)->first();
            $reader = DB::table('users')->where('id', 2)->first();
            $newStaff = DB::table('users')->where('id', 3)->first();
            $readerProfile = DB::table('reader_profiles')->where('user_id', 2)->first();

            $this->assertSame('0901111111', $staff->phone);
            $this->assertSame('Old staff address', $staff->address);
            $this->assertSame('1985-03-04', $staff->date_of_birth);
            $this->assertSame('avatars/staff.png', $staff->avatar_path);
            $this->assertNull($reader->phone);
            $this->assertNull($reader->address);
            $this->assertNull($reader->date_of_birth);
            $this->assertNull($reader->avatar_path);
            $this->assertNull($newStaff->phone);
            $this->assertSame('RD000002', $readerProfile->reader_code);
            $this->assertSame('Reader address', $readerProfile->address);

            try {
                $migration->down();
                $this->fail('The migration must keep columns while profile data exists.');
            } catch (\RuntimeException) {
                $this->assertTrue(Schema::hasColumn('users', 'phone'));
            }

            DB::table('users')->update([
                'phone' => null,
                'address' => null,
                'date_of_birth' => null,
                'avatar_path' => null,
            ]);
            $migration->down();
            $this->assertFalse(Schema::hasColumn('users', 'phone'));
        } finally {
            config(['database.default' => $originalDefault]);
            DB::purge('staff_profile_migration_test');
        }
    }
}
