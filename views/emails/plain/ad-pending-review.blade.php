{{ $blueprint->getEmailSubject($translator) }}

{!! $translator->trans('lowseekai-advertising.email.pending_review.body', [
    '{user}' => $blueprint->applicant->display_name,
    '{title}' => $blueprint->ad->title,
    '{slot}' => $blueprint->getData()['slotLabel'] ?? '-',
    '{price}' => $blueprint->ad->total_price,
]) !!}
