<?php

namespace Tests\Feature\Writing;

use App\Jobs\Writing\ProcessWritingAttempt;
use App\Models\User;
use App\Models\WritingAttempt;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `writing` was a declared skill with nothing behind it. These tests cover the
 * path that fills that gap, and in particular the rule that makes photographed
 * paper practice safe to mark: a machine's reading of someone's handwriting is
 * a claim, not a fact, and it is not scored until they say it is right.
 */
class WritingAttemptTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
        Queue::fake();
        $this->user = User::factory()->create();
    }

    public function test_typed_writing_is_accepted_and_queued_for_marking(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/writing/attempts', [
            'text' => 'Yesterday I go to the shop and buyed some bread.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.source', WritingAttempt::SOURCE_TYPED)
            ->assertJsonPath('data.status', WritingAttempt::STATUS_PENDING)
            ->assertJsonPath('data.word_count', 10);

        Queue::assertPushed(ProcessWritingAttempt::class);
    }

    public function test_typed_text_is_authoritative_immediately(): void
    {
        // Nobody needs to confirm what they typed themselves.
        $this->actingAs($this->user)->postJson('/api/v1/writing/attempts', [
            'text' => 'I have lived here since three years.',
        ])->assertCreated();

        $attempt = WritingAttempt::first();

        $this->assertTrue($attempt->text_confirmed);
        $this->assertTrue($attempt->textIsAuthoritative());
    }

    public function test_a_photographed_page_is_stored_and_not_yet_confirmed(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/writing/attempts', [
            'page' => UploadedFile::fake()->image('homework.jpg', 1200, 1600),
        ]);

        $response->assertCreated()->assertJsonPath('data.source', WritingAttempt::SOURCE_PHOTO);

        $attempt = WritingAttempt::first();

        $this->assertNotNull($attempt->media_asset_id);
        $this->assertFalse($attempt->text_confirmed);
        $this->assertFalse(
            $attempt->textIsAuthoritative(),
            'an unread photo must never be markable',
        );
        Storage::disk('local')->assertExists($attempt->page->path);
    }

    public function test_a_learner_can_correct_what_was_read_off_their_page(): void
    {
        $attempt = WritingAttempt::create([
            'user_id' => $this->user->id,
            'source' => WritingAttempt::SOURCE_PHOTO,
            'recognised_text' => 'I hove a red cor.',
            'text' => 'I hove a red cor.',
            'recognition_confidence' => 0.44,
            'status' => WritingAttempt::STATUS_AWAITING_CONFIRMATION,
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/v1/writing/attempts/{$attempt->id}/confirm", [
                'text' => 'I have a red car.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', WritingAttempt::STATUS_PENDING);

        $attempt->refresh();

        $this->assertSame('I have a red car.', $attempt->text);
        $this->assertTrue($attempt->text_confirmed);

        // The machine's reading is kept, so a bad recognition can be studied
        // rather than silently overwritten.
        $this->assertSame('I hove a red cor.', $attempt->recognised_text);

        Queue::assertPushed(ProcessWritingAttempt::class);
    }

    public function test_a_low_confidence_reading_is_flagged_for_careful_checking(): void
    {
        $attempt = WritingAttempt::create([
            'user_id' => $this->user->id,
            'source' => WritingAttempt::SOURCE_PHOTO,
            'recognised_text' => 'something [?] barely legible',
            'recognition_confidence' => 0.31,
            'status' => WritingAttempt::STATUS_AWAITING_CONFIRMATION,
        ]);

        $this->actingAs($this->user)
            ->getJson("/api/v1/writing/attempts/{$attempt->id}")
            ->assertOk()
            ->assertJsonPath('data.recognition.needs_careful_check', true)
            ->assertJsonPath('data.recognition.confirmed', false);
    }

    public function test_confirming_is_refused_once_an_attempt_has_been_marked(): void
    {
        $attempt = WritingAttempt::create([
            'user_id' => $this->user->id,
            'source' => WritingAttempt::SOURCE_PHOTO,
            'text' => 'Already marked.',
            'text_confirmed' => true,
            'status' => WritingAttempt::STATUS_SCORED,
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/v1/writing/attempts/{$attempt->id}/confirm", ['text' => 'Changed my mind.'])
            ->assertStatus(409);
    }

    public function test_a_typed_attempt_has_nothing_to_confirm(): void
    {
        $attempt = WritingAttempt::create([
            'user_id' => $this->user->id,
            'source' => WritingAttempt::SOURCE_TYPED,
            'text' => 'I typed this.',
            'text_confirmed' => true,
            'status' => WritingAttempt::STATUS_PENDING,
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/v1/writing/attempts/{$attempt->id}/confirm", ['text' => 'x'])
            ->assertStatus(422);
    }

    public function test_one_learner_cannot_read_or_confirm_another_learners_work(): void
    {
        $other = User::factory()->create();
        $attempt = WritingAttempt::create([
            'user_id' => $other->id,
            'source' => WritingAttempt::SOURCE_TYPED,
            'text' => 'Private.',
            'status' => WritingAttempt::STATUS_SCORED,
        ]);

        $this->actingAs($this->user)
            ->getJson("/api/v1/writing/attempts/{$attempt->id}")->assertStatus(403);

        $this->actingAs($this->user)
            ->postJson("/api/v1/writing/attempts/{$attempt->id}/confirm", ['text' => 'x'])->assertStatus(403);
    }

    public function test_it_refuses_a_submission_with_neither_text_nor_a_page(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/writing/attempts', [])
            ->assertStatus(422)->assertJsonValidationErrors('text');
    }

    public function test_it_refuses_a_submission_carrying_both(): void
    {
        // Which one is the learner's actual work? Guessing would mark the
        // wrong thing.
        $this->actingAs($this->user)
            ->postJson('/api/v1/writing/attempts', [
                'text' => 'typed version',
                'page' => UploadedFile::fake()->image('page.jpg'),
            ])
            ->assertStatus(422);
    }

    public function test_writing_requires_authentication(): void
    {
        $this->postJson('/api/v1/writing/attempts', ['text' => 'hello'])->assertStatus(401);
    }
}
