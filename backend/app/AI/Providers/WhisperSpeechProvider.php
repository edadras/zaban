<?php

namespace App\AI\Providers;

use App\AI\Contracts\SpeechProviderInterface;
use App\AI\Support\SpeechRequest;
use App\AI\Support\SpeechResult;
use Symfony\Component\Process\Process;

/**
 * Local speech-to-text via whisper.cpp, with optional forced alignment.
 *
 * Running transcription on our own hardware keeps learner voice recordings off
 * third-party services by default, which matters because those recordings are
 * personal data (spec 45).
 *
 * Alignment is reported as unsupported unless an aligner binary is configured -
 * phoneme-level scoring inferred from a transcript alone would be a guess
 * dressed up as measurement.
 */
class WhisperSpeechProvider implements SpeechProviderInterface
{
    public function __construct(
        private string $binary,
        private ?string $modelPath,
        private int $timeout,
        private ?string $alignerBinary = null,
        private ?string $alignerDictionary = null,
    ) {}

    public function code(): string
    {
        return 'whisper';
    }

    public function capabilities(): array
    {
        return ['stt'];
    }

    public function isAvailable(): bool
    {
        return $this->binary && $this->modelPath && is_file($this->modelPath) && $this->which($this->binary);
    }

    public function supportsAlignment(): bool
    {
        return (bool) $this->alignerBinary && $this->which($this->alignerBinary);
    }

    public function transcribe(SpeechRequest $req): SpeechResult
    {
        if (! is_file($req->audioPath)) {
            return SpeechResult::failure('Audio file not found.');
        }

        $out = tempnam(sys_get_temp_dir(), 'stt');
        $cmd = [
            $this->binary, '-m', $this->modelPath, '-f', $req->audioPath,
            '-l', $req->language, '-oj', '-of', $out, '-np', '-nt',
        ];
        $process = new Process($cmd);
        $process->setTimeout($this->timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            @unlink($out);

            return SpeechResult::failure(trim($process->getErrorOutput()) ?: 'Transcription failed.');
        }

        $jsonPath = $out.'.json';
        $payload = is_file($jsonPath) ? json_decode(file_get_contents($jsonPath), true) : null;
        @unlink($out);
        @unlink($jsonPath);

        if (! is_array($payload)) {
            return SpeechResult::failure('Transcriber returned no parsable output.');
        }

        $words = [];
        $transcript = '';
        foreach ($payload['transcription'] ?? [] as $seg) {
            $transcript .= $seg['text'] ?? '';
            foreach ($seg['tokens'] ?? [] as $tok) {
                $text = trim($tok['text'] ?? '');
                if ($text === '' || str_starts_with($text, '[')) {
                    continue;
                }
                $words[] = [
                    'word' => $text,
                    'start_ms' => (int) (($tok['offsets']['from'] ?? 0)),
                    'end_ms' => (int) (($tok['offsets']['to'] ?? 0)),
                    'confidence' => (float) ($tok['p'] ?? 0),
                ];
            }
        }

        return new SpeechResult(
            ok: true,
            transcript: trim($transcript),
            words: $words,
            model: basename((string) $this->modelPath),
            raw: ['segments' => count($payload['transcription'] ?? [])],
        );
    }

    public function align(SpeechRequest $req): SpeechResult
    {
        if (! $this->supportsAlignment()) {
            return SpeechResult::failure('No aligner configured.');
        }
        if (! $req->expectedText) {
            return SpeechResult::failure('Alignment needs the expected text.');
        }

        $dir = sys_get_temp_dir().'/align_'.bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        $base = $dir.'/utt';
        copy($req->audioPath, $base.'.wav');
        file_put_contents($base.'.lab', $req->expectedText);

        $cmd = [$this->alignerBinary, 'align', $dir];
        if ($this->alignerDictionary) {
            $cmd[] = $this->alignerDictionary;
        }
        $cmd[] = 'english_us_arpa';
        $cmd[] = $dir.'/out';

        $process = new Process($cmd);
        $process->setTimeout($this->timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->rmdir($dir);

            return SpeechResult::failure('Forced alignment failed: '.trim($process->getErrorOutput()));
        }

        $grid = $dir.'/out/utt.TextGrid';
        $phonemes = is_file($grid) ? $this->parseTextGrid(file_get_contents($grid)) : [];
        $this->rmdir($dir);

        return new SpeechResult(ok: true, transcript: $req->expectedText, phonemes: $phonemes, model: 'mfa');
    }

    /** Minimal TextGrid reader: the phones tier with its interval boundaries. */
    private function parseTextGrid(string $content): array
    {
        $out = [];
        if (! preg_match('/name = "phones".*?(?=item \[|\z)/s', $content, $m)) {
            return $out;
        }
        preg_match_all(
            '/xmin = ([\d.]+)\s+xmax = ([\d.]+)\s+text = "([^"]*)"/',
            $m[0],
            $rows,
            PREG_SET_ORDER,
        );
        $i = 0;
        foreach ($rows as $r) {
            $label = trim($r[3]);
            if ($label === '' || $label === 'sil' || $label === 'sp') {
                continue;
            }
            $out[] = [
                'word_index' => $i++,
                'phoneme' => $label,
                'start_ms' => (int) round(((float) $r[1]) * 1000),
                'end_ms' => (int) round(((float) $r[2]) * 1000),
                'score' => 1.0,
            ];
        }

        return $out;
    }

    private function which(string $bin): bool
    {
        if (str_contains($bin, '/')) {
            return is_executable($bin);
        }
        $p = new Process(['which', $bin]);
        $p->run();

        return $p->isSuccessful();
    }

    private function rmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
