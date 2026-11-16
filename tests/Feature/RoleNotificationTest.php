<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\User;
use App\Notifications\LibraryWorkflowNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

class RoleNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reservation_notifies_the_active_librarians(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);
        $librarian = User::factory()->create(['role' => 'Thủ thư']);
        $locked = User::factory()->create(['role' => 'Thủ thư', 'is_locked' => true]);
        $reader = User::factory()->create();
        $book = Book::create(['book_code' => 'NOTICE01', 'title' => 'Sách kiểm thử', 'total_quantity' => 2]);

        $this->actingAs($reader)->postJson('/reservations', ['book_id' => $book->id, 'quantity' => 1])
            ->assertCreated();

        $this->assertSame(0, $admin->notifications()->count());
        $this->assertSame(0, $locked->notifications()->count());
        $this->actingAs($librarian)->getJson('/my/notifications')->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('data.0.data.audience', 'Thủ thư')
            ->assertJsonPath('data.0.data.route', 'reservations');
    }

    public function test_inbox_hides_legacy_admin_copies_and_filters_counts_and_read_actions_by_audience(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);
        $legacy = $this->notification($admin, ['route' => 'admin']);
        $formerRole = $this->notification($admin, ['route' => 'payments', 'audience' => 'Thủ thư']);
        $administrator = $this->notification($admin, ['route' => 'admin', 'audience' => 'Admin']);

        $this->actingAs($admin)->getJson('/my/notifications')->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $administrator->id);
        $this->postJson('/my/notifications/'.$legacy->id.'/read')->assertNotFound();
        $this->postJson('/my/notifications/read-all')->assertOk();

        $this->assertNotNull($administrator->fresh()->read_at);
        $this->assertNull($legacy->fresh()->read_at);
        $this->assertNull($formerRole->fresh()->read_at);
    }

    public function test_librarians_and_readers_keep_their_legacy_workflow_notices(): void
    {
        $librarian = User::factory()->create(['role' => 'Thủ thư']);
        $reader = User::factory()->create();
        foreach ([[$librarian, 'librarian-home'], [$reader, 'reservations']] as [$user, $route]) {
            $notice = $this->notification($user, ['route' => $route]);
            $this->actingAs($user)->getJson('/my/notifications')->assertOk()
                ->assertJsonPath('unread_count', 1)
                ->assertJsonPath('data.0.id', $notice->id);
        }
    }

    public function test_only_admin_dashboard_returns_account_totals(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);
        $librarian = User::factory()->create(['role' => 'Thủ thư']);
        User::factory()->create(['is_locked' => true]);

        $this->actingAs($admin)->getJson('/staff/dashboard')->assertOk()
            ->assertJsonPath('staff_accounts', 2)
            ->assertJsonPath('locked_accounts', 1);
        $this->actingAs($librarian)->getJson('/staff/dashboard')->assertOk()
            ->assertJsonMissingPath('staff_accounts')
            ->assertJsonMissingPath('locked_accounts');
    }

    private function notification(User $user, array $data): DatabaseNotification
    {
        return $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => LibraryWorkflowNotification::class,
            'data' => $data + ['title' => 'Thông báo kiểm thử', 'message' => 'Nội dung kiểm thử'],
        ]);
    }
}
