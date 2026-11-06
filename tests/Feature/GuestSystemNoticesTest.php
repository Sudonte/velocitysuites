<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Announcements as the guest website's "System" notifications: audience and date rules, unread per guest, "Updated", archive, no duplicates. */
class GuestSystemNoticesTest extends ApiFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('api_tokens')) {
            Schema::create('api_tokens', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('token')->unique();
                $table->string('device_name')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
            });
        }
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content');
            $table->json('images')->nullable();
            $table->string('status')->default('draft');
            $table->date('published_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->json('target_audience')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('last_edited_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::create('announcement_reads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('announcement_id');
            $table->timestamp('read_at')->nullable();
            $table->unique(['user_id', 'announcement_id']);
        });
    }

    private function asGuest(User $user): static
    {
        $plain = 'sn-token-'.$user->id;
        \Illuminate\Support\Facades\DB::table('api_tokens')->updateOrInsert(['user_id' => $user->id], ['token' => hash('sha256', $plain), 'created_at' => now(), 'updated_at' => now()]);

        return $this->withHeaders(['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json']);
    }

    private function ann(array $o = []): Announcement
    {
        return Announcement::create($o + ['title' => 'Pool maintenance', 'content' => 'The pool is closed on Sunday.', 'status' => 'published', 'published_at' => now()->subDay()->toDateString(), 'target_audience' => ['guest'], 'notified_at' => now()->subDay()]);
    }

    private function notices(User $u): array
    {
        return $this->asGuest($u)->getJson('/api/guest/system-notices')->assertOk()->json();
    }

    public function test_a_live_announcement_for_guests_is_an_unread_system_notification_for_every_guest(): void
    {
        $this->ann();
        [$a] = $this->makeGuestUser('NoticeA');
        [$b] = $this->makeGuestUser('NoticeB');
        foreach ([$a, $b] as $u) {
            $r = $this->notices($u);
            $this->assertCount(1, $r['data']);
            $this->assertSame(1, $r['unread_count']);
            $this->assertSame('Pool maintenance', $r['data'][0]['title']);
            $this->assertSame('The pool is closed on Sunday.', $r['data'][0]['message']);
            $this->assertSame('announcement', $r['data'][0]['category']);
            $this->assertFalse($r['data'][0]['is_read']);
            $this->assertFalse($r['data'][0]['updated']);
        }
    }

    public function test_audience_status_and_date_rules_decide_what_a_guest_sees(): void
    {
        $this->ann(['title' => 'For all (empty audience)', 'target_audience' => null]);
        $this->ann(['title' => 'Staff only', 'target_audience' => ['manager', 'receptionist']]);
        $this->ann(['title' => 'Draft', 'status' => 'draft']);
        $this->ann(['title' => 'Scheduled', 'published_at' => now()->addDays(3)->toDateString()]);
        $this->ann(['title' => 'Expired', 'ends_at' => now()->subDay()->toDateString()]);
        $this->ann(['title' => 'Ends today', 'ends_at' => now()->toDateString()]);
        $this->ann(['title' => 'Archived', 'status' => 'archived', 'archived_at' => now()]);
        $this->ann(['title' => 'Guests + public', 'target_audience' => ['public', 'guest']]);
        [$u] = $this->makeGuestUser('NoticeRules');
        $titles = collect($this->notices($u)['data'])->pluck('title')->sort()->values()->all();
        $this->assertSame(['Ends today', 'For all (empty audience)', 'Guests + public'], $titles);
    }

    public function test_read_unread_and_mark_all_are_kept_per_guest_and_count_in_the_unread_total(): void
    {
        $one = $this->ann(['title' => 'One']);
        $two = $this->ann(['title' => 'Two']);
        [$a] = $this->makeGuestUser('ReadA');
        [$b] = $this->makeGuestUser('ReadB');

        $this->asGuest($a)->putJson("/api/guest/system-notices/{$one->id}/read")->assertOk();
        $ra = $this->notices($a);
        $this->assertSame(1, $ra['unread_count']);
        $this->assertSame(2, $this->notices($b)['unread_count'], 'another guest is unaffected');

        $this->asGuest($a)->putJson("/api/guest/system-notices/{$one->id}/unread")->assertOk();
        $this->assertSame(2, $this->notices($a)['unread_count']);

        $this->asGuest($a)->putJson('/api/guest/system-notices/read-all')->assertOk();
        $this->assertSame(0, $this->notices($a)['unread_count']);
        $this->assertSame(2, $this->notices($b)['unread_count']);
        $this->assertSame(2, \App\Models\AnnouncementRead::where('user_id', $a->id)->count(), 'read-all twice does not duplicate rows');
        $this->asGuest($a)->putJson('/api/guest/system-notices/read-all')->assertOk();
        $this->assertSame(2, \App\Models\AnnouncementRead::where('user_id', $a->id)->count());
    }

    public function test_an_updated_announcement_moves_to_the_top_unread_again_without_a_duplicate(): void
    {
        $old = $this->ann(['title' => 'Old news', 'notified_at' => now()->subDays(3), 'published_at' => now()->subDays(3)->toDateString()]);
        $this->ann(['title' => 'Newer news', 'notified_at' => now()->subDay()]);
        [$u] = $this->makeGuestUser('Updated');
        $this->asGuest($u)->putJson('/api/guest/system-notices/read-all')->assertOk();
        $this->assertSame(0, $this->notices($u)['unread_count']);
        $this->assertSame(['Newer news', 'Old news'], collect($this->notices($u)['data'])->pluck('title')->all());

        $old->forceFill(['title' => 'Old news (revised)', 'last_edited_at' => now()->addMinute()])->save();
        $r = $this->notices($u);
        $this->assertCount(2, $r['data'], 'no duplicate entry');
        $this->assertSame('Old news (revised)', $r['data'][0]['title'], 'moves to the top');
        $this->assertTrue($r['data'][0]['updated']);
        $this->assertFalse($r['data'][0]['is_read']);
        $this->assertSame(1, $r['unread_count']);
        $this->assertTrue($r['data'][1]['is_read'], 'the untouched one stays read');
    }

    public function test_archived_expired_and_unpublished_ones_disappear_and_cannot_be_opened(): void
    {
        $a = $this->ann(['title' => 'Going away']);
        $b = $this->ann(['title' => 'Switched off']);
        $c = $this->ann(['title' => 'Will expire']);
        [$u] = $this->makeGuestUser('Gone');
        $this->assertCount(3, $this->notices($u)['data']);

        $a->archiveRecord();
        $b->update(['status' => 'draft']);
        $c->update(['ends_at' => now()->subDay()->toDateString()]);
        $r = $this->notices($u);
        $this->assertSame([], $r['data']);
        $this->assertSame(0, $r['unread_count']);
        $this->asGuest($u)->putJson("/api/guest/system-notices/{$a->id}/read")->assertNotFound();
    }

    public function test_the_apps_own_notification_list_is_unchanged_and_the_website_does_not_show_the_stored_announcement_row_twice(): void
    {
        $ann = $this->ann(['title' => 'Holiday hours']);
        [$u] = $this->makeGuestUser('NoDupes');
        \App\Models\Notification::create(['user_id' => $u->id, 'title' => 'Holiday hours', 'message' => $ann->content, 'category' => 'announcement', 'is_read' => false]);
        $apiList = $this->asGuest($u)->getJson('/api/notifications')->assertOk()->json('data');
        $this->assertCount(1, $apiList, 'the app still gets its stored row exactly as before');

        $js = file_get_contents(public_path('js/portal/shell.js'));
        $this->assertStringContainsString("String(n.category || '').toLowerCase() !== 'announcement'", $js, 'the web leaves the stored row out and shows the live announcement instead');
        $this->assertStringContainsString('/guest/system-notices', $js);
    }

    public function test_the_admin_status_labels_and_audience_summary(): void
    {
        $this->assertSame('Draft', $this->ann(['status' => 'draft'])->displayStatus());
        $this->assertSame('Scheduled', $this->ann(['published_at' => now()->addDays(2)->toDateString()])->displayStatus());
        $this->assertSame('Published', $this->ann()->displayStatus());
        $this->assertSame('Expired', $this->ann(['ends_at' => now()->subDay()->toDateString()])->displayStatus());
        $arch = $this->ann();
        $arch->archiveRecord();
        $this->assertSame('Archived', $arch->displayStatus());
        $this->assertSame('Guests', $this->ann(['target_audience' => ['guest']])->audienceShort());
        $this->assertSame('Staff', $this->ann(['target_audience' => ['manager', 'receptionist']])->audienceShort());
        $this->assertSame('All', $this->ann(['target_audience' => null])->audienceShort());
        $this->assertSame('Guests + Staff', $this->ann(['target_audience' => ['guest', 'manager']])->audienceShort());
    }

    public function test_the_admin_list_hides_archived_by_default_shows_only_the_important_details_and_editing_marks_it_updated(): void
    {
        $live = $this->ann(['title' => 'Live one', 'content' => 'SECRET-FULL-MESSAGE-TEXT', 'ends_at' => now()->addDays(10)->toDateString()]);
        $gone = $this->ann(['title' => 'Archived one']);
        $gone->archiveRecord();
        $admin = User::create(['first_name' => 'Ad', 'last_name' => 'Min', 'email' => 'admin@ann.test', 'role' => 'admin']);

        $html = $this->actingAs($admin)->get(route('admin.announcements.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Live one', $html);
        $this->assertStringNotContainsString('Archived one', $html);
        $this->assertStringContainsString('ann-chip published', $html);
        $this->assertStringNotContainsString('Last updated</td>', $html);
        $this->assertStringContainsString('<option value="active" selected>', $html);
        // the full message is only in the View data (modal), never printed in the list
        $this->assertStringNotContainsString('<small class="text-muted">SECRET', $html);
        $this->assertStringContainsString('New announcement', $html);

        $this->assertStringContainsString('Archived one', $this->actingAs($admin)->get(route('admin.announcements.index', ['status' => 'archived']))->getContent());
        $all = $this->actingAs($admin)->get(route('admin.announcements.index', ['status' => 'all']))->getContent();
        $this->assertTrue(str_contains($all, 'Live one') && str_contains($all, 'Archived one'));
        $this->assertStringContainsString('ann-chip archived', $all);

        // editing the message stamps last_edited_at (a guest sees it as Updated); changing only the dates or audience does not
        $this->actingAs($admin)->put(route('admin.announcements.update', $live), ['title' => 'Live one', 'content' => 'SECRET-FULL-MESSAGE-TEXT', 'status' => 'published', 'published_at' => now()->subDay()->toDateString(), 'ends_at' => now()->addDays(20)->toDateString(), 'target_audience' => ['guest']])->assertRedirect();
        $this->assertNull($live->fresh()->last_edited_at);
        $this->actingAs($admin)->put(route('admin.announcements.update', $live), ['title' => 'Live one', 'content' => 'A new message', 'status' => 'published', 'published_at' => now()->subDay()->toDateString(), 'ends_at' => '', 'target_audience' => ['guest']])->assertRedirect();
        $this->assertNotNull($live->fresh()->last_edited_at);
        $this->assertNull($live->fresh()->ends_at);
        // the end date cannot be before the publish date
        $this->actingAs($admin)->put(route('admin.announcements.update', $live), ['title' => 'Live one', 'content' => 'A new message', 'status' => 'published', 'published_at' => now()->toDateString(), 'ends_at' => now()->subDays(5)->toDateString()])->assertSessionHasErrors('ends_at');
    }
}
