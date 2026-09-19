{{ $blueprint->getEmailSubject($translator) }}

{!! $translator->trans(
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
) !!}
