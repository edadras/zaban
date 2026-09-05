<?php

namespace Tests\Feature\Speech\Support;

use App\AI\Contracts\SpeechProviderInterface;
use App\AI\Support\SpeechRequest;
use App\AI\Support\SpeechResult;

/**
 * A speech provider for tests only.
 *
 * It exists so the pipeline can be exercised without a real STT engine or forced
 * aligner. It is wired in through config exactly the way a real provider is, so
 * the orchestrator, the registry and the chain are all under test too - and
 * nothing in app/ knows this class exists.
 */
class FakeSpeechProvider implements SpeechProviderInterface
{
    public static ?SpeechResult $transcription = null;

    public static ?SpeechResult $alignment = null;

    public static bool $alignmentSupported = false;

    /** @var array<int,array{method:string,request:SpeechRequest}> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$transcription = null;
        self::$alignment = null;
        self::$alignmentSupported = false;
        self::$calls = [];
    }

    public function code(): string
    {
        return 'fake-speech';
    }

    public function capabilities(): array
    {
        return ['stt'];
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function supportsAlignment(): bool
    {
        return self::$alignmentSupported;
    }

    public function transcribe(SpeechRequest $request): SpeechResult
    {
        self::$calls[] = ['method' => 'transcribe', 'request' => $request];

        return self::$transcription ?? SpeechResult::failure('No fake transcription configured.');
    }

    public function align(SpeechRequest $request): SpeechResult
    {
        self::$calls[] = ['method' => 'align', 'request' => $request];

        return self::$alignment ?? SpeechResult::failure('No fake alignment configured.');
    }
}
