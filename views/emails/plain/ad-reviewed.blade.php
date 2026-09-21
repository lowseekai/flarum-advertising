{{ $blueprint->getEmailSubject($translator) }}

{!! $translator->trans(
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
) !!}
