<?php

namespace App\Services;

/**
 * Weighted section scoring for 360° evaluation forms.
 *
 * Section Score = (Sum of Ratings ÷ (Questions × Max Rating)) × Section Weight
 * Overall %     = Sum of weighted section scores
 * Equivalent    = Overall % ÷ 20  (1–5 scale)
 */
class EvaluationScoringService
{
    /** @var list<array{key: string, matrix: string, ratings: list<string>, count: int, weight: int, max: int}> */
    private const SECTIONS = [
        [
            'key' => 'quality',
            'matrix' => 'quality_of_work',
            'ratings' => ['quality_0', 'quality_1', 'quality_2'],
            'count' => 3,
            'weight' => 15,
            'max' => 5,
        ],
        [
            'key' => 'productivity',
            'matrix' => 'productivity',
            'ratings' => ['productivity_0', 'productivity_1', 'productivity_2'],
            'count' => 3,
            'weight' => 15,
            'max' => 5,
        ],
        [
            'key' => 'accountability',
            'matrix' => 'accountability',
            'ratings' => ['accountability_0', 'accountability_1', 'accountability_2'],
            'count' => 3,
            'weight' => 15,
            'max' => 5,
        ],
        [
            'key' => 'communication',
            'matrix' => 'communication',
            'ratings' => ['communication_0', 'communication_1', 'communication_2'],
            'count' => 3,
            'weight' => 15,
            'max' => 5,
        ],
        [
            'key' => 'problem_solving',
            'matrix' => 'problem_solving',
            'ratings' => ['problem_solving_0', 'problem_solving_1', 'problem_solving_2'],
            'count' => 3,
            'weight' => 10,
            'max' => 5,
        ],
        [
            'key' => 'core_values',
            'matrix' => 'core_values',
            'ratings' => ['core_value_0', 'core_value_1', 'core_value_2', 'core_value_3', 'core_value_4', 'core_value_5', 'core_value_6'],
            'count' => 7,
            'weight' => 30,
            'max' => 5,
        ],
    ];

    /**
     * @param  array<string, mixed>  $surveyData
     * @return array{
     *   overall_percentage: float,
     *   overall_score: float,
     *   overall_rating: string,
     *   section_scores: array<string, float>
     * }|null
     */
    public function computeFromSurveyData(array $surveyData): ?array
    {
        $sectionScores = [];
        $overallPercentage = 0.0;

        foreach (self::SECTIONS as $section) {
            $values = $this->collectSectionValues($surveyData, $section);
            if ($values === []) {
                continue;
            }

            $total = array_sum($values);
            $maxPossible = count($values) * $section['max'];
            if ($maxPossible <= 0) {
                continue;
            }

            $normalized = round($total / $maxPossible, 2);
            $weighted = round($normalized * $section['weight'], 2);
            $sectionScores[$section['key']] = $weighted;
            $overallPercentage += $weighted;
        }

        if ($sectionScores === []) {
            return null;
        }

        $overallPercentage = round($overallPercentage, 2);
        $equivalentRating = round($overallPercentage / 20, 2);

        return [
            'overall_percentage' => $overallPercentage,
            'overall_score' => $equivalentRating,
            'overall_rating' => $this->ratingLabelFromPercentage($overallPercentage),
            'section_scores' => $sectionScores,
        ];
    }

