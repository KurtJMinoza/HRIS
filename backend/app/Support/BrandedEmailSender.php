<?php

namespace App\Support;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;

class BrandedEmailSender
{
    public const LOGO_PLACEHOLDER = '{{ logo_url }}';

    public const PUBLIC_LOGO_PATH = '/api/email/logo.png';

    public static function logoPath(): ?string
    {
        foreach ([
            public_path('logo/AGCTek.png'),
            public_path('logo/AGC_DARK.png'),
        ] as $path) {
            if (is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    public static function publicLogoUrl(): ?string
    {
        $explicit = config('mail.logo_url');
        if (is_string($explicit) && trim($explicit) !== '') {
            return trim($explicit);
        }

        foreach ([
            config('app.url'),
            config('app.frontend_url'),
        ] as $baseUrl) {
            if (! is_string($baseUrl) || trim($baseUrl) === '') {
                continue;
            }

            $baseUrl = rtrim(trim($baseUrl), '/');
            if (self::isPublicHttpUrl($baseUrl)) {
                return $baseUrl.self::PUBLIC_LOGO_PATH;
            }
        }

        return null;
    }

    public static function previewLogoSrc(): string
    {
        return self::publicLogoUrl() ?: AgcEmailTemplateBuilder::embeddedLogoDataUri();
    }

    public static function send(string $to, string $subject, string $bodyHtml): void
    {
        $logoPath = self::logoPath();
        $publicLogoUrl = self::publicLogoUrl();

        Mail::send(
            'emails.branded-notification',
            [
                'bodyHtml' => $bodyHtml,
                'logoPath' => $logoPath,
                'publicLogoUrl' => $publicLogoUrl,
            ],
            function (Message $message) use ($to, $subject, $bodyHtml, $logoPath, $publicLogoUrl): void {
                $fromAddress = (string) config('mail.from.address');
                $fromName = (string) config('mail.from.name');
                $replyAddress = (string) config('mail.reply_to.address', $fromAddress);
                $replyName = (string) config('mail.reply_to.name', $fromName);

                $message->from($fromAddress, $fromName);
                $message->to($to);
                $message->subject($subject);
                $message->replyTo($replyAddress, $replyName);

                $previewHtml = self::renderPreviewHtml($bodyHtml, $logoPath, $publicLogoUrl);
                $message->text(self::toPlainText($previewHtml));

                $symfony = $message->getSymfonyMessage();
                $symfony->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'OOF, AutoReply');
                $symfony->getHeaders()->addTextHeader('X-Entity-Ref-ID', 'agctek-hris-'.sha1($to.$subject));
            }
        );
    }

    public static function prepareHtml(string $bodyHtml, ?string $logoPath = null, ?string $publicLogoUrl = null): string
    {
        $bodyHtml = AgcEmailTemplateBuilder::normalizeLogoImgTag($bodyHtml);
        $publicLogoUrl ??= self::publicLogoUrl();

        if ($publicLogoUrl !== null) {
            return str_replace(self::LOGO_PLACEHOLDER, $publicLogoUrl, $bodyHtml);
        }

        return $bodyHtml;
    }

    public static function renderPreviewHtml(string $bodyHtml, ?string $logoPath = null, ?string $publicLogoUrl = null): string
    {
        $logoPath ??= self::logoPath();
        $publicLogoUrl ??= self::publicLogoUrl();

        if ($publicLogoUrl !== null) {
            return self::replaceLogoPlaceholder($bodyHtml, $publicLogoUrl);
        }

        $logoSrc = AgcEmailTemplateBuilder::embeddedLogoDataUri();
        if ($logoSrc === '') {
            return self::replaceLogoPlaceholder($bodyHtml, '');
        }

        return self::replaceLogoPlaceholder($bodyHtml, $logoSrc);
    }

    private static function replaceLogoPlaceholder(string $html, string $logoSrc): string
    {
        $html = AgcEmailTemplateBuilder::normalizeLogoImgTag($html);

        return str_replace(self::LOGO_PLACEHOLDER, $logoSrc, $html);
    }

    private static function isPublicHttpUrl(string $url): bool
    {
        if (! preg_match('#^https://#i', $url)) {
            return false;
        }

        return ! preg_match('#(localhost|127\.0\.0\.1)#i', $url);
    }

    private static function toPlainText(string $html): string
    {
        $text = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html) ?? $html;
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text) ?? $text;
        $text = preg_replace('/<\/(p|h1|h2|h3|tr)>/i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
