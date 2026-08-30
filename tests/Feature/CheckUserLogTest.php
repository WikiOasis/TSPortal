<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CheckUserCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\SignsWikiRequests;
use Tests\TestCase;

class CheckUserLogTest extends TestCase
{
    use RefreshDatabase;
    use SignsWikiRequests;

    private function staff(): User
    {
        return User::create([
            'username' => 'Reviewer',
            'mw_central_id' => 900,
            'flags' => [User::FLAG_TS],
            'active' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function check(int $logId, array $overrides = []): array
    {
        return array_replace_recursive([
            'log_id' => $logId,
            'checked_at' => '2026-08-01T10:00:00+00:00',
            'checker' => ['username' => 'Marisol Kane', 'central_id' => 41],
            'type' => 'userips',
            'target' => ['kind' => 'account', 'name' => 'Halcyon Reed', 'fingerprint' => 'abc123def4567890'],
            'reason' => 'Cross-wiki sockpuppetry, see TS-2026-0481',
            'reason_given' => true,
        ], $overrides);
    }

    private function push(array $checks, array $extra = []): TestResponse
    {
        return $this->wikiPost('/api/wiki/v1/checkuser-checks', [
            'wiki' => 'oasiswiki',
            'targets' => 'accounts',
            'checks' => $checks,
        ] + $extra);
    }

    #[Test]
    public function a_batch_of_checks_is_stored(): void
    {
        $this->push([$this->check(1), $this->check(2)])
            ->assertStatus(202)
            ->assertJsonPath('stored', 2)
            ->assertJsonPath('through', 2);

        $check = CheckUserCheck::query()->where('log_id', 1)->sole();

        $this->assertSame('oasiswiki', $check->wiki);
        $this->assertSame('Marisol Kane', $check->checker_username);
        $this->assertSame(41, $check->checker_central_id);
        $this->assertSame('userips', $check->type);
        $this->assertTrue($check->reason_given);
    }

    #[Test]
    public function the_same_batch_twice_leaves_one_row_per_check(): void
    {
        $this->push([$this->check(1), $this->check(2)])->assertStatus(202);

        $this->push([$this->check(1), $this->check(2)])
            ->assertStatus(202)
            ->assertJsonPath('stored', 0)
            ->assertJsonPath('updated', 2);

        $this->assertSame(2, CheckUserCheck::query()->count());
    }

    #[Test]
    public function a_wiki_resending_with_targets_turned_up_fills_them_in(): void
    {
        $this->push([$this->check(1, ['target' => ['kind' => 'ip', 'name' => null]])]);

        $this->assertNull(CheckUserCheck::query()->sole()->target_name);

        $this->push([$this->check(1, ['target' => ['kind' => 'ip', 'name' => '198.51.100.7']])], ['targets' => 'all']);

        $this->assertSame('198.51.100.7', CheckUserCheck::query()->sole()->target_name);
    }

    #[Test]
    public function whether_a_reason_was_given_is_recomputed_rather_than_believed(): void
    {
        $this->push([$this->check(1, ['reason' => '   ', 'reason_given' => true])]);

        $check = CheckUserCheck::query()->sole();

        $this->assertFalse($check->reason_given);
        $this->assertNull($check->reason);
    }

    #[Test]
    public function an_unreadable_row_is_skipped_rather_than_failing_the_batch(): void
    {
        $this->push([
            $this->check(1),
            ['log_id' => 0, 'checked_at' => '2026-08-01T10:00:00+00:00'],
            ['log_id' => 5, 'checked_at' => 'not a date'],
            $this->check(3),
        ])
            ->assertStatus(202)
            ->assertJsonPath('stored', 2)
            ->assertJsonPath('skipped', 2);
    }

    #[Test]
    public function a_batch_writes_one_audit_line_and_not_one_per_check(): void
    {
        $this->push([$this->check(1), $this->check(2), $this->check(3)]);

        $entry = AuditLog::query()->where('action', 'checkuser.received')->sole();

        $this->assertSame(3, $entry->meta['stored']);
        $this->assertSame('oasiswiki', $entry->meta['wiki']);
        $this->assertSame('accounts', $entry->meta['targets']);
    }

    #[Test]
    public function an_unsigned_push_is_refused(): void
    {
        $this->postJson('/api/wiki/v1/checkuser-checks', ['checks' => []])
            ->assertStatus(401);
    }

    #[Test]
    public function the_log_is_readable_by_trust_and_safety(): void
    {
        $this->push([
            $this->check(1),
            $this->check(2, ['reason' => '', 'reason_given' => false]),
        ]);

        $this->actingAs($this->staff())->getJson('/api/portal/checkuser')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.unexplained', 1);
    }

    #[Test]
    public function the_checks_with_no_reason_can_be_asked_for_on_their_own(): void
    {
        $this->push([
            $this->check(1),
            $this->check(2, ['reason' => null, 'reason_given' => false]),
        ]);

        $this->actingAs($this->staff())->getJson('/api/portal/checkuser?unexplained=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.log_id', 2);
    }

    #[Test]
    public function an_ip_target_reads_as_a_kind_and_a_fingerprint(): void
    {
        $this->push([$this->check(1, [
            'target' => ['kind' => 'ip', 'name' => null, 'fingerprint' => 'abcdef0123456789'],
        ])]);

        $this->actingAs($this->staff())->getJson('/api/portal/checkuser')
            ->assertOk()
            ->assertJsonPath('data.0.target', 'An IP address (abcdef01)')
            ->assertJsonPath('data.0.fingerprint', 'abcdef01')
            ->assertJsonPath('data.0.target_name', null);
    }

    #[Test]
    public function a_target_can_be_searched_by_the_fingerprint_shown_on_screen(): void
    {
        $this->push([
            $this->check(1, ['target' => ['kind' => 'ip', 'name' => null, 'fingerprint' => 'abcdef0123456789']]),
            $this->check(2, ['target' => ['kind' => 'ip', 'name' => null, 'fingerprint' => '9876543210fedcba']]),
        ]);

        $this->actingAs($this->staff())->getJson('/api/portal/checkuser?target=abcdef01')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.log_id', 1);
    }

    #[Test]
    public function somebody_without_the_flag_cannot_read_it(): void
    {
        $outsider = User::create([
            'username' => 'Passer By',
            'mw_central_id' => 901,
            'flags' => [],
            'active' => true,
        ]);

        $this->actingAs($outsider)->getJson('/api/portal/checkuser')->assertForbidden();
    }
}
