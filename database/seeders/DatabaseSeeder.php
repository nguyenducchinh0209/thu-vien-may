<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        foreach ([
            ['Quản trị viên', 'admin@library.vn', 'admin12345', 'Admin'],
            ['Lê Thu Hà', 'thuthu@library.vn', 'thuthu123', 'Thủ thư'],
            ['Minh Anh', 'minhanh@example.com', 'docSach123', 'Độc giả'],
        ] as [$name, $email, $password, $role]) {
            User::updateOrCreate(['email' => $email], [
                'name' => $name,
                'password' => $password,
                'role' => $role,
            ]);
        }
    }
}
