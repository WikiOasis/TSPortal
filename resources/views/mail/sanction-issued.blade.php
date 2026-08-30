<x-mail::message>
# An action has been taken on your account

**{{ $sanction->label }}** &mdash; reference **{{ $sanction->reference }}**

@if ($sanction->scope)
**Where it applies:** {{ $sanction->scope }}
@endif

**Given:** {{ $sanction->issued_at?->toDayDateTimeString() }}

@if ($sanction->expires_at)
**Ends:** {{ $sanction->expires_at->toDayDateTimeString() }}
@else
**Ends:** it does not expire on its own.
@endif

## Why

{{ $sanction->reason }}

@if ($sanction->appealable)
## If you think this is wrong

You can ask Trust & Safety to reconsider. You do not need to be able to log in to do it &mdash; the form below works either way, and asks for an address we can reply to.

<x-mail::button :url="$appealUrl">
Appeal this
</x-mail::button>
@else
This particular action cannot be appealed. If your circumstances change, you can still write to Trust & Safety through **Special:SafetyHome**.
@endif

<x-mail::button :url="$homeUrl" color="secondary">
See everything on file for your account
</x-mail::button>

Trust &amp; Safety<br>
{{ config('app.name') }}
</x-mail::message>
