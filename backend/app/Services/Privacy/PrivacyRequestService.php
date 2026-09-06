<?php

namespace App\Services\Privacy;

use App\Models\AuditLog;
use App\Models\MediaAsset;
use App\Models\PrivacyRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Fulfils the two requests a person can make about their own data.
 *
 * Both were recorded and neither was ever carried out: `privacy_requests` rows
 * were created by the profile endpoints and nothing read them. A promise to
 * export or erase that is only written down is worse than no promise, because
 * the person believes it happened.
 *
 * Two decisions worth stating, because they are the ones that look wrong until
 * you know why:
 *
 * The account row is emptied rather than deleted. Invoices must survive an
 * erasure - a tax authority does not care that the customer asked - and they
 * are joined to `users` by a foreign key. So the row stays, holding nothing:
 * a placeholder name, an unroutable address at a reserved domain, a random
 * password nobody has, and no avatar. Everything that says who the person was
 * is gone; the accounting stays balanced.
 *
 * Learning data goes entirely. Attempts, sessions, mastery, errors, streaks,
 * recordings and their audio files are the record of a person, and none of it
 * is needed once they leave.
 */
class PrivacyRequestService
{
    /** Where exports are written. Private disk: nothing here is publicly reachable. */
    public const DISK = 'local';

    /**
     * How long a finished export stays downloadable. Long enough to be missed
     * on holiday, short enough not to be a copy of someone's data sitting in a
     * bucket for a year.
     */
    public const EXPORT_DAYS = 14;

    /**
     * The tables holding a learner's own record, cleared on erasure.
     *
     * Billing is deliberately absent: invoices, transactions, payment attempts
     * and coupon redemptions are financial records, and they are kept against
     * the emptied account rather than deleted with it.
     */
    private const LEARNING_TABLES = [
        'exercise_attempts', 'lesson_attempts', 'learning_sessions',
        'placement_sessions', 'exam_attempts', 'writing_attempts',
        'conversation_sessions', 'learner_concepts', 'learner_reviews',
        'review_history', 'learner_errors', 'learner_skill_states',
        'skill_snapshots', 'daily_progress', 'xp_transactions',
        'user_achievements', 'user_events', 'pronunciation_errors',
        'ai_requests', 'ai_usage', 'entitlement_usage',
        'learner_profiles', 'user_profiles', 'user_settings',
    ];

    /** Tables an export reads. The learner's own rows, nothing else's. */
    private const EXPORT_TABLES = [
        'user_profiles', 'user_settings', 'learner_profiles', 'daily_progress',
        'learning_sessions', 'lesson_attempts', 'exercise_attempts',
        'placement_sessions', 'exam_attempts', 'writing_attempts',
        'speech_attempts', 'learner_concepts', 'learner_reviews',
        'learner_errors', 'learner_skill_states', 'skill_snapshots',
        'xp_transactions', 'user_achievements', 'subscriptions', 'invoices',
    ];

    public function process(PrivacyRequest $request): PrivacyRequest
    {
        if ($request->status !== 'pending') {
            return $request;
        }

        $request->update(['status' => 'processing']);

        try {
            match ($request->type) {
                'export' => $this->export($request),
                'delete_account' => $this->erase($request),
                default => throw new \InvalidArgumentException(
                    "Unknown privacy request type: {$request->type}",
                ),
            };
        } catch (\Throwable $e) {
            $request->update([
                'status' => 'failed',
                'error' => Str::limit($e->getMessage(), 1000, ''),
            ]);

            throw $e;
        }

        return $request->fresh();
    }

    /** Everything the platform holds about this person, as one JSON file. */
    private function export(PrivacyRequest $request): void
    {
        $user = User::withTrashed()->findOrFail($request->user_id);

        $payload = [
            'exported_at' => now()->toIso8601String(),
            'account' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale,
                'timezone' => $user->timezone,
                'created_at' => $user->created_at?->toIso8601String(),
            ],
        ];

        foreach (self::EXPORT_TABLES as $table) {
            $payload[$table] = DB::table($table)->where('user_id', $user->id)->get();
        }

        $path = sprintf('privacy/exports/%d/%s.json', $user->id, Str::uuid());
        Storage::disk(self::DISK)->put(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        $request->update([
            'status' => 'completed',
            'export_path' => $path,
            'expires_at' => now()->addDays(self::EXPORT_DAYS),
            'completed_at' => now(),
            'error' => null,
        ]);
    }

    /** Erase the person, keep the accounting. */
    private function erase(PrivacyRequest $request): void
    {
        $userId = $request->user_id;

        // The recordings are files on disk as well as rows, and a row deleted
        // without its file leaves the voice behind.
        $this->deleteSpeechAudio($userId);

        DB::transaction(function () use ($userId, $request) {
            foreach (self::LEARNING_TABLES as $table) {
                DB::table($table)->where('user_id', $userId)->delete();
            }
            DB::table('speech_attempts')->where('user_id', $userId)->delete();

            $user = User::withTrashed()->find($userId);
            if ($user !== null) {
                $avatar = $user->avatar_path;

                $user->forceFill([
                    'name' => 'Deleted account',
                    // .invalid is reserved by RFC 2606 and can never be routed,
                    // so this address cannot reach a person by accident.
                    'email' => "deleted-{$userId}@erased.invalid",
                    'email_verified_at' => null,
                    'password' => bcrypt(Str::random(64)),
                    'remember_token' => null,
                    'avatar_path' => null,
                    'locale' => 'en',
                    'timezone' => 'UTC',
                ])->save();
                $user->tokens()->delete();
                $user->delete();

                if ($avatar) {
                    Storage::disk('public')->delete($avatar);
                }
            }

            // The request itself is kept: it is the evidence that the erasure
            // was asked for and carried out. It names no one it did not
            // already name.
            $request->update([
                'status' => 'completed',
                'completed_at' => now(),
                'error' => null,
            ]);

            AuditLog::create([
                'user_id' => null,
                'action' => 'privacy.erased',
                'after' => ['request' => $request->id, 'user' => $userId],
            ]);
        });
    }

    private function deleteSpeechAudio(int $userId): void
    {
        $assetIds = DB::table('speech_attempts')
            ->where('user_id', $userId)
            ->whereNotNull('media_asset_id')
            ->pluck('media_asset_id');

        foreach (MediaAsset::whereIn('id', $assetIds)->get() as $asset) {
            if ($asset->path) {
                Storage::disk($asset->disk ?: self::DISK)->delete($asset->path);
            }
            $asset->delete();
        }
    }

    /**
     * Exports do not live forever. This removes the file and the path once the
     * window has passed; the request row stays as the record that it happened.
     */
    public function purgeExpiredExports(): int
    {
        $purged = 0;

        PrivacyRequest::where('type', 'export')
            ->where('status', 'completed')
            ->whereNotNull('export_path')
            ->where('expires_at', '<=', now())
            ->each(function (PrivacyRequest $request) use (&$purged) {
                Storage::disk(self::DISK)->delete($request->export_path);
                $request->update(['export_path' => null, 'status' => 'expired']);
                $purged++;
            });

        return $purged;
    }
}
