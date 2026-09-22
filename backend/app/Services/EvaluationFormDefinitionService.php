<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Normalize and validate native evaluation form definitions (v2).
 */
class EvaluationFormDefinitionService
{
    public const VERSION = 2;

    /**
     * @param  array<string, mixed>|null  $raw
     * @return array<string, mixed>|null
     */
    public function normalizeStored(?array $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        if ($this->isDefinitionV2($raw)) {
            return $raw;
        }

        if ($this->isLegacySectionsList($raw)) {
            return $this->legacySectionsToDefinition($raw);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $formRow  keys: sections, survey_json, description
     * @return array<string, mixed>
     */
    public function resolveFromForm(array $formRow): array
    {
        $sections = $formRow['sections'] ?? null;
        if (is_array($sections) && $this->isDefinitionV2($sections)) {
            return $sections;
        }

        $surveyJson = $formRow['survey_json'] ?? null;
        if (is_array($surveyJson) && ! empty($surveyJson['pages'])) {
            $converted = $this->surveyJsonToDefinition($surveyJson);
            if ($converted !== null) {
                return $converted;
            }
        }

        if (is_array($sections) && $this->isLegacySectionsList($sections)) {
            return $this->legacySectionsToDefinition($sections, [
                'intro_html' => (string) ($formRow['description'] ?? ''),
            ]);
        }

        return $this->emptyDefinition((string) ($formRow['description'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    public function validateDefinition(array $definition): void
    {
        if (! $this->isDefinitionV2($definition)) {
            throw ValidationException::withMessages([
                'sections' => ['Invalid evaluation form definition.'],
            ]);
        }

        $total = 0;
        $scoredCount = 0;

        foreach ($definition['sections'] ?? [] as $section) {
            if (! is_array($section)) {
                continue;
            }
            $weight = (float) ($section['weight'] ?? 0);
            if ($weight <= 0) {
                continue;
            }
            $scoredCount++;
            $total += $weight;

            if ($weight < 0 || $weight > 100) {
                throw ValidationException::withMessages([
                    'sections' => ['Section weights must be between 0 and 100.'],
                ]);
            }
        }

        if ($scoredCount === 0) {
            throw ValidationException::withMessages([
                'sections' => ['Add at least one scored section with weight.'],
            ]);
        }

        if (abs($total - 100) > 0.01) {
            throw ValidationException::withMessages([
                'sections' => ["Section weights must sum to 100% (currently {$total}%)."],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $raw
     */
    public function isDefinitionV2(?array $raw): bool
    {
        return is_array($raw)
            && (int) ($raw['version'] ?? 0) === self::VERSION
            && isset($raw['sections'])
            && is_array($raw['sections']);
    }

    /**
     * @param  array<int, mixed>  $raw
     */
    public function isLegacySectionsList(array $raw): bool
    {
        if ($raw === []) {
            return false;
        }

        return array_is_list($raw) && is_array($raw[0] ?? null);
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function legacySectionsToDefinition(array $sections, array $meta = []): array
    {
        $mapped = [];
        foreach ($sections as $si => $section) {
            $questions = [];
            foreach ($section['questions'] ?? [] as $qi => $q) {
                $questions[] = [
                    'id' => $q['id'] ?? $this->slugId($q['title'] ?? "q_{$si}_{$qi}"),
                    'title' => $q['title'] ?? 'Question',
                    'type' => ($q['type'] ?? 'rating') === 'text' ? 'text' : 'rating',
                    'max' => (int) ($q['max'] ?? 5),
                    'required' => ($q['required'] ?? true) !== false,
                ];
            }
            $mapped[] = [
                'id' => $section['id'] ?? $this->slugId($section['title'] ?? "section_{$si}"),
                'title' => $section['title'] ?? 'Section',
                'weight' => (float) ($section['weight'] ?? 0),
                'questions' => $questions,
            ];
        }

        return [
            'version' => self::VERSION,
            'intro_html' => (string) ($meta['intro_html'] ?? ''),
            'scale' => ['min' => 1, 'max' => 5],
            'info_fields' => [
                'employee_name', 'position', 'department',
                'evaluation_period', 'evaluator_name', 'relationship',
            ],
            'sections' => $mapped,
            'comments' => $meta['comments'] ?? [],
            'signatures' => $meta['signatures'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $surveyJson
     * @return array<string, mixed>|null
     */
    public function surveyJsonToDefinition(array $surveyJson): ?array
    {
        if (empty($surveyJson['pages']) || ! is_array($surveyJson['pages'])) {
            return null;
        }

        $sections = [];
        $comments = [];
        $signatures = [];
        $introParts = [];

        foreach ($surveyJson['pages'] as $page) {
            if (! is_array($page)) {
                continue;
            }
            $elements = is_array($page['elements'] ?? null) ? $page['elements'] : [];

            foreach ($elements as $el) {
                if (is_array($el) && ($el['type'] ?? '') === 'html' && ! empty($el['html'])) {
                    $introParts[] = (string) $el['html'];
                }
            }

            $panels = array_values(array_filter($elements, fn ($el) => is_array($el) && ($el['type'] ?? '') === 'panel'));

            if ($panels !== []) {
                foreach ($panels as $panel) {
                    $questions = [];
                    foreach ($panel['elements'] ?? [] as $el) {
                        $q = $this->surveyElementToQuestion($el);
                        if ($q !== null) {
                            $questions[] = $q;
                        }
                    }
                    if ($questions !== []) {
                        $sections[] = [
                            'id' => $this->slugId($panel['name'] ?? $panel['title'] ?? 'section'),
                            'title' => $panel['title'] ?? $panel['name'] ?? 'Section',
                            'weight' => (float) ($panel['weight'] ?? 0),
                            'questions' => $questions,
                        ];
                    }
                }
            }
        }

        return [
            'version' => self::VERSION,
            'intro_html' => implode('', $introParts),
            'scale' => ['min' => 1, 'max' => 5],
            'info_fields' => [
                'employee_name', 'position', 'department',
                'evaluation_period', 'evaluator_name', 'relationship',
            ],
            'sections' => $sections,
            'comments' => $comments,
            'signatures' => $signatures,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function emptyDefinition(string $introHtml = ''): array
    {
        return [
            'version' => self::VERSION,
            'intro_html' => $introHtml,
            'scale' => ['min' => 1, 'max' => 5],
            'info_fields' => [
                'employee_name', 'position', 'department',
                'evaluation_period', 'evaluator_name', 'relationship',
            ],
            'sections' => [[
                'id' => 'section_1',
                'title' => 'Section 1',
                'weight' => 100,
                'questions' => [[
                    'id' => 'rating_1',
                    'title' => 'Rating question',
                    'type' => 'rating',
                    'max' => 5,
                    'required' => true,
                ]],
            ]],
            'comments' => [],
            'signatures' => [],
        ];
    }

    /**
     * @param  mixed  $el
     * @return array<string, mixed>|null
     */
    private function surveyElementToQuestion($el): ?array
    {
        if (! is_array($el)) {
            return null;
        }

        $type = $el['type'] ?? '';
        if (in_array($type, ['html', 'expression', 'panel'], true)) {
            return null;
        }

        $id = (string) ($el['name'] ?? $this->slugId($el['title'] ?? 'q'));

        if ($type === 'rating') {
            return [
                'id' => $id,
                'title' => $el['title'] ?? $el['name'] ?? 'Rating',
                'type' => 'rating',
                'max' => (int) ($el['rateMax'] ?? $el['rateCount'] ?? 5),
                'required' => ($el['isRequired'] ?? true) !== false,
            ];
        }

        if ($type === 'matrix') {
            $rows = [];
            foreach ($el['rows'] ?? [] as $i => $row) {
                if (is_string($row)) {
                    $rows[] = $row;
                } elseif (is_array($row)) {
                    $rows[] = (string) ($row['text'] ?? $row['value'] ?? "Row {$i}");
                }
            }

            return [
                'id' => $id,
                'title' => $el['title'] ?? $el['name'] ?? 'Matrix',
                'type' => 'matrix',
                'max' => (int) ($el['rateMax'] ?? 5),
                'rows' => $rows,
                'required' => ($el['isRequired'] ?? true) !== false,
            ];
        }

        if (in_array($type, ['comment', 'text'], true)) {
            return [
                'id' => $id,
                'title' => $el['title'] ?? $el['name'] ?? 'Comment',
                'type' => 'text',
                'required' => ($el['isRequired'] ?? false) === true,
            ];
        }

        return null;
    }

    private function slugId(string $name): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $name) ?? '', '_'));

        return $slug !== '' ? $slug : 'item_'.uniqid();
    }
}
