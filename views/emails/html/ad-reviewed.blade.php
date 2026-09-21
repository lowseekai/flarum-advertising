<!DOCTYPE html>
<html lang="{{ $translator->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ $blueprint->getEmailSubject($translator) }}</title>
</head>
<body style="font-family:Arial,Helvetica,sans-serif;color:#333;line-height:1.6;">
    <h2>{{ $blueprint->getEmailSubject($translator) }}</h2>
    {!! $formatter->convert($translator->trans(
        $blueprint->ad->status === 'approved'
            ? 'lowseekai-advertising.email.reviewed.approved_body'
            : 'lowseekai-advertising.email.reviewed.rejected_body',
        [
            '{title}' => $blueprint->ad->title,
            '{price}' => $blueprint->ad->total_price,
            '{slot}' => $blueprint->getData()['slotLabel'] ?? '-',
            '{startsAt}' => $blueprint->getData()['startsAt'] ?? '-',
            '{endsAt}' => $blueprint->getData()['endsAt'] ?? '-',
            '{note}' => $blueprint->ad->review_note ?: '-',
        ]
    )) !!}
</body>
</html>
