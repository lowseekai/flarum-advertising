<x-mail::plain.notification>
<x-slot:body>
{!! $translator->trans(
    $blueprint->ad->status === 'approved'
        ? 'lowseekai-advertising.email.reviewed.approved_body'
        : 'lowseekai-advertising.email.reviewed.rejected_body',
    [
        '{title}' => $blueprint->ad->title,
        '{price}' => $blueprint->ad->total_price,
        '{slot}' => $blueprint->getData()['slotLabel'],
        '{position}' => $blueprint->getData()['slotPosition'] ?: '未指定',
        '{endsAt}' => $blueprint->getData()['endsAt'] ?: '无',
        '{note}' => $blueprint->ad->review_note ?: '无',
    ]
) !!}
</x-slot:body>
</x-mail::plain.notification>
