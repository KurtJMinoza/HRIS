<?php

use App\Models\Evaluation;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $jobMatrices = ['quality_of_work', 'productivity', 'accountability', 'communication', 'problem_solving'];

        Evaluation::query()
            ->whereNotNull('scores')
            ->orderBy('id')
            ->each(function (Evaluation $evaluation) use ($jobMatrices): void {
                $surveyData = $evaluation->scores['survey_data'] ?? null;
                if (!is_array($surveyData)) {
                    return;
                }

                $jobValues = [];
                foreach ($jobMatrices as $name) {
                    foreach (($surveyData[$name] ?? []) as $value) {
                        if (is_numeric($value)) {
                            $jobValues[] = (float) $value;
                        }
                    }
                }

                $coreValues = [];
                foreach (($surveyData['core_values'] ?? []) as $value) {
                    if (is_numeric($value)) {
                        $coreValues[] = (float) $value;
                    }
                }

                if ($jobValues === [] && $coreValues === []) {
                    return;
                }

                $jobAvg = $jobValues !== [] ? array_sum($jobValues) / count($jobValues) : 0.0;
                $coreAvg = $coreValues !== [] ? array_sum($coreValues) / count($coreValues) : 0.0;
                $score = round($jobAvg * 0.70 + $coreAvg * 0.30, 2);

                $rating = match (true) {
                    $score >= 4.5 => 'Outstanding',
                    $score >= 3.5 => 'Very Good',
                    $score >= 2.5 => 'Good',
                    $score >= 1.5 => 'Needs Improvement',
                    default => 'Unsatisfactory',
                };

                $evaluation->update([
                    'overall_score' => $score,
                    'overall_rating' => $rating,
                ]);
            });
    }

    public function down(): void
    {
        // Scores were wrong before; no safe rollback.
    }
};
