<?php

namespace App\Services\Classroom;

use App\Events\Classroom\ClassroomEvent;
use App\Models\ClassChatMessage;
use App\Models\ClassMaterial;
use App\Models\ClassSession;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Shared screen state inside a live class: PDF page, video clock, whiteboard.
 *
 * Authority stays with the coach. Learners read `stage` from `GET /room` and
 * apply `stage.updated` / `whiteboard.updated` / `chat.message` events.
 */
class RoomStageService
{
    public const MODE_MATERIAL = 'material';

    public const MODE_WHITEBOARD = 'whiteboard';

    public const MAX_STROKES = 400;

    /** Default stage when a session has never been touched. */
    public function empty(): array
    {
        return [
            'mode' => self::MODE_MATERIAL,
            'page' => 1,
            'media' => [
                'playing' => false,
                'position_ms' => 0,
                'updated_at' => null,
            ],
            'whiteboard' => [
                'strokes' => [],
            ],
        ];
    }

    public function current(ClassSession $session): array
    {
        $stage = $session->stage;

        if (! is_array($stage) || $stage === []) {
            return $this->empty();
        }

        return array_replace_recursive($this->empty(), $stage);
    }

    /**
     * Coach updates page / playhead / mode. Partial patches merge in.
     *
     * @param  array{mode?:string,page?:int,media?:array{playing?:bool,position_ms?:int}}  $patch
     */
    public function update(ClassSession $session, array $patch): array
    {
        $stage = $this->current($session);

        if (isset($patch['mode'])) {
            $mode = (string) $patch['mode'];
            if (! in_array($mode, [self::MODE_MATERIAL, self::MODE_WHITEBOARD], true)) {
                throw new ClassroomException('Unknown stage mode.');
            }
            $stage['mode'] = $mode;
        }

        if (array_key_exists('page', $patch)) {
            $page = (int) $patch['page'];
            if ($page < 1 || $page > 5000) {
                throw new ClassroomException('PDF page out of range.');
            }
            $stage['page'] = $page;
        }

        if (isset($patch['media']) && is_array($patch['media'])) {
            $media = $stage['media'];
            if (array_key_exists('playing', $patch['media'])) {
                $media['playing'] = (bool) $patch['media']['playing'];
            }
            if (array_key_exists('position_ms', $patch['media'])) {
                $position = (int) $patch['media']['position_ms'];
                if ($position < 0) {
                    throw new ClassroomException('Playback position cannot be negative.');
                }
                $media['position_ms'] = $position;
            }
            $media['updated_at'] = now()->toIso8601String();
            $stage['media'] = $media;
        }

        $session->forceFill(['stage' => $stage])->save();

        event(new ClassroomEvent($session->id, ClassroomEvent::STAGE_UPDATED, [
            'stage' => $this->publicStage($stage),
        ]));

        return $this->publicStage($stage);
    }

    /** Reset presentation cursor when a new material is shared. */
    public function resetForShare(ClassSession $session): void
    {
        $stage = $this->current($session);
        $stage['mode'] = self::MODE_MATERIAL;
        $stage['page'] = 1;
        $stage['media'] = [
            'playing' => false,
            'position_ms' => 0,
            'updated_at' => now()->toIso8601String(),
        ];
        $session->forceFill(['stage' => $stage])->save();
    }

    /**
     * @param  array{id?:string,color?:string,width?:float|int,points:list<array{0:float|int,1:float|int}>}  $stroke
     */
    public function appendStroke(ClassSession $session, array $stroke): array
    {
        $stage = $this->current($session);
        $points = $stroke['points'] ?? [];

        if (! is_array($points) || count($points) < 2) {
            throw new ClassroomException('A stroke needs at least two points.');
        }
        if (count($points) > 800) {
            throw new ClassroomException('Stroke is too long.');
        }

        $normalised = [];
        foreach ($points as $point) {
            if (! is_array($point) || count($point) < 2) {
                continue;
            }
            $normalised[] = [
                round((float) $point[0], 4),
                round((float) $point[1], 4),
            ];
        }

        if (count($normalised) < 2) {
            throw new ClassroomException('A stroke needs at least two points.');
        }

        $entry = [
            'id' => (string) ($stroke['id'] ?? Str::ulid()),
            'color' => $this->safeColor($stroke['color'] ?? '#111827'),
            'width' => max(1, min(24, (float) ($stroke['width'] ?? 3))),
            'points' => $normalised,
        ];

        $strokes = $stage['whiteboard']['strokes'] ?? [];
        $strokes[] = $entry;
        if (count($strokes) > self::MAX_STROKES) {
            $strokes = array_slice($strokes, -self::MAX_STROKES);
        }
        $stage['whiteboard']['strokes'] = array_values($strokes);
        $stage['mode'] = self::MODE_WHITEBOARD;

        $session->forceFill(['stage' => $stage])->save();

        event(new ClassroomEvent($session->id, ClassroomEvent::WHITEBOARD_UPDATED, [
            'action' => 'stroke',
            'stroke' => $entry,
            'stage' => $this->publicStage($stage),
        ]));

        return $this->publicStage($stage);
    }

    public function clearWhiteboard(ClassSession $session): array
    {
        $stage = $this->current($session);
        $stage['whiteboard']['strokes'] = [];
        $stage['mode'] = self::MODE_WHITEBOARD;
        $session->forceFill(['stage' => $stage])->save();

        event(new ClassroomEvent($session->id, ClassroomEvent::WHITEBOARD_UPDATED, [
            'action' => 'clear',
            'stage' => $this->publicStage($stage),
        ]));

        return $this->publicStage($stage);
    }

    public function postChat(ClassSession $session, User $user, string $body): ClassChatMessage
    {
        $body = trim($body);
        if ($body === '') {
            throw new ClassroomException('Chat message cannot be empty.');
        }

        $message = $session->chatMessages()->create([
            'user_id' => $user->id,
            'body' => $body,
        ]);

        $message->load('user');

        event(new ClassroomEvent($session->id, ClassroomEvent::CHAT_MESSAGE, [
            'message' => $this->presentChat($message),
        ]));

        return $message;
    }

    /**
     * Recent chat for the room snapshot.
     *
     * @return list<array<string, mixed>>
     */
    public function recentChat(ClassSession $session, int $limit = 80): array
    {
        return $session->chatMessages()
            ->with('user')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->sortBy('id')
            ->values()
            ->map(fn (ClassChatMessage $m) => $this->presentChat($m))
            ->all();
    }

    /** @return array<string, mixed> */
    public function presentChat(ClassChatMessage $message): array
    {
        return [
            'id' => $message->id,
            'user_id' => $message->user_id,
            'name' => $message->user?->name,
            'body' => $message->body,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    /**
     * Stage without leaking internal-only keys later.
     *
     * @return array<string, mixed>
     */
    public function publicStage(array $stage): array
    {
        return [
            'mode' => $stage['mode'] ?? self::MODE_MATERIAL,
            'page' => (int) ($stage['page'] ?? 1),
            'media' => [
                'playing' => (bool) ($stage['media']['playing'] ?? false),
                'position_ms' => (int) ($stage['media']['position_ms'] ?? 0),
                'updated_at' => $stage['media']['updated_at'] ?? null,
            ],
            'whiteboard' => [
                'strokes' => array_values($stage['whiteboard']['strokes'] ?? []),
            ],
        ];
    }

    private function safeColor(string $color): string
    {
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1) {
            return strtolower($color);
        }

        return '#111827';
    }
}
