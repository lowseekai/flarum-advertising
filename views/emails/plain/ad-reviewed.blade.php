{{ $blueprint->getEmailSubject($translator) }}

{!! $translator->trans(
    match ($blueprint->ad->status) {
        'approved' => 'lowseekai-advertising.email.reviewed.approved_body',
        'reserved' => 'lowseekai-advertising.email.reviewed.reserved_body',
        'cancelled', 'expired' => 'lowseekai-advertising.email.reviewed.cancelled_body',
        default => 'lowseekai-advertising.email.reviewed.rejected_body',
    },
    [
        '{title}' => $blueprint->ad->title,
        '{price}' => $blueprint->ad->total_price,
        '{slot}' => $blueprint->getData()['slotLabel'] ?? '-',
        '{startsAt}' => $blueprint->getData()['startsAt'] ?? '-',
        '{endsAt}' => $blueprint->getData()['endsAt'] ?? '-',
        '{estimatedStartAt}' => $blueprint->getData()['estimatedStartAt'] ?? '-',
        '{waitUntil}' => $blueprint->getData()['waitUntil'] ?? '-',
        '{note}' => $blueprint->ad->review_note ?: '-',
    ]
) !!}
