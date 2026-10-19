<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Existing demo accounts are preserved when seeding again.

        foreach ([
            ['Quản trị viên', 'admin@library.vn', 'admin12345', 'Admin'],
            ['Lê Thu Hà', 'thuthu@library.vn', 'thuthu123', 'Thủ thư'],
            ['Minh Anh', 'minhanh@example.com', 'docSach123', 'Độc giả'],
        ] as [$name, $email, $password, $role]) {
            User::firstOrCreate(['email' => $email], [
                'name' => $name,
                'password' => $password,
                'role' => $role,
            ]);
        }

        $this->call(LibrarySeeder::class);
    }
}
