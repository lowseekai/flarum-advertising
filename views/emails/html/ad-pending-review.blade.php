<!DOCTYPE html>
<html lang="{{ $translator->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ $blueprint->getEmailSubject($translator) }}</title>
</head>
<body style="font-family:Arial,Helvetica,sans-serif;color:#333;line-height:1.6;">
    <h2>{{ $blueprint->getEmailSubject($translator) }}</h2>
    {!! $formatter->convert($translator->trans('lowseekai-advertising.email.pending_review.body', [
        '{user}' => $blueprint->applicant->display_name,
        '{title}' => $blueprint->ad->title,
        '{slot}' => $blueprint->getData()['slotLabel'] ?? '-',
        '{price}' => $blueprint->ad->total_price,
    ])) !!}
</body>
</html>
