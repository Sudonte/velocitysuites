<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;

/**
 * Notifications are archived, never deleted: Archive moves one out of the
 * inbox (and marks it read), Restore brings it back, Archive All Read moves
 * every read one, and there is no delete route any more.
 */
class NotificationArchiveTest extends ApiFlowTestCase
{
    private function staff(string $role): User
    {
        return User::create([
            'first_name' => 'N', 'last_name' => ucfirst($role), 'email' => "{$role}-archive@example.test",
            'password' => bcrypt('x'), 'role' => $role, 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    private function note(User $user, bool $read = false): Notification
    {
        return Notification::create(['user_id' => $user->id, 'title' => 'T', 'message' => 'M', 'category' => 'general', 'is_read' => $read]);
    }

    public function test_archive_restore_and_archive_all_read(): void
    {
        $user = $this->staff('receptionist');
        $unread = $this->note($user);
        $read = $this->note($user, true);
        $this->actingAs($user);

        $this->put(route('notifications.archive', $unread))->assertRedirect();
        $unread->refresh();
        $this->assertNotNull($unread->archived_at);
        $this->assertTrue($unread->is_read);
        $this->assertSame(0, Notification::unreadCountFor($user->id));

        $this->get(route('notifications.index'))->assertOk()
            ->assertViewHas('notifications', fn ($n) => $n->pluck('id')->all() === [$read->id]);
        $this->get(route('notifications.index', ['view' => 'archived']))->assertOk()
            ->assertViewHas('notifications', fn ($n) => $n->pluck('id')->all() === [$unread->id]);

        $this->put(route('notifications.restore', $unread))->assertRedirect();
        $this->assertNull($unread->fresh()->archived_at);

        $this->put(route('notifications.archiveAllRead'))->assertRedirect();
        $this->assertSame(2, Notification::where('user_id', $user->id)->whereNotNull('archived_at')->count());
        $this->assertSame(2, Notification::where('user_id', $user->id)->count(), 'nothing is deleted');
    }

    public function test_cannot_archive_someone_elses_notification_and_delete_is_gone(): void
    {
        $owner = $this->staff('manager');
        $other = $this->staff('receptionist');
        $n = $this->note($owner);

        $this->actingAs($other)->put(route('notifications.archive', $n))->assertForbidden();
        $status = $this->actingAs($owner)->delete('/manager/notifications/' . $n->id)->status();
        $this->assertContains($status, [404, 405], 'no delete route');
        $this->assertNotNull($n->fresh());
    }
}
