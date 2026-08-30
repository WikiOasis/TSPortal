<x-mail::message>
# There is an update on {{ $case->reference }}

@if ($isReply)
Trust & Safety has replied on your case.
@elseif ($previousLabel)
The status changed from **{{ $previousLabel }}** to **{{ $statusLabel }}**.
@else
The status is now **{{ $statusLabel }}**.
@endif

<x-mail::button :url="$homeUrl">
Read it on the wiki
</x-mail::button>

You can reply there, and doing so reopens the case if it had been closed.

Trust &amp; Safety<br>
{{ config('app.name') }}

<x-mail::subcopy>
The update itself is not repeated in this email. It is behind your login, where only you can read it.
</x-mail::subcopy>
</x-mail::message>
