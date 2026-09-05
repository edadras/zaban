<?php

namespace App\Services\Exam;

use App\Models\ExamSection;

/**
 * Typed reader over exam_sections.scoring_criteria.
 *
 * The seeder writes that JSON; this class is the only thing that interprets it,
 * so a new exam format changes one file rather than being spread through the
 * scoring code.
 */
final class SectionScoring
{
    public const MODE_OBJECTIVE = 'objective';
    public const MODE_RUBRIC = 'rubric';

    private function __construct(private readonly array $config) {}

    public static function for(ExamSection $section): self
    {
        return new self($section->scoring_criteria ?? []);
    }

    public function mode(): string
    {
        return $this->config['mode'] ?? self::MODE_OBJECTIVE;
    }

    public function isObjective(): bool
    {
        return $this->mode() === self::MODE_OBJECTIVE;
    }

    public function isRubric(): bool
    {
        return $this->mode() === self::MODE_RUBRIC;
    }

    /** How this exam combines section scores into the overall score. */
    public function aggregation(): string
    {
        return $this->config['aggregation'] ?? 'mean';
    }

    public function rawMax(): int
    {
        return (int) ($this->config['raw_max'] ?? 0);
    }

    /** @return array{min: float, max: float, step: float} */
    public function sectionScale(): array
    {
        return $this->scale($this->config['section_scale'] ?? null, ['min' => 0.0, 'max' => 100.0, 'step' => 1.0]);
    }

    /** @return array{min: float, max: float, step: float} */
    public function criterionScale(): array
    {
        return $this->scale($this->config['criterion_scale'] ?? null, $this->sectionScale());
    }

    /** @return array<int, array<string, mixed>> */
    public function criteria(): array
    {
        return array_values($this->config['criteria'] ?? []);
    }

    /** @return string[] */
    public function criterionCodes(): array
    {
        return array_map(fn (array $c) => (string) $c['code'], $this->criteria());
    }

    /**
     * Relative weight of one task type within its section. IELTS Task 2 counts
     * twice Task 1; where an exam weights its tasks equally the profile omits
     * the key and everything defaults to 1.
     */
    public function taskWeight(?string $taskTypeCode): float
    {
        if (! $taskTypeCode) {
            return 1.0;
        }

        return (float) ($this->config['task_weights'][$taskTypeCode] ?? 1.0);
    }

    /** Speaking interview structure, empty for everything else. */
    public function parts(): array
    {
        return array_values($this->config['parts'] ?? []);
    }

    public function part(string $code): ?array
    {
        foreach ($this->parts() as $part) {
            if (($part['code'] ?? null) === $code) {
                return $part;
            }
        }

        return null;
    }

    /**
     * Raw marks to the section's reported score.
     *
     * Three published shapes cover the four exams: IELTS uses a lookup table on
     * the raw count, TOEFL scales linearly, and Cambridge and PTE interpolate
     * between grade-boundary anchors.
     */
    public function scoreFromRaw(float $rawCorrect): ?float
    {
        $max = $this->rawMax();
        if ($max <= 0) {
            return null;
        }

        $raw = max(0.0, min((float) $max, $rawCorrect));
        $scale = $this->sectionScale();

        $score = match ($this->config['conversion'] ?? 'linear') {
            'table' => $this->fromTable($raw),
            'anchors' => $this->fromAnchors($raw / $max, $scale),
            default => $scale['min'] + ($raw / $max) * ($scale['max'] - $scale['min']),
        };

        if ($score === null) {
            return null;
        }

        return self::roundToStep($score, $scale['step'], $scale['min'], $scale['max']);
    }

    /**
     * Weighted mean of the per-criterion scores, rescaled from the criterion
     * scale onto the section's reported scale.
     *
     * @param  array<string, float>  $scores  criterion code => score
     */
    public function scoreFromCriteria(array $scores): ?float
    {
        $criteria = $this->criteria();
        if (! $criteria || ! $scores) {
            return null;
        }

        $weighted = 0.0;
        $weight = 0.0;
        foreach ($criteria as $criterion) {
            $code = (string) $criterion['code'];
            if (! array_key_exists($code, $scores)) {
                continue;
            }
            $w = (float) ($criterion['weight'] ?? 1.0);
            $weighted += $w * (float) $scores[$code];
            $weight += $w;
        }

        if ($weight <= 0.0) {
            return null;
        }

        $mean = $weighted / $weight;
        $from = $this->criterionScale();
        $to = $this->sectionScale();

        $span = $from['max'] - $from['min'];
        $rescaled = $span > 0
            ? $to['min'] + (($mean - $from['min']) / $span) * ($to['max'] - $to['min'])
            : $mean;

        return self::roundToStep($rescaled, $to['step'], $to['min'], $to['max']);
    }

    public static function roundToStep(float $value, float $step, float $min, float $max): float
    {
        $value = max($min, min($max, $value));
        if ($step <= 0) {
            return round($value, 2);
        }

        return round(max($min, min($max, round($value / $step) * $step)), 2);
    }

    private function fromTable(float $raw): ?float
    {
        $rows = $this->config['table'] ?? [];
        // Rows are ordered from the highest threshold down; the first one the raw
        // count reaches is the band.
        foreach ($rows as $row) {
            if ($raw >= (float) $row['raw_min']) {
                return (float) $row['score'];
            }
        }

        return $rows ? (float) end($rows)['score'] : null;
    }

    private function fromAnchors(float $proportion, array $scale): ?float
    {
        $rows = $this->config['table'] ?? [];
        if (count($rows) < 2) {
            return null;
        }

        usort($rows, fn ($a, $b) => $a['proportion'] <=> $b['proportion']);

        if ($proportion <= (float) $rows[0]['proportion']) {
            return (float) $rows[0]['score'];
        }

        for ($i = 1; $i < count($rows); $i++) {
            $hi = $rows[$i];
            if ($proportion > (float) $hi['proportion']) {
                continue;
            }
            $lo = $rows[$i - 1];
            $span = (float) $hi['proportion'] - (float) $lo['proportion'];
            $t = $span > 0 ? ($proportion - (float) $lo['proportion']) / $span : 0.0;

            return (float) $lo['score'] + $t * ((float) $hi['score'] - (float) $lo['score']);
        }

        return (float) end($rows)['score'] ?: $scale['max'];
    }

    /** @return array{min: float, max: float, step: float} */
    private function scale(?array $raw, array $fallback): array
    {
        return [
            'min' => (float) ($raw['min'] ?? $fallback['min']),
            'max' => (float) ($raw['max'] ?? $fallback['max']),
            'step' => (float) ($raw['step'] ?? $fallback['step']),
        ];
    }
}
