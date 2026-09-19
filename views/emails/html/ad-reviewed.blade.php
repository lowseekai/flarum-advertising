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
            '{slot}' => $blueprint->getData()['slotLabel'],
            '{position}' => $blueprint->getData()['slotPosition'] ?: '未指定',
            '{startsAt}' => $blueprint->getData()['startsAt'] ?: '审核通过后生成',
            '{endsAt}' => $blueprint->getData()['endsAt'] ?: '审核通过后生成',
            '{note}' => $blueprint->ad->review_note ?: '无',
        ]
    )) !!}
</body>
</html>
