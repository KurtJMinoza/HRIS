<?php

namespace App\Support;

class AgcEmailTemplateBuilder
{
    private const FONT = 'Arial,Helvetica,sans-serif';

    private const BRAND = '#ea580c';

    private const LOGO_WIDTH = 150;

    public static function logoUrl(): string
    {
        return BrandedEmailSender::LOGO_PLACEHOLDER;
    }

    public static function embeddedLogoDataUri(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $path = BrandedEmailSender::logoPath();
        if ($path === null) {
            $cached = '';

            return $cached;
        }

        $mime = str_ends_with(strtolower($path), '.png') ? 'image/png' : 'image/jpeg';
        $cached = 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));

        return $cached;
    }

    public static function layout(string $innerHtml, ?string $preheader = null): string
    {
        $company = '{{ company_name }}';
        $preheaderHtml = $preheader
            ? '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#eceff3;">'.e($preheader).'</div>'
            : '';

        return '<!DOCTYPE html>'
            .'<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            .'<body style="margin:0;padding:0;background:#eceff3;font-family:'.self::FONT.';">'
            .$preheaderHtml
            .'<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#eceff3;">'
            .'<tr><td align="center" style="padding:32px 16px;">'
            .'<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;width:100%;border-collapse:collapse;background:#ffffff;border:1px solid #d9dee7;box-shadow:0 8px 24px rgba(15,23,42,0.06);">'
            .'<tr><td style="padding:0;">'
            .'<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">'
            .'<tr><td style="padding:22px 32px 18px;background:#ffffff;text-align:left;">'
            .'<img src="'.BrandedEmailSender::LOGO_PLACEHOLDER.'" alt="AGCTEK" width="'.self::LOGO_WIDTH.'" style="display:block;height:auto;border:0;outline:none;text-decoration:none;max-width:'.self::LOGO_WIDTH.'px;max-height:52px;" />'
            .'</td></tr>'
            .'<tr><td style="height:3px;background:'.self::BRAND.';font-size:0;line-height:0;">&nbsp;</td></tr>'
            .'</table>'
            .'</td></tr>'
            .'<tr><td style="padding:30px 32px 10px;font-family:'.self::FONT.';color:#334155;">'
            .trim($innerHtml)
            .'</td></tr>'
            .'<tr><td style="padding:18px 32px 26px;background:#f8fafc;border-top:1px solid #e8edf3;font-family:'.self::FONT.';">'
            .'<p style="margin:0;font-size:12px;line-height:1.6;color:#64748b;font-weight:600;">'.$company.'</p>'
            .'<p style="margin:6px 0 0;font-size:12px;line-height:1.6;color:#94a3b8;">You received this message because your organization uses AGCTEK HRIS for workforce notifications.</p>'
            .'<p style="margin:6px 0 0;font-size:12px;line-height:1.6;color:#94a3b8;">This is an automated email. Please do not reply directly to this message.</p>'
            .'</td></tr>'
            .'</table></td></tr></table></body></html>';
    }

    public static function title(string $text): string
    {
        return '<h1 style="margin:0 0 20px;font-family:'.self::FONT.';font-size:20px;line-height:1.35;font-weight:700;color:#0f172a;letter-spacing:-0.01em;">'
            .trim($text)
            .'</h1>';
    }

    public static function greeting(string $name = '{{ employee_name }}'): string
    {
        return '<p style="margin:0 0 16px;font-family:'.self::FONT.';font-size:15px;line-height:1.65;color:#475569;">Dear '.$name.',</p>';
    }

    public static function greetingGeneric(): string
    {
        return '<p style="margin:0 0 16px;font-family:'.self::FONT.';font-size:15px;line-height:1.65;color:#475569;">Dear Sir/Madam,</p>';
    }

    public static function paragraph(string $html): string
    {
        return '<p style="margin:0 0 16px;font-family:'.self::FONT.';font-size:15px;line-height:1.65;color:#475569;">'.$html.'</p>';
    }

    /**
     * @param  list<array{label: string, value: string}>  $rows
     */
    public static function infoTable(array $rows): string
    {
        $html = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:20px 0 22px;border-collapse:collapse;background:#f8fafc;border:1px solid #e8edf3;font-family:'.self::FONT.';">';

        foreach ($rows as $index => $row) {
            $border = $index > 0 ? 'border-top:1px solid #e8edf3;' : '';
            $html .= '<tr>'
                .'<td style="padding:12px 16px;width:36%;font-size:13px;line-height:1.5;color:#64748b;vertical-align:top;'.$border.'">'.trim($row['label']).'</td>'
                .'<td style="padding:12px 16px;font-size:14px;line-height:1.5;color:#0f172a;font-weight:600;vertical-align:top;'.$border.'">'.trim($row['value']).'</td>'
                .'</tr>';
        }

        return $html.'</table>';
    }

    public static function cta(string $label = 'Open HRIS', string $url = '{{ action_url }}'): string
    {
        return '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:24px 0 8px;">'
            .'<tr><td style="border-radius:6px;background:'.self::BRAND.';">'
            .'<a href="'.$url.'" style="display:inline-block;padding:12px 22px;font-family:'.self::FONT.';font-size:14px;font-weight:700;line-height:1;color:#ffffff;text-decoration:none;border-radius:6px;">'
            .trim($label)
            .'</a></td></tr></table>';
    }

    public static function closing(): string
    {
        return '<p style="margin:20px 0 0;font-family:'.self::FONT.';font-size:15px;line-height:1.65;color:#475569;">'
            .'Regards,<br><span style="color:#0f172a;font-weight:700;">{{ company_name }}</span>'
            .'</p>';
    }
}
