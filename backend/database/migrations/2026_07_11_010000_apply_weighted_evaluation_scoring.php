<?php

use App\Models\Evaluation;
use App\Models\EvaluationForm;
use App\Services\EvaluationScoringService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $scoring = app(EvaluationScoringService::class);

        EvaluationForm::query()
            ->whereNotNull('survey_json')
            ->orderBy('id')
            ->each(function (EvaluationForm $form) use ($scoring): void {
                $json = $form->survey_json;
                if (!is_array($json)) {
                    return;
                }

                $updated = $scoring->applyWeightedSummaryExpressions($json);
                $updated = $this->updateRatingGuideHtml($updated);

                if ($updated !== $json) {
                    $form->update(['survey_json' => $updated]);
                }
            });

        Evaluation::query()
            ->whereNotNull('scores')
            ->orderBy('id')
            ->each(function (Evaluation $evaluation) use ($scoring): void {
                $surveyData = $evaluation->scores['survey_data'] ?? null;
                if (!is_array($surveyData)) {
                    return;
                }

                $computed = $scoring->computeFromSurveyData($surveyData);
                if ($computed === null) {
                    return;
                }

                $scores = $evaluation->scores;
                $scores['survey_data']['overall_percentage'] = $computed['overall_percentage'];
                $scores['survey_data']['final_score'] = $computed['overall_score'];
                $scores['survey_data']['overall_rating'] = $computed['overall_rating'];
                foreach ($computed['section_scores'] as $key => $value) {
                    $scores['survey_data']["{$key}_score"] = $value;
                }

                $evaluation->update([
                    'scores' => $scores,
                    'overall_score' => $computed['overall_score'],
                    'overall_rating' => $computed['overall_rating'],
                ]);
            });
    }

    public function down(): void
    {
        // Prior scoring was incorrect; no safe rollback.
    }

    /**
     * @param  array<string, mixed>  $surveyJson
     * @return array<string, mixed>
     */
    private function updateRatingGuideHtml(array $surveyJson): array
    {
        $guide = <<<'HTML'
<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:0.75rem 1rem;">
  <h3 style="font-size:0.95rem;font-weight:700;color:#0f172a;margin-bottom:0.5rem;">PERFORMANCE RATING GUIDE</h3>
  <table style="width:100%;border-collapse:collapse;font-size:0.85rem;">
    <thead>
      <tr style="border-bottom:1px solid #e2e8f0;">
        <th style="padding:0.3rem 0.5rem;text-align:left;font-weight:600;color:#475569;">Overall Percentage</th>
        <th style="padding:0.3rem 0.5rem;text-align:left;font-weight:600;color:#475569;">Rating (1–5)</th>
        <th style="padding:0.3rem 0.5rem;text-align:left;font-weight:600;color:#475569;">Performance Level</th>
      </tr>
    </thead>
    <tbody>
      <tr><td style="padding:0.3rem 0.5rem;font-weight:600;color:#16a34a;">90.00% – 100.00%</td><td style="padding:0.3rem 0.5rem;">4.50 – 5.00</td><td style="padding:0.3rem 0.5rem;">Outstanding</td></tr>
      <tr style="background:#f1f5f9;"><td style="padding:0.3rem 0.5rem;font-weight:600;color:#0284c7;">70.00% – 89.99%</td><td style="padding:0.3rem 0.5rem;">3.50 – 4.49</td><td style="padding:0.3rem 0.5rem;">Very Good</td></tr>
      <tr><td style="padding:0.3rem 0.5rem;font-weight:600;color:#f59e0b;">50.00% – 69.99%</td><td style="padding:0.3rem 0.5rem;">2.50 – 3.49</td><td style="padding:0.3rem 0.5rem;">Good</td></tr>
      <tr style="background:#f1f5f9;"><td style="padding:0.3rem 0.5rem;font-weight:600;color:#f97316;">30.00% – 49.99%</td><td style="padding:0.3rem 0.5rem;">1.50 – 2.49</td><td style="padding:0.3rem 0.5rem;">Needs Improvement</td></tr>
      <tr><td style="padding:0.3rem 0.5rem;font-weight:600;color:#dc2626;">20.00% – 29.99%</td><td style="padding:0.3rem 0.5rem;">1.00 – 1.49</td><td style="padding:0.3rem 0.5rem;">Unsatisfactory</td></tr>
    </tbody>
  </table>
</div>
HTML;

        $walk = function (array &$elements) use (&$walk, $guide): void {
            foreach ($elements as &$el) {
                if (!is_array($el)) {
                    continue;
                }
                if (($el['type'] ?? '') === 'panel') {
                    $walk($el['elements']);
                    continue;
                }
                if (($el['name'] ?? '') === 'rating_guide' && ($el['type'] ?? '') === 'html') {
                    $el['html'] = $guide;
                }
            }
        };

        foreach ($surveyJson['pages'] ?? [] as &$page) {
            if (!is_array($page['elements'] ?? null)) {
                continue;
            }
            $walk($page['elements']);
        }

        return $surveyJson;
    }
};