    /**
     * @param  array<string, mixed>  $surveyJson
     * @return array<string, mixed>
     */
    public function applyWeightedSummaryExpressions(array $surveyJson): array
    {
        $usesMatrix = $this->surveyUsesMatrixQuestions($surveyJson);
        $summaryPageIndex = null;

        foreach ($surveyJson['pages'] ?? [] as $idx => $page) {
            if (!is_array($page)) {
                continue;
            }
            $title = strtolower((string) ($page['title'] ?? ''));
            if (str_contains($title, 'summary') || str_contains($title, 'signature')) {
                $summaryPageIndex = $idx;
                break;
            }
        }

        if ($summaryPageIndex === null) {
            return $surveyJson;
        }

        $page = $surveyJson['pages'][$summaryPageIndex];
        $elements = is_array($page['elements'] ?? null) ? $page['elements'] : [];
        $elements = array_values(array_filter(
            $elements,
            fn ($el) => !is_array($el) || ($el['type'] ?? '') !== 'expression',
        ));

        $insertAt = count($elements);
        foreach ($elements as $i => $el) {
            if (is_array($el) && ($el['name'] ?? '') === 'scoring_header') {
                $insertAt = $i + 1;
                break;
            }
        }

        $expressions = $this->buildSummaryExpressionElements($usesMatrix);
        array_splice($elements, $insertAt, 0, $expressions);

        $surveyJson['pages'][$summaryPageIndex]['elements'] = $elements;

        return $surveyJson;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildSummaryExpressionElements(bool $usesMatrix): array
    {
        $elements = [];
        $weightedRefs = [];

        foreach (self::SECTIONS as $section) {
            $sumExpr = $usesMatrix
                ? $this->matrixSumExpression($section['matrix'], $section['count'])
                : $this->ratingSumExpression($section['ratings']);

            $maxPossible = $section['count'] * $section['max'];
            $key = $section['key'];
            $weight = $section['weight'];
            $title = $this->sectionTitle($key, $weight);

            $elements[] = [
                'type' => 'expression',
                'name' => "{$key}_score",
                'title' => $title,
                'expression' => "round(({$sumExpr}) / {$maxPossible}, 2) * {$weight}",
                'displayStyle' => 'decimal',
            ];

            $weightedRefs[] = "{{$key}_score}";
        }

        $overallExpr = implode(' + ', $weightedRefs);

        $elements[] = [
            'type' => 'expression',
            'name' => 'overall_percentage',
            'title' => '★ OVERALL PERCENTAGE',
            'expression' => $overallExpr,
            'displayStyle' => 'decimal',
        ];
        $elements[] = [
            'type' => 'expression',
            'name' => 'overall_rating',
            'title' => 'Overall Performance Level',
            'expression' => "if({overall_percentage} >= 90, 'Outstanding', if({overall_percentage} >= 70, 'Very Good', if({overall_percentage} >= 50, 'Good', if({overall_percentage} >= 30, 'Needs Improvement', 'Unsatisfactory'))))",
        ];

        return $elements;
    }

    private function sectionTitle(string $key, int $weight): string
    {
        return match ($key) {
            'quality' => "A. Quality of Work ({$weight}%)",
            'productivity' => "B. Productivity & Results ({$weight}%)",
            'accountability' => "C. Accountability & Reliability ({$weight}%)",
            'communication' => "D. Communication & Collaboration ({$weight}%)",
            'problem_solving' => "E. Problem Solving & Initiative ({$weight}%)",
            'core_values' => "Core Values ({$weight}%)",
            default => ucfirst(str_replace('_', ' ', $key))." ({$weight}%)",
        };
    }

    private function matrixSumExpression(string $matrix, int $count): string
    {
        $parts = [];
        for ($i = 0; $i < $count; $i++) {
            $parts[] = "{{$matrix}.{$i}}";
        }

        return '('.implode(' + ', $parts).')';
    }

    /**
     * @param  list<string>  $ratings
     */
    private function ratingSumExpression(array $ratings): string
    {
        $parts = array_map(fn ($name) => "{{$name}}", $ratings);

        return '('.implode(' + ', $parts).')';
    }

    /**
     * @param  array<string, mixed>  $surveyJson
     */
    private function surveyUsesMatrixQuestions(array $surveyJson): bool
    {
        $found = false;
        $walk = function (array $elements) use (&$walk, &$found): void {
            foreach ($elements as $el) {
                if (!is_array($el)) {
                    continue;
                }
                if (($el['type'] ?? '') === 'panel') {
                    $walk($el['elements'] ?? []);
                    continue;
                }
                if (($el['type'] ?? '') === 'matrix') {
                    $found = true;
                }
            }
        };

        foreach ($surveyJson['pages'] ?? [] as $page) {
            $walk($page['elements'] ?? []);
        }

        return $found;
    }

    /**
     * @param  array<string, mixed>  $surveyData
     * @param  array{key: string, matrix: string, ratings: list<string>, count: int, weight: int, max: int}  $section
     * @return list<float>
     */
    private function collectSectionValues(array $surveyData, array $section): array
    {
        $matrixRaw = $surveyData[$section['matrix']] ?? null;
        if (is_array($matrixRaw)) {
            $vals = [];
            for ($i = 0; $i < $section['count']; $i++) {
                $cell = $matrixRaw[$i] ?? $matrixRaw[(string) $i] ?? null;
                if (is_numeric($cell)) {
                    $vals[] = (float) $cell;
                }
            }
            if ($vals !== []) {
                return $vals;
            }
        }

        $vals = [];
        foreach ($section['ratings'] as $name) {
            $cell = $surveyData[$name] ?? null;
            if (is_numeric($cell)) {
                $vals[] = (float) $cell;
            }
        }

        return $vals;
    }

    public function ratingLabelFromPercentage(float $percentage): string
    {
        return match (true) {
            $percentage >= 90 => 'Outstanding',
            $percentage >= 70 => 'Very Good',
            $percentage >= 50 => 'Good',
            $percentage >= 30 => 'Needs Improvement',
            default => 'Unsatisfactory',
        };
    }
}
