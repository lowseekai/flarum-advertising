{{ $blueprint->getEmailSubject($translator) }}

{!! $translator->trans('lowseekai-advertising.email.pending_review.body', [
    '{user}' => $blueprint->applicant->display_name,
    '{title}' => $blueprint->ad->title,
    '{slot}' => $blueprint->getData()['slotLabel'],
    '{position}' => $blueprint->getData()['slotPosition'] ?: '未指定',
    '{price}' => $blueprint->ad->total_price,
]) !!}
