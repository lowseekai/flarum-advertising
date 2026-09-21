{{ $blueprint->getEmailSubject($translator) }}

{!! $translator->trans(
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
        '{endsAt}' => $blueprint->getData()['endsAt'] ?? '-',
        '{reason}' => $blueprint->reason ?: '-',
    ]
) !!}
