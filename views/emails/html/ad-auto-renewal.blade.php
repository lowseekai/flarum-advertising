<!DOCTYPE html>
<html lang="{{ $translator->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ $blueprint->getEmailSubject($translator) }}</title>
</head>
<body style="font-family:Arial,Helvetica,sans-serif;color:#333;line-height:1.6;">
    <h2>{{ $blueprint->getEmailSubject($translator) }}</h2>
    {!! $formatter->convert($translator->trans(
        match ($blueprint->event) {
            'enabled' => 'lowseekai-advertising.email.auto_renewal.enabled_body',
            'succeeded' => 'lowseekai-advertising.email.auto_renewal.succeeded_body',
            'price_changed' => 'lowseekai-advertising.email.auto_renewal.price_changed_body',
            default => 'lowseekai-advertising.email.auto_renewal.failed_body',
        },
        [
            '{title}' => $blueprint->ad->title,
            '{amount}' => $blueprint->amount,
            '{slot}' => $blueprint->getData()['slotLabel'],
            '{position}' => $blueprint->getData()['slotPosition'] ?? '-',
            '{endsAt}' => $blueprint->getData()['endsAt'] ?? '-',
            '{reason}' => $blueprint->reason ?: '-',
        ]
    )) !!}
</body>
</html>
