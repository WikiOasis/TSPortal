<x-mail::message>
# Trust & Safety has your {{ $noun }}

Your {{ $noun }} was received and given the reference **{{ $case->reference }}**. Please quote it if you write to us about this again.

Someone will read it. We do not put a time on that, because the honest answer depends on what else is open — but you do not need to do anything else for now, and we will write here if we need more from you.

<x-mail::button :url="$homeUrl">
Follow it on the wiki
</x-mail::button>

You can read the conversation on this {{ $noun }}, and add to it, from **Special:SafetyHome** while you are logged in.

Trust &amp; Safety<br>
{{ config('app.name') }}

<x-mail::subcopy>
This message went to the address held for your account. Nothing about this {{ $noun }} is quoted here on purpose — email is not a private channel, and the details stay behind your login.
</x-mail::subcopy>
</x-mail::message>
