<?php

use App\Models\EvaluationForm;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        EvaluationForm::query()
            ->whereNotNull('survey_json')
            ->orderBy('id')
            ->each(function (EvaluationForm $form): void {
                $json = $form->survey_json;
                if (!is_array($json)) {
                    return;
                }

                $normalized = $this->normalizeExpressions($json);
                if ($normalized !== $json) {
                    $form->update(['survey_json' => $normalized]);
                }
            });
    }

    public function down(): void
    {
        // Expressions were invalid before; no safe rollback.
    }

    /**
     * @param  array<string, mixed>  $surveyJson
     * @return array<string, mixed>
     */
    private function normalizeExpressions(array $surveyJson): array
    {
        foreach ($surveyJson['pages'] ?? [] as $pageIndex => $page) {
            if (!is_array($page)) {
                continue;
            }
            $surveyJson['pages'][$pageIndex]['elements'] = $this->normalizeElements($page['elements'] ?? []);
        }

        return $surveyJson;
    }

    /**
     * @param  array<int, mixed>  $elements
     * @return array<int, mixed>
     */
    private function normalizeElements(array $elements): array
    {
        foreach ($elements as $index => $el) {
            if (!is_array($el)) {
                continue;
            }

            if (($el['type'] ?? '') === 'panel') {
                $elements[$index]['elements'] = $this->normalizeElements($el['elements'] ?? []);
                continue;
            }

            if (($el['type'] ?? '') === 'expression' && isset($el['expression']) && is_string($el['expression'])) {
                $elements[$index]['expression'] = preg_replace(
                    '/\{([a-zA-Z_][\w]*)\[(\d+)\]\}/',
                    '{$1.$2}',
                    $el['expression'],
                );
            }
        }

        return $elements;
    }
};
