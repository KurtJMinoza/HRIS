<?php

use App\Models\EmailTemplate;
use App\Support\AgcEmailTemplateBuilder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $signature = AgcEmailTemplateBuilder::SIGNATURE_NAME;

        EmailTemplate::query()->each(function (EmailTemplate $template) use ($signature): void {
            $html = (string) $template->body_html;
            if ($html === '') {
                return;
            }

            $updated = str_replace('{{ company_name }}', $signature, $html);
            if ($updated !== $html) {
                $template->update(['body_html' => $updated]);
            }
        });
    }

    public function down(): void
    {
        $signature = AgcEmailTemplateBuilder::SIGNATURE_NAME;

        EmailTemplate::query()->each(function (EmailTemplate $template) use ($signature): void {
            $html = (string) $template->body_html;
            if ($html === '') {
                return;
            }

            $updated = str_replace($signature, '{{ company_name }}', $html);
            if ($updated !== $html) {
                $template->update(['body_html' => $updated]);
            }
        });
    }
};
