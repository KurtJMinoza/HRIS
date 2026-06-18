<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin:0;padding:0;">
@php
    $logoPath = $logoPath ?? \App\Support\BrandedEmailSender::logoPath();
    $publicLogoUrl = $publicLogoUrl ?? \App\Support\BrandedEmailSender::publicLogoUrl();

    if ($publicLogoUrl) {
        $logoSrc = $publicLogoUrl;
    } elseif ($logoPath) {
        $logoSrc = $message->embedData(
            (string) file_get_contents($logoPath),
            'AGCTek.png',
            'image/png',
        );
    } else {
        $logoSrc = '';
    }

    $renderedHtml = str_replace(\App\Support\BrandedEmailSender::LOGO_PLACEHOLDER, $logoSrc, $bodyHtml);
@endphp
{!! $renderedHtml !!}
</body>
</html>
