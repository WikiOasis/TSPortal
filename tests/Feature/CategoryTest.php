<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CaseCategory;
use App\Models\OutboundEvent;
use App\Models\SafetyCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\SignsWikiRequests;
use Tests\TestCase;

class CategoryTest extends TestCase
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

    /** @param list<array<string, mixed>> $categories */
    private function submit(array $categories, array $extra = []): SafetyCase
    {
        $this->wikiPost('/api/wiki/v1/submissions', [
            'type' => 'report',
            'wiki' => 'oasiswiki',
            'reporter' => ['central_id' => 7, 'username' => 'Halcyon Reed'],
            'answers' => ['report' => 'harassment'],
            'categories' => $categories,
        ] + $extra)->assertStatus(201);

        return SafetyCase::query()->latest('id')->firstOrFail();
    }

    #[Test]
    public function a_submission_carries_its_categories_through(): void
    {
        $case = $this->submit([
            ['id' => 'harassment', 'label' => 'Harassment', 'group' => 'conduct', 'field' => 'report'],
            ['id' => 'threat-of-physical-harm', 'label' => 'Threat of physical harm', 'group' => 'harm'],
        ]);

        $this->assertSame('harassment', $case->category);
        $this->assertSame('conduct', $case->category_group);
        $this->assertSame(2, $case->categories()->count());

        $primary = $case->categories()->where('is_primary', true)->sole();
        $this->assertSame('harassment', $primary->category);
        $this->assertSame('report', $primary->source_field);
    }

    #[Test]
    public function a_report_can_be_more_than_one_thing(): void
    {
        $case = $this->submit([
            ['id' => 'harassment', 'label' => 'Harassment'],
            ['id' => 'doxxing', 'label' => 'Disclosure of personal information'],
        ]);

        $this->assertEqualsCanonicalizing(
            ['harassment', 'doxxing'],
            $case->categories()->pluck('category')->all(),
        );
    }

    #[Test]
    public function a_submission_with_no_categories_is_honestly_uncategorised(): void
    {
        $case = $this->submit([]);

        $this->assertNull($case->category);
        $this->assertSame(0, $case->categories()->count());
    }

    #[Test]
    public function two_wikis_naming_one_thing_differently_are_reconciled(): void
    {
        config()->set('categories.aliases', ['a-harassment-issue' => 'harassment']);

        $case = $this->submit([
            ['id' => 'a-harassment-issue', 'label' => 'A harassment issue'],
        ]);

        $this->assertSame('harassment', $case->category);
        $this->assertSame('A harassment issue', $case->categories()->sole()->label);
    }

    #[Test]
    public function the_same_category_twice_is_stored_once(): void
    {
        config()->set('categories.aliases', ['harassment-2' => 'harassment']);

        $case = $this->submit([
            ['id' => 'harassment', 'label' => 'Harassment'],
            ['id' => 'harassment-2', 'label' => 'Harassment (old wording)'],
        ]);

        $this->assertSame(1, $case->categories()->count());
    }

    #[Test]
    public function a_category_with_no_label_still_reads_as_something(): void
    {
        $case = $this->submit([['id' => 'harassment']]);

        $this->assertSame('harassment', $case->categories()->sole()->label);
    }

    #[Test]
    public function the_mirror_is_told_what_a_case_was_filed_under(): void
    {
        $case = $this->submit([['id' => 'harassment', 'label' => 'Harassment']]);

        $event = OutboundEvent::query()
            ->where('event', OutboundEvent::CASE_UPSERT)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(
            [['id' => 'harassment', 'label' => 'Harassment']],
            $event->payload['categories'],
        );
        $this->assertSame($case->reference, $event->payload['reference']);
    }

    #[Test]
    public function a_reviewer_can_correct_a_categorisation(): void
    {
        $case = $this->submit([['id' => 'something-else', 'label' => 'Something else']]);
        $staff = $this->staff();

        $this->actingAs($staff)
            ->putJson("/api/portal/cases/{$case->id}/categories", [
                'categories' => [
                    ['id' => 'threat-of-physical-harm', 'label' => 'Threat of physical harm'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.category', 'threat-of-physical-harm');

        $case->refresh();

        $this->assertSame(['threat-of-physical-harm'], $case->categories()->pluck('category')->all());

        $entry = AuditLog::query()->where('action', 'case.categorised')->sole();
        $this->assertSame(['something-else'], $entry->meta['from']);
        $this->assertSame(['threat-of-physical-harm'], $entry->meta['to']);
    }

    #[Test]
    public function everything_can_be_taken_off(): void
    {
        $case = $this->submit([['id' => 'harassment', 'label' => 'Harassment']]);

        $this->actingAs($this->staff())
            ->putJson("/api/portal/cases/{$case->id}/categories", ['categories' => []])
            ->assertOk();

        $this->assertNull($case->refresh()->category);
        $this->assertSame(0, $case->categories()->count());
    }

    #[Test]
    public function the_queue_can_be_narrowed_to_a_category(): void
    {
        $this->submit([['id' => 'harassment', 'label' => 'Harassment']]);
        $this->submit([['id' => 'spam', 'label' => 'Spam']]);
        $this->submit([]);

        $staff = $this->staff();

        $this->actingAs($staff)->getJson('/api/portal/cases?category=harassment')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($staff)->getJson('/api/portal/cases?category=none')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function a_case_filed_under_two_things_appears_under_either(): void
    {
        $this->submit([
            ['id' => 'harassment', 'label' => 'Harassment'],
            ['id' => 'doxxing', 'label' => 'Doxxing'],
        ]);

        $staff = $this->staff();

        foreach (['harassment', 'doxxing'] as $category) {
            $this->actingAs($staff)->getJson("/api/portal/cases?category={$category}")
                ->assertOk()
                ->assertJsonCount(1, 'data');
        }
    }

    #[Test]
    public function an_id_is_compared_in_one_case_however_it_was_written(): void
    {
        $case = $this->submit([['id' => '  Harassment ', 'label' => 'Harassment']]);

        $this->assertSame('harassment', $case->category);
        $this->assertSame('harassment', CaseCategory::canonical('  HARASSMENT '));
    }
}
