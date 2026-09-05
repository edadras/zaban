<?php

namespace App\Console\Commands;

use App\Models\AudioAsset;
use App\Models\MediaAsset;
use App\Models\SourceDocument;
use App\Models\SourcePage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Proves that nothing from the source books was dropped on the way into the
 * database, by comparing what is on disk against what is stored.
 *
 * This is deliberately independent of the importer: it re-reads the PDFs and the
 * audio tree rather than trusting the importer's own counters.
 */
class AuditContent extends Command
{
    protected $signature = 'content:audit {--audio-dir=sources/audio}';

    protected $description = 'Verify no source content was lost during import';

    public function handle(): int
    {
        $root = dirname(base_path());
        $failures = 0;

        $this->info('── Page coverage ──');
        $rows = [];
        foreach (SourceDocument::with('files')->get() as $doc) {
            $pdf = $doc->files->firstWhere('kind', 'pdf');
            if (! $pdf) {
                continue;
            }
            $abs = $root.'/'.$pdf->path;
            $expected = is_file($abs) ? $this->pdfPageCount($abs) : 0;
            $stored = SourcePage::where('source_file_id', $pdf->id)->count();
            $empty = SourcePage::where('source_file_id', $pdf->id)
                ->where(fn ($q) => $q->whereNull('text')->orWhere('char_count', 0))->count();
            $chars = (int) SourcePage::where('source_file_id', $pdf->id)->sum('char_count');
            $ok = $expected > 0 && $stored === $expected;
            $failures += $ok ? 0 : 1;
            $rows[] = [$doc->title, $expected, $stored, $empty, number_format($chars), $ok ? 'OK' : 'MISMATCH'];
        }
        $this->table(['document', 'pdf pages', 'stored', 'empty', 'chars', ''], $rows);

        $this->info('── Audio coverage ──');
        $dir = $root.'/'.trim($this->option('audio-dir'), '/');
        $onDisk = $this->mp3Files($dir);
        $registered = MediaAsset::where('type', 'audio')->pluck('path')->all();
        $registeredSet = array_flip($registered);

        $unregistered = [];
        foreach ($onDisk as $rel) {
            if (! isset($registeredSet[$rel])) {
                $unregistered[] = $rel;
            }
        }
        $dangling = [];
        foreach ($registered as $rel) {
            if (! is_file($root.'/'.$rel)) {
                $dangling[] = $rel;
            }
        }
        $unmapped = AudioAsset::doesntHave('mappings')->count();

        $this->table(['check', 'count', ''], [
            ['mp3 files on disk', count($onDisk), ''],
            ['registered as media_assets', count($registered), count($registered) === count($onDisk) ? 'OK' : 'MISMATCH'],
            ['on disk but not registered', count($unregistered), $unregistered ? 'FAIL' : 'OK'],
            ['registered but file missing', count($dangling), $dangling ? 'FAIL' : 'OK'],
            ['audio assets with no mapping', $unmapped, $unmapped ? 'FAIL' : 'OK'],
        ]);
        foreach (array_slice($unregistered, 0, 5) as $u) {
            $this->warn("  unregistered: {$u}");
        }
        foreach (array_slice($dangling, 0, 5) as $d) {
            $this->warn("  dangling: {$d}");
        }
        $failures += (count($unregistered) ? 1 : 0) + (count($dangling) ? 1 : 0) + ($unmapped ? 1 : 0);

        $this->info('── Structural coverage ──');
        $segTypes = DB::table('source_segments')
            ->select('segment_type', DB::raw('COUNT(*) n'), DB::raw('SUM(CHAR_LENGTH(text)) chars'))
            ->groupBy('segment_type')->get();
        $this->table(['segment type', 'rows', 'chars'],
            $segTypes->map(fn ($r) => [$r->segment_type, $r->n, number_format((int) $r->chars)])->all());

        $orphans = [
            'lessons without a unit' => DB::table('lessons')->leftJoin('units', 'units.id', '=', 'lessons.unit_id')->whereNull('units.id')->count(),
            'concepts without a sense' => DB::table('concepts')->leftJoin('vocabulary_senses', 'vocabulary_senses.id', '=', 'concepts.conceptable_id')->whereNull('vocabulary_senses.id')->count(),
            'segments without a document' => DB::table('source_segments')->leftJoin('source_documents', 'source_documents.id', '=', 'source_segments.source_document_id')->whereNull('source_documents.id')->count(),
            'lessons with no concepts' => DB::table('lessons')->leftJoin('lesson_concept', 'lesson_concept.lesson_id', '=', 'lessons.id')->whereNull('lesson_concept.lesson_id')->count(),
            'exercises with no answer' => DB::table('exercises')->leftJoin('exercise_answers', 'exercise_answers.exercise_id', '=', 'exercises.id')->whereNull('exercise_answers.exercise_id')->count(),
        ];
        $this->table(['integrity check', 'count'], collect($orphans)->map(fn ($v, $k) => [$k, $v])->values()->all());
        $failures += $orphans['lessons without a unit'] + $orphans['concepts without a sense'] + $orphans['segments without a document'];

        $this->newLine();
        if ($failures === 0) {
            $this->info('AUDIT PASSED — every source page and audio file is represented.');

            return self::SUCCESS;
        }
        $this->error("AUDIT FAILED — {$failures} check(s) did not pass.");

        return self::FAILURE;
    }

    private function pdfPageCount(string $path): int
    {
        $out = [];
        @exec('pdfinfo '.escapeshellarg($path).' 2>/dev/null', $out);
        foreach ($out as $line) {
            if (preg_match('/^Pages:\s+(\d+)/', $line, $m)) {
                return (int) $m[1];
            }
        }

        return 0;
    }

    /** @return string[] repo-relative paths */
    private function mp3Files(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }
        $root = dirname(base_path()).'/';
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && strtolower($f->getExtension()) === 'mp3') {
                $files[] = str_replace($root, '', $f->getPathname());
            }
        }
        sort($files);

        return $files;
    }
}
