<x-mail::html.notification>
    <x-slot:body>
        {!! $formatter->convert($translator->trans('lowseekai-advertising.email.pending_review.body', [
            '{user}' => $blueprint->applicant->display_name,
            '{title}' => $blueprint->ad->title,
            '{slot}' => $blueprint->getData()['slotLabel'],
            '{price}' => $blueprint->ad->total_price,
        ])) !!}
    </x-slot:body>
</x-mail::html.notification>
