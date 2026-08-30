# TSPortal

The WikiOasis Trust & Safety queue: where reports filed on the wikis are read,
answered and acted on.

It is one half of a pair. The other is the
[WikiOasisSafety](../WikiOasisSafety) MediaWiki extension, which is what a
reader sees — the reporting wizard, `Special:SafetyHome`, and the message a
suspended account gets when it tries to log in. This portal is what Trust &
Safety sees, and it is the record: the wiki keeps a copy so that its own pages
render from a local read, but everything is decided here.

- Laravel 13, PHP 8.3+, MySQL
- A Vue 3 single-page app built from [Codex](https://doc.wikimedia.org/codex/),
  Wikimedia's design system, so it looks like the wikis it serves
- MediaWiki OAuth 2.0 for signing in — there is no password here
- HMAC-signed requests in both directions between portal and wiki

## Getting it running

```sh
composer install
npm install
cp .env.example .env && php artisan key:generate

# Point it at your database, then:
php artisan migrate
php artisan db:seed --class=DemoSeeder   # optional, invents a queue to look at

npm run build          # or `npm run dev` while working on the front end
php artisan serve
```

Two more things need setting up before it does anything real.

### 1. An OAuth consumer

Register an OAuth **2.0** client at `Special:OAuthConsumerRegistration` on the
central wiki, with the callback pointing at `/auth/mediawiki/callback` here.
`basic` scope is enough: the portal only needs to know who is at the keyboard.

```ini
MW_CENTRAL_URL=https://meta.wikioasis.org
MW_OAUTH_CLIENT_ID=…
MW_OAUTH_CLIENT_SECRET=…

# Your own username, for the very first sign-in. Nobody can grant you access
# yet, so this does it — then empty it again.
MW_BOOTSTRAP_ADMINS=YourName
```

Acting on the wiki is done by the portal's own service account, not with a
staff member's token. An action taken by Trust & Safety is taken by Trust &
Safety: attributing a farm-wide lock to whoever happened to click the button
misstates who decided it, and breaks when that person's token expires.

### 2. A shared secret

```sh
php artisan tsportal:secret
```

Put it in `MW_S2S_SECRET` here and `$wgWikiOasisSafetyPortalSecret` in the
wiki's `LocalSettings.php`. It is what the two installations authenticate to
each other with, and anyone holding it can file reports and read any account's
standing — treat it as a password.

### 3. A queue worker, and a cron line to feed it

```sh
php artisan queue:work --queue=default

* * * * * cd /path/to/tsportal && php artisan schedule:run >> /dev/null 2>&1
```

**The worker is where the background work happens.** All of it — pushing to the
wiki, walking erasures through their stages, retiring expired actions, posting to
Slack. Keep one running under systemd or Supervisor.

**Pushing to the wiki is not on a timer.** Writing a row to `outbound_events`
dispatches `App\Jobs\SyncToWiki`, so a change reaches `Special:SafetyHome` in the
second after it happens rather than in the minute after it, and a portal where
nothing happened overnight does nothing overnight. A pass that has to leave an
event backed off books its own next pass before it finishes, which is what
replaced the every-minute sweep.

**The cron line is only for the two things a clock has to notice.** Nothing
happens when a sanction's expiry passes or when CentralAuth finishes a rename, so
`ExpireDueSanctions` (every ten minutes) and `AdvanceDataRemovals` (every minute)
stay on a schedule — but `schedule:run` only enqueues them, and the worker does
the work. The first matters more than it looks: nothing edits a sanction when its
time runs out, so without it the wiki goes on refusing a login for a ban that
ended overnight, and the person it affects cannot log in to tell anyone.

The three commands are all still there for driving by hand — `tsportal:sync`
(with `--retry-stuck` for events that have given up), `tsportal:expire-sanctions`,
and `tsportal:advance-removals --reference=…`. They run in the foreground and
print, which is the reason to type one.

## How it fits together

```
  a reader on the wiki                    Trust & Safety
  ────────────────────                    ──────────────
  Special:SafetyHome  ─┐                 ┌─  the queue, /queue
  the report wizard    │                 │   a file, /files/…
  the login screen     │                 │   an account, /accounts/…
                       │                 │   actions, /actions
                       │                 │
              ┌────────▼─────────┐       │
              │  WikiOasisSafety │       │
              │    (extension)   │       │
              └──┬────────────▲──┘       │
                 │            │          │
         signed  │            │  signed  │
         submit  │            │  sync +  │
        comment  │            │  enforce │
         appeal  │            │          │
              ┌──▼────────────┴──────────▼──┐
              │          TSPortal           │
              │  cases · investigations ·   │
              │  accounts · actions ·       │
              │  erasures · audit log       │
              └─────────────────────────────┘
```

**The wiki never reads from here on a page view.** It holds a mirror, kept up to
date by the portal pushing changes as they happen, so `Special:SafetyHome` is a
database read. A page someone opens at a bad moment must not depend on a service
somewhere else being awake.

**Nothing here fails because the wiki is down.** Every outbound change is a row
in `outbound_events` before it is a request, drained by a queued job with
exponential backoff. A wiki that is out for a day replays a day of activity when
it comes back, in order. What it cannot do is fail silently: the dashboard
counts what is queued and what has given up trying.

## The domain

| Table | What it holds |
| --- | --- |
| `users` | Trust & Safety staff. No password column: OAuth is the only way in |
| `subjects` | Accounts the portal holds something about — including names that resolve to no account, which are usually the ones needing a person. `erased_at` is when the wiki erased one, and when the portal's own retention clock started |
| `cases` | Reports, appeals, messages and data requests, in one table |
| `case_comments` | The conversation, with `visibility` deciding what leaves the portal |
| `cases.data_*` | What a data request asked for, and what was decided |
| `investigations` | The work: what reports feed into and what actions come out of. Internal, always |
| `investigation_notes` | The reasoning on a file, in order. No visibility column, because there is no visibility to choose |
| `sanctions` | Actions, and whether the wiki actually carried each one out |
| `case_categories` | What each submission was about, in the words its reporter was shown |
| `checkuser_checks` | Who ran a CheckUser check, why, and on which wiki. Never what one returned |
| `transparency_reports` | A period's figures, frozen on the day they were published |
| `data_removals` | Erasures, and the record that has to survive one |
| `portal_objects` | Every reference the portal has allocated, and what it names |
| `wikis` | The farm, as the portal last heard. A cache |
| `attachments` | Evidence. A row per file, filled in as the bytes arrive; the disk each went to is on the row |
| `audit_logs` | Append-only. Nothing in the portal edits or deletes a row |
| `outbound_events` | What the wiki has not been told yet |

Nine decisions in there are load-bearing:

**One numbering scheme for everything.** `TS-2026-0481`, from one counter, whether
it names a report, an investigation, an action or an erasure. There used to be
four prefixes and four counters, which meant two objects could hold the same
number and a reference could not be resolved without first guessing its type —
which is exactly the knowledge somebody holding a bare number does not have.
`portal_objects` answers "what is TS-2026-0481?", and the search box at the top of
every page is that question. Old `AP-`, `DR-`, `CT-` and `AC-` references still
resolve; nothing renumbers the past.

**An investigation is where the work lives.** A report is a request and an action
is a consequence; neither is the work. Before this there was nowhere to put "these
six reports are one pattern" except the order somebody happened to close them in.
Reports attach to a file, actions are issued *out of* one — `SanctionService`
requires it — and concluding a file answers every report on it in one go, with the
same outcome. Nothing on a file is ever mirrored: there is no `synced_at` column
and `WikiSync` has no method that takes one.

**One table for every kind of submission.** The wizards differ in what they ask,
not in what happens next: each becomes a thing with a reference, a status, a
conversation and an outcome. One table means the queue, the comment thread, the
audit trail and the sync are written once.

**`answers` is kept verbatim and never parsed.** The questions live in the
wiki's `LocalSettings.php` and can be rewritten there without redeploying this,
so a schema over them would mean a wiki renaming a field silently losing every
report. The portal reads only the field *roles* the wiki declares alongside.

**Comments default to internal.** That is the wrong default for the common case
and the right one for the dangerous one: an internal note accidentally made
public is a disclosure, and a public reply accidentally kept internal is a
reporter waiting a day longer. Two buttons, not a remembered dropdown.

**An anonymous report is never listed back to anyone.** It is not attached to an
account, so there is nobody it could correctly be shown to. It is filtered in a
query scope rather than in a controller, and never mirrored to the wiki at all.

**A category is the wiki's word, and it is stored rather than looked up.** The
vocabulary lives in each wiki's `LocalSettings.php` and the extension resolves it
server-side before sending — see `Categories.php` there — so the label and the
group travel with the row. A wiki that rewrites its flow next year does not
retroactively re-describe a report in words nobody was ever shown, and a figure in
a published document keeps meaning what it meant. `cases.category` is the primary,
denormalised for the queue's filter; `case_categories` is all of them, because a
report can be harassment *and* a threat of physical harm and counting it as one
would under-report the other.

**Why an action was taken is the portal's word, and it is fixed.** The opposite
choice from the one above, deliberately. A report category describes what a member
of the public said, in the words their wiki offered them; an action category
describes what Trust & Safety decided, which is one team applying one policy
across the farm — a per-wiki list there would count the same decision under two
names depending on where it happened. The list is `config/categories.php`, and a
test keeps it in step with the dropdown in `format.js`.

**A published transparency report never moves.** A dashboard is always right
about now; a report is right about a period and other people quote it. So the
figures are computed once into `figures` and rendered from there, small counts are
banded *when the figures are built* rather than when they are shown, and
`regenerate` returns a 409 once a report is published. A correction is a new
report that says what it corrects.

## What the portal can actually do to a wiki

The list in `MW_SUPPORTED_ACTIONS` is what the extension can carry out. Anything
outside it is recorded and marked `manual`, which shows up as work waiting for
somebody.

**Those are the extension's task names, not the portal's type names**, and they are
not always the same word: a `warning` is sent as `warn`, a `wiki-deletion` as
`delete-wiki`. `WikiClient::TASKS` is the authoritative list.

This mattered more than it looks. Nothing used to check the setting, and a name
that matched no task was not an error — it silently meant "the extension cannot do
this". So a configuration reading `warning,wiki-deletion,block` switched warnings
and wiki deletions *off* while looking exactly like one that switched them on, and
`block` missing from the default meant blocking never worked at all: every block
was recorded, marked as needing a person, and never sent. Unrecognised names are
now filtered out of the usable list and reported on the dashboard in words.

Three of those states are worth telling apart, and the portal does:

| State | Means |
| --- | --- |
| `pushed` | The wiki did it |
| `recorded` | Nothing to send. A note, or something a person did somewhere the portal cannot reach — complete, not outstanding |
| `queued` | The wiki has it and is working through it, one project at a time |
| `manual` | The portal wanted to and the extension cannot yet. Work waiting for a person |
| `partial` | **The wiki took the record and did not confirm the enforcement.** The worst state in the system |
| `failed` | It tried and could not |

`queued` exists because a block is not one operation. It is a job per named wiki —
a block lives in one wiki's own tables and only that wiki's code can write it — so
the reply to "block on these six wikis" is "six jobs queued", which is not the same
as "blocked". That used to be recorded as `pushed`, so a job that failed on two of
the six left the portal saying the block was in force everywhere. Each wiki reports
back through `Api\Wiki\ActionProgressController`, and the state only becomes
`pushed` when the whole list has; a mix ends up `partial` and names which wikis it
is actually in force on.

`partial` exists because of one specific hole. A suspension used to be a row in
the extension's `wos_standing` table and nothing else — and a row in an extension
table is not a lock. It is read by one authentication provider on one login form.
It does not stop an API login, a bot password, a session that was already open, or
an OAuth grant made last year. So the portal said suspended, the account's own
standing page agreed, the case was closed, and the account carried on editing.

`lock` now asks for a CentralAuth lock as well and the extension reports the two
halves separately, so "row written, lock not confirmed" is a state the dashboard
can name in words instead of one nobody could see.

### The actions

`note`, `warning`, `block`, `lock`, `wiki-deletion`, and `other`.

**A block names wikis.** It always did — a block is a local thing on a MediaWiki
install, and "blocked" without saying where is not something anybody can act on or
appeal against. The old free-text `scope` column read fine and could not be
enforced, pushed or counted; `sanctions.wikis` is the list, and the extension
blocks on exactly those. The wikis are picked from `wikis`, a cache the farm
pushes, because a block on `oasisexampl` is an action the portal believes it took
and no wiki ever heard of.

**Interaction bans and partial blocks are gone.** Neither was ever enforceable
from here: both sat marked "needs doing by hand" indefinitely, which records an
intention rather than an action. Rows that already hold those types keep them and
keep rendering — a decision that was taken was taken.

**`other` is for what the portal has no verb for.** "Removal of content",
"referred to the police", "asked the host to take the file down". It writes its own
label and is marked `recorded`, because a file whose actions are only the
automatable ones misrepresents what was done — and the reference on a logged
action is what an appeal or an audit points at.

**Deleting a wiki is the one action with no account at the other end of it**, which
is why `sanctions.subject_id` is nullable. It goes through CreateWiki's
`RemoteWiki`, because the farm's list of wikis is CreateWiki's and a wiki deleted
anywhere else is one the farm still believes exists — it would carry on being
listed, indexed and served. `delete()` marks it: the wiki stops being reachable
immediately and the databases are dropped later by the farm's own retention job,
which is the behaviour worth having rather than an immediate drop. Deleting a wiki
over content that turns out to have been misreported should be recoverable for as
long as the farm allows, and lifting the action is what recovers it.

## Answering a data request

The other three kinds of case are answered by somebody deciding they are. A data
request is not: it is a legal obligation, and "closed" says nothing about whether
it was discharged.

So a data request carries its own kind and its own decision. **Which kind it is
comes first and blocks everything else** — approving without knowing what was
asked for is how a request to correct something gets answered by deleting the
account, and that direction does not come back.

The kind is a guess the wizard supplies and a person confirms. It has to be that
way round: the questions live in the wiki's `LocalSettings.php` and can be
rewritten there without redeploying the portal, so the wiki declares which field
carries the answer (`$wgWikiOasisSafetyDataFieldRoles['request_kind']`), the portal
maps what it finds onto its own kinds, and anything unrecognised is left null —
which is a request somebody reads rather than one the portal guesses about.

A flow that asks for only one thing has no field to read it off, so the role names
the answer instead — `'request_kind' => ['value' => 'erase']` — which is what the
shipped wizard does now that asking for a copy is not offered. Still the wiki
saying what its submissions mean rather than the portal inferring it, and mapped
through the same synonyms, so a flow that pins a value the portal does not take is
left unclassified exactly as a wizard answer would be.

**Approving an erasure request starts the erasure.** One action, in the same
operation and with the same record as one begun from an account's page.

**Approving anything else starts nothing** and leaves the case outstanding until
what was agreed to has been carried out and the case closed. That is the failure
this models against: approved, replied to twice, closed, and nothing done.

Declining requires a reason **the requester reads** — under most of the regimes
these arrive under, giving one is the obligation rather than the courtesy, so it is
a separate field from the internal `resolution` and it is required. The case closes
as `rejected`, which the wiki renders as "closed, no action": somebody is entitled
to know their request was considered and refused rather than filed away.

### No copies

**The portal does not answer a request for a copy of what it holds.** Not "does
not offer"; does not have the machinery. There is no kind for it, no reviewer can
record a request as being one, the words for asking are not in
`DATA_KIND_SYNONYMS`, and there is no endpoint that assembles a subject's file or
records having sent it. Those requests are answered by a person, off the portal,
at `safety@wikioasis.org`.

There used to be all of it — an `AccessPackage` class, a `GET …/package` endpoint,
a `POST …/disclosed` to record that the copy went, and a panel on the case page
that put somebody's whole file on screen. It is deleted rather than disabled, and
the reason is worth keeping written down.

An access request is an obligation to hand over personal data, arriving by
definition from somebody with a strong interest in being given as much as
possible — and a Trust & Safety file is full of *other people's* personal data,
most sharply the people who reported the requester and are relying on not being
named. The class that assembled one was defined by what it refused: no internal
notes, no investigation in any form, never the reporter or their words or the
subject line of a case about the requester, nothing anonymous in either
direction, no `internal_reason` on an action. Every one of those refusals was one
mistake away from handing somebody who reports harassment to the person they
reported, which is the worst way this system can fail anybody.

A route that can do that is a risk the portal carries every day, for a request it
receives rarely and can answer better with a person reading the file. So the
wizard no longer offers it (`$wgWikiOasisSafetyDataSteps` in the extension), and
the portal cannot be configured back into it: a wiki whose own `LocalSettings.php`
still offers the option produces a request with **no kind**, which lands in front
of a reviewer to read and decline with the address of somebody who can answer it.

Requests to **delete** what we hold are unaffected, and are what the flow is now
for.

## Answering an appeal

An appeal is against something, and it ends in an answer. Both of those used to be
guesses, and for most appeals neither question was being asked at all.

### There are two doors, and only one announced itself

`Special:SafetyAppeal` — the form on a login screen, for somebody locked out —
posts to `/appeals` and arrives typed as an appeal. Everybody who can still log in
uses the contact wizard, whose appeal branch is one option on "why are you writing
to us?". That arrives typed as a **`contact`** carrying a *category* of `appeal`,
because `type` describes which wizard was used and `category` describes what the
person said.

So the portal had two kinds of appeal and treated the commoner one as a message.
Everything keyed on the type — the panel, the decision, the acceptance rate —
missed every appeal from somebody who could still log in, and the published
figures described the minority who could not. Worse, the contact wizard *asks*
which action is being appealed and offers the reader their own record to pick
from, so the exact reference was sitting in `answers` and going nowhere.

`CaseService` now corrects the type from the category at intake
(`categories.appeal_categories`, configurable because the vocabulary is each
wiki's own), and `flow` is left alone — which wizard produced a case stays true.
A data request is never promoted, whatever it is categorised as: it is a legal
obligation with its own decision and clock, and that is the one misclassification
here that loses something unreconstructable.

### What it is against

The reference field on an appeal is optional and always will be. The premise of an
appeal is somebody locked out of the account they are writing about, typing into a
box on a login screen — insisting on a case number would turn "I have been blocked
and I do not know why" into a validation error shown to exactly that person. So
most appeals arrive with no reference at all.

`App\Services\Safety\AppealParser` works out what one is about, in this order: a
reference they gave, a reference anywhere in what they wrote, the only action on
their file, then the most recent of several. What changed is not the guessing — the
portal always guessed — but that it now **records how it knew**. A reference the
appellant supplied and a pick from their file used to be written into
`cases.sanction_id` identically, so nothing downstream could tell which it was
looking at.

Now `appeal_link_source` and `appeal_link_confidence` sit beside it, and
`appeal_link_notes` keeps what else was in the running. Only a stated reference and
a correction made by hand come back as `certain`; everything else is marked as
wanting a person, and the Activity page counts how many are outstanding — the
honest cost of being willing to guess at all.

The one thing the parser will not do is follow a reference belonging to somebody
else. People quote the block notice a friend was shown, or the case number from a
report *about* them naming a different account. Following it would attach this
appeal to that person's action, and every figure below would be wrong with nothing
anywhere to show it.

### How it ended

`cases.appeal_outcome`, written by a person, in five values rather than two.

The outcome used to be inferred — an action lifted with an appeal on its file —
and that reading cannot see an appeal that succeeded without a lift (an indefinite
reduced to six months), cannot see a withdrawal at all, and silently counts "not
decided yet" the same as "refused". The single figure a reader of a transparency
report looks for hardest was the one the portal could not honestly produce.

`withdrawn` and `invalid` exist to keep the denominator meaning something. Neither
says anything about whether the original action was right, so both are counted and
then excluded from the published rate; see `SafetyCase::APPEAL_ON_THE_MERITS`.

Granting **lifts the action in the same request**, through the same service that
lifts one from an account's page. Not a reminder on a dashboard: an appeal recorded
as granted against an account that is still locked out is the record saying somebody
was vindicated while they are still shut out, and nothing here would be looking for
the gap. An appeal linked to nothing cannot be granted at all — there would be
nothing to lift, and it would land in the overall acceptance rate while appearing in
none of the categories a reader would use to check it.

### The appellant sees the link too

Special:SafetyHome shows the person who appealed which action it is against, the
reason that was given for it, whether it still applies, and what was decided. That
sounds obvious and was not the case: an appeal rendered as a case filed under
"appeal" and stopped there, while the action itself sat on the standing tab of the
same page with nothing joining the two.

An appeal the portal could **not** place says so on that page and asks for the
reference. The appellant is the one person who knows which action they meant, and
asking them is the fastest correction available to either side.

## Erasing an account

The only irreversible thing here — and it is irreversible on the *wiki*. The portal
adds a record and never subtracts one.

Whoever is answering the data request erases the account, in one action. There is
no approval step, no second reader, and no flag gate.

All three were tried and removed for the same reason. Somebody has asked for their
personal data to be removed; the team member reading that request is the person who
has established it is genuine, and making them wait for a colleague to agree — or
to press the button on their behalf — adds a delay paid for entirely by the person
waiting, in exchange for a review that consists of reading the same request again
with less context.

What makes it accountable is not a queue. It is the record: the reference, both
names, who did it, under what basis, and when. After the job runs there is nothing
on the wiki to prove the account existed, which is the point, so `data_removals` is
the only place that pairing survives — and it is also what makes a mistake findable,
which a second signature never was.

A request can be written down without being started (`hold`), for one that has
arrived and is not ready to act on. That is not a review step: nobody else has to
agree to it.

**Nothing happens on a web request.** Approving used to send the rename inline, so
whoever pressed the button waited on a round trip to MediaWiki — the full timeout
when the wiki was slow, the whole connect timeout when it was down — and a single
lost request meant an erasure that never started, because nothing ever came back
to try again.

Every stage now runs from `App\Jobs\AdvanceDataRemovals`, queued every minute:
`approved → renaming → renamed → scrubbing → done`. It sends what is approved,
asks whether a rename has finished, starts the scrub that follows, and retries what
failed. So a wiki that is down delays an erasure instead of losing one.

**Waiting and failing are different states**, and conflating them was the other
half of this going wrong. A rename across a farm reports itself unfinished for most
of the minutes it takes, and a wiki being restarted does not answer at all — and
both of those used to land in `failed`, which needs a person to notice and press a
button. So an erasure that would have completed on its own sat waiting for
somebody.

Now anything the wiki has not *finally refused* is a wait: the row keeps its state,
backs off exponentially to a ceiling of ten minutes, and records what it is waiting
on in `last_problem` rather than in `error` — so a request in flight does not read
on screen as a broken one. `next_attempt_at` is what lets the command run every
minute without turning patience into failure.

The extension says which it is, with two error codes. `tasknotready` means ask
again — a rename still running, a portal that could not be reached, a database
briefly unavailable. `taskfailed` means no: CentralAuth absent, an unusable name, no
approved erasure with that reference. The portal waits on the first, fails on the
second immediately, and treats any code it does not recognise as a wait — the cost
of being wrong that way is a few retries, and the cost of being wrong the other way
is an erasure abandoned halfway. Patience runs out after twelve waits, about an hour
and a half, and the row then says how long it waited.

Two stages, and they cannot be one. CentralAuth renames a farm asynchronously; the
scrub works on the new name. Running both together would scrub an account that has
not been renamed, which fails silently on most of the tables involved and leaves
the old name in the rest.

The scrub is a job per attached wiki and each one reports back, so a queue that
loses one shows up as a request that never completed rather than as an erasure
everybody believes happened.

The account ends up called `WikiOasisGDPR_` plus 24 random bytes in hex. Random
rather than derived from the reference: a name carrying its own reference would let
anybody who finds it in a five-year-old page history establish that this history
belongs to an erasure and go looking for which one.

It is off by default (`MW_PII_ENABLED`).

### It erases the wiki, not the portal

An erasure discharges an obligation to the wiki. Trust & Safety's own records are
kept under retention rules of their own — longer than a wiki's, and not discharged
by a removal request — and they are removed deliberately, by a person, against that
policy.

This used not to be true, in a small and quiet way. When the rename finished, the
subject's `username` was overwritten with the anonymised name and its `email` set
to null, on the reasoning that keeping the old name here would defeat the erasure.
That is the wrong reasoning for this portal, and it was the wrong shape of decision:
a retention rule being applied two columns at a time as a side effect of a job
finishing, with nobody having decided anything.

So the portal keeps what it holds — the name, the address, the cases, the
conversation, the files — and two columns on `subjects` keep the wiki's state
separate from it:

| Column | Meaning |
|---|---|
| `wiki_username` | What the wiki calls the account now |
| `erased_at` | When it was erased there, and when the portal's own clock starts |

**Everything the portal sends to a wiki goes through `Subject::wikiName()`**, which
returns the erased name where there is one. That is not tidiness. Pushing the
retained name would relabel the mirror row on `Special:SafetyHome` with the name the
erasure had just removed — the portal handing back the identity the wiki was asked
to forget, which is worse than not having erased it at all, because by then
everybody believes it is gone.

`Subject::forUsername()` is the other half. A wiki mentioning an erased account
knows it only as `WikiOasisGDPR_3f9c…`, so that name resolves to the existing row
rather than creating a second one — and a rename arriving for an erased subject
updates `wiki_username` instead of the retained name, so the record cannot be
deleted through a side door months later the next time any wiki happens to mention
the account.

### Asking what is now past retention

```sh
php artisan tsportal:retention --years=3
```

Lists erased accounts whose portal records are older than that, with what is
attached to each: cases, comments, stored files. It deletes nothing, and there is
no default period — a command that guessed at one would be making the policy
decision.

The reason it exists at all is that the failure mode of "a person removes it
deliberately" is that nobody does, because nobody knows what is due. A retention
rule with no way of asking what it applies to becomes keeping everything forever,
which is the thing it was written to prevent.

The deciding stays with a person because it has to: an account erased three years
ago may still be named in an open investigation, in an action still in force, or in
a case somebody has asked us about. None of that is legible to a command. Deletion
is done against the database by somebody who has looked — and files need the disk as
well as the row, which is what `AttachmentStore::forget()` is for.

## Reporting on ourselves

Three screens, and they answer three different people's questions.

**Activity** (`/activity`) is for the team. The dashboard answers "what should I
do next" and is four numbers about right now; this needs history — that intake
doubled in March, that harassment reports take three times as long to close as
licensing ones, that one checker ran a third of the checks. Every figure is over a
period and carries the same window immediately before it, because the first thing
anybody does with a number is compare it to last quarter's.

Two details in there are the difference between a chart and a misleading chart.
Durations are medians with the 90th percentile beside them, never means: one case
that sat open for two years pulls a mean so far off that the figure stops
describing anything. And every breakdown carries its own denominator — how many
cases arrived with no category, how many actions have no reason recorded — because
a category chart covering 60% of the intake, printed without saying so, presents
six wikis' vocabularies as though they were the whole farm's.

**CheckUser log** (`/checkuser`) is for whoever is watching how the right is used.
Each wiki reads its own `cu_log` and pushes the metadata — who ran a check, the
reason they gave, what kind of target, when. Never what a check returned; there is
no code on either side that could send that.

Delivery needs nothing scheduled on the wikis: a check is sent at the moment it is
run, after the response has gone out, and queues in the extension's outbox if this
portal is not reachable. That matters at farm scale — a per-wiki cron entry is
quietly absent on exactly the wiki nobody thought about, and a missing entry looks
identical to nobody having run any checks.

The push endpoint deduplicates on (wiki, log id), which is what makes the rest
safe: a queued batch redelivered after a lost response leaves one row per check,
and a wiki backfilling its history can overlap freely with what is already here. By default an account name is sent as
itself and an IP is reduced to a keyed fingerprint, which is enough to count six
checks against one target and not enough to say what the target was.

It sits behind `ts` and no further flag, deliberately. Putting oversight of the
right to look up IP addresses behind a rarer flag than the right to suspend an
account is the wrong way round.

The figure it leads on is checks run with no reason recorded, because that is the
one case where the log itself is incomplete and it is countable without anybody
exercising judgement. An empty page here means one of two things that look
identical in the figures — nobody ran any checks, or nobody switched the reporting
on — so the page says which.

**Transparency** (`/transparency`) is for people outside the team, and that
changes three things about it:

- *It is frozen.* Figures are computed once and stored. Rendering live would mean
  the numbers moving underneath a document other people have already quoted — a
  case reopened in November changing the count of closed cases in a report about
  the second quarter.
- *Small counts are suppressed.* "One erasure request, from oasiswiki-fr, in a
  week when one account was renamed" identifies a person to anybody who was
  watching — and a transparency report is published precisely to people who were
  watching. Counts at or below the report's threshold are banded as "fewer than
  N", the suppression happens when the figures are *built* rather than when they
  are shown, and the threshold is stored on the report so one published under a
  threshold of 5 does not later claim it was published under the current one.
- *It says what it does not know.* Every section carries its gaps: how many cases
  had no category, how many actions no reason, how many wikis report CheckUser
  activity at all. A total drawn from four wikis out of forty is "we know about 90
  checks", not "we ran 90 checks", and a report that did not say so would make a
  much stronger claim than the data supports.

The figure it leads on for appeals is the share accepted, broken down by what the
original action was *for*. "One in five appeals succeeds" describes a process; one
in five against sockpuppetry findings succeeding while one in twenty against
harassment findings does describes how the two are decided, and it is the only
figure here that can show a category being got wrong at the point of the action
rather than at the appeal. Those rates are suppressed on their own denominator and
a withheld row takes its percentage with it — stricter than the one share the
CheckUser section prints, because that one is over every check on the farm and
these are per-category and frequently small.

Generating and rebuilding a draft is `ts`; publishing is `admin` — a flag, not a
second person, like everything else here. A CSV export is offered because a
transparency report is a thing other people re-analyse, and a table locked in a web
page is a table they will re-type by hand and get wrong.

## Evidence

A reporter describing harassment usually has a screenshot of it, and that is the
one piece of the report that settles what happened. It used to stay in the
browser: the wizard collected name, size, type and modified time, and the portal
recorded a case saying "four files were named". A reviewer's first act on half the
queue was to email the reporter asking them to send the pictures again. Most did
not.

### Getting here

The wiki sends the submission first and the files afterwards, one signed request
each, because a file needs a case to belong to and the case has no reference until
the submission has been taken. The portal writes a row per named file from the
submission and fills each one in as its bytes arrive.

The bytes come base64-encoded inside the signed JSON body rather than as
multipart. That is not a style choice: `VerifyWikiSignature` checks an HMAC over
the raw request body, and PHP consumes a `multipart/form-data` body into `$_FILES`
before any application code runs — `php://input` is then empty, so the middleware
would be verifying a signature over nothing. It costs about a third extra on the
wire and roughly 1.4× the file in memory while it is decoded, which is what
`TS_ATTACHMENTS_MAX_BYTES` bounds.

### Three states, kept apart

| State | Meaning |
|---|---|
| `pending` | The submission named it; the bytes have not arrived |
| `stored` | It is here and can be downloaded |
| `refused` | The portal would not keep it, and `refused_reason` says why |

Collapsing any two of these is the failure that costs something. A refused file
that reads as "no file" has a reviewer conclude the reporter attached nothing — so
they never ask for it, and the thing that showed what happened never arrives. So a
refusal always writes the row, and always says why.

### Moving to S3

`config/attachments.php` names a disk in `config/filesystems.php`. That is the
whole of the abstraction: `TS_ATTACHMENTS_DISK=s3` plus the `AWS_*` block, and no
call site in the portal knows or asks where the bytes live. Anything speaking the
S3 API works — Amazon, R2, B2, MinIO.

**The disk each file went to is written on its own row**, and that is the part the
migration rests on. Resolve the disk from configuration at download time instead
and the day the setting changes, every file already written is looked for in a
bucket it was never in — 404s for evidence that is sitting there intact. Old rows
keep pointing at `local`, new ones at `s3`, both keep working.

Whichever disk it is, it must not be publicly readable. These are screenshots of
harassment and photographs of identity documents.

### Reading one

Streamed through the portal, not handed out as a link to the disk or a pre-signed
bucket URL, and the reason is the audit line: who looked at a photograph of
somebody's identity document is a question Trust & Safety has to be able to answer
about itself — to an auditor, to a data protection authority, and to the person in
the photograph. A pre-signed URL is a capability that leaves the building and is
used with nobody watching.

The type sent is the one detected from the bytes, not the one the upload claimed,
with `nosniff` set. A file that arrived calling itself a PNG and is really HTML is
the oldest way there is of running script on a colleague's session, and this
portal holds every case on the farm.

## The reports that cannot wait

A report that somebody is about to be killed arrives through the same form as a
copyright complaint and lands in the same list. Sorted oldest-first, as a queue
should be, it sits behind forty reports about page moves until somebody happens to
open it. Nobody chose that; it is what a fair queue does. And hours is the whole of
the difference this category makes.

So a report filed under a category named in `config('categories.threat_to_life')`:

- **is `urgent` before any person has read it**, set at submission from the
  categories the wiki resolved;
- **sorts above everything else open, whatever sort is chosen.** Not only
  `sort=priority` — the failure being prevented is somebody clicking "newest" to
  see what has just come in and, in doing so, burying the one case that could not
  wait;
- **is marked where somebody will see it** — a red banner across the top of the
  case itself, a chip on its queue row, and a dashboard tile that appears only
  when the count is nonzero, because a tile showing zero every day is a tile
  nobody looks at;
- **pings Slack**, which is the next section.

### It does not depend on the wiki having a vocabulary

This is the part that was wrong to begin with, and it was wrong in the way that
matters: keying the detection on categories meant it rested entirely on the one
input a wiki is explicitly allowed not to provide.

A wiki that has not declared `$wgWikiOasisSafetyCategories` sends no categories —
the block at the top of `config/categories.php` says as much, and treats an
uncategorised case as one needing a person. Fine for counting; useless here. A
report arrived carrying `report: threat-of-physical-harm` and
`threat-to-life: yes` in its answers, with no categories, at `normal` priority, in
arrival order, with nothing on any screen saying what it was.

So `Triage::detect()` reads the **answers** as well, against the same list:

- a field *named* as one of the watched ids and answered with anything but a
  refusal — a wiki with a `threat-to-life` checkbox is saying it as plainly as it
  can;
- an answer *valued* as one — `report: threat-of-physical-harm` names a watched id
  whether or not the wiki went on to declare it as a category, which is the same
  rule the extension's own resolver uses.

`no`, `false`, `none` and blank are refusals, because a wiki that asks the
question on every report would otherwise flag every report. And matching stays
exact: no search for the word "threat" in a free-text box, which would fire on the
harassment report that mentions one in passing and train everybody to ignore the
alert.

The finding is then stored on `cases.threat_to_life` rather than recomputed. The
queue filters on it and the dashboard counts it, neither of which can query a JSON
answers column portably — and it is the finding rather than the input, so a report
that was urgent when it arrived stays urgent in the record even if somebody later
edits the list of words that made it so. A re-categorisation can set it and never
clears it: taking a category off says the case was filed wrongly, not that nobody
is in danger.

Categories are matched against everything a case is filed under, not just the
primary. A report whose triage answer was "harassment" and which goes on to say
the person has been threatened with a knife is a threat to life, and reading only
the first answer would miss it — which is the one failure the whole path exists to
prevent.

### Why it is a list somebody maintains

Report categories are the wiki's own words — the block at the top of
`config/categories.php` explains why — so there is no id the portal can rely on
being spelled its way. `threat-of-physical-harm` is the shipped example
configuration's; another wiki will have written `threat-to-life`, `danger` or
`violence`. Every spelling in use across the farm goes in
`TS_THREAT_TO_LIFE_CATEGORIES`, once, written down by somebody who checked.

Deliberately not a keyword scan of what the reporter wrote. A rule that guesses
will both miss one — the failure this exists to prevent — and fire on the
harassment report that mentions a threat in passing. Somewhere between the second
and the tenth false alarm the mention starts being ignored, and then the real one
is ignored too.

### Escalate only

A reviewer who has read a case and set it `low` has more information than a
category list does, and their reasons are not written anywhere the portal can
read. So a re-categorisation escalates only when the threat-to-life category is
*new*: somebody saying for the first time that this is a threat to life is new
information and wins; an unrelated edit to the categories of a case already filed
that way does not drag it back to urgent.

Taking a case off `urgent` is the interesting version of the event, so
`case.priority` records whether it was a threat-to-life case at the time — the
line is useless later without it.

## Slack

The portal records everything it does. Nobody reads that table, because it is
something you go and look at, which means you look at it once you already know
something happened — so a report filed at two on a Sunday morning sat in the queue
until somebody opened the queue.

The same audit line now goes to Slack. It is hung off `Audit::log`, once, for the
reason the audit call itself is a one-liner: a notification somebody has to
remember to send is a notification missing from the one path nobody thought about.
Every kind of activity already comes through that method, so nothing has to be
added to a service later to make it visible.

Off by default. `SLACK_ACTIVITY_ENABLED=true` and `SLACK_WEBHOOK_URL` is the
minimum; `config/slack.php` then routes families of events to further webhooks —
`cases`, `actions`, `data`, `admin`, `urgent` — each falling back to the default,
so one channel or five is a configuration choice. An action nobody has routed goes
to the default rather than being dropped: a new audit action should turn up
somewhere looking unpolished, and somebody noticing it in the wrong channel is how
it gets routed.

`php artisan tsportal:slack-test` sends a message to every configured webhook and
prints the routing. Worth running after any change to it, because a misconfigured
incoming webhook fails silently and identically to a quiet week — nobody
investigates a channel with no messages in it, so the failure is discovered on the
night the threat-to-life ping does not arrive.

### The mention

One thing pings, and only one: a report filed under a threat-to-life category.
`SLACK_THREAT_MENTION` is Slack's own syntax — `<!channel>`, `<!here>`, or
`<!subteam^S0123ABCD>` for a user group, which is the better answer if the team has
one. The value of a mention is entirely in its rarity: a channel that pings for
every status change is a channel with notifications turned off, and then the one
that mattered is silent too.

### What is not sent

No case summaries, no wizard answers, no comment bodies, and by default no account
names. A Slack workspace is not this portal — its retention is somebody else's
setting, its export is available to workspace admins, its search is indefinite,
and the guest account somebody added for a contractor last year may still be in
the channel. A message says what happened and to which reference, and links into
the portal, where access is checked and reading is itself audited.

That is a deliberate trade against convenience: it means you cannot triage from
Slack, which is correct.

Sending is queued, cannot fail the request that caused it, and gives up after
three attempts. The work has already happened and been recorded; the audit log
remains the record and this is a courtesy on top of it.

## Access

Three flags, checked by the `staff` middleware:

| Flag | What it opens |
| --- | --- |
| `ts` | Everything: the queue, accounts, actions, the audit log |
| `user-manager` | Granting and removing flags, on the Team page |
| `admin` | Suspending an account, deleting a wiki, erasing an account's data, publishing a transparency report — and undoing any of them |

Flags come from two places and the difference matters. One derived from a wiki
group (see `config/mediawiki.php`) comes back on its own at the next sign-in; one
granted on the Team page is a decision and stays until it is taken back there.
Signing in recomputes the first and leaves the second alone.

Someone who signs in with no `ts` flag is not refused — that is the normal state
for a new team member — and gets a page naming who can grant it.

## Email

Most of the time the portal does not know an account's address, and that is
correct: it belongs to the wiki. So updates reach a reporter through Echo, which
mails them through MediaWiki without the address ever leaving it.

The exception is the one that matters most. A suspended account cannot read a
notification on the wiki, so `Special:SafetyAppeal` asks for an address, and
that is what `SanctionIssuedMail` and the case mails use. Those emails carry the
substance — what the action was, why, and how to appeal — because "log in to
find out why you cannot log in" is not a message anyone can act on. Every other
mail says only that there is something to read and where.

## Tests

```sh
php artisan test
```

Worth knowing what they cover, since most of it is about disclosure rather than
arithmetic: that an unsigned request is refused and a replayed one is caught,
that an anonymous report is never listed, that one account cannot read another's
case, that an internal note is never queued for the wiki, that a lock suspends
and a warning does not, and that an action the extension cannot carry out is
still recorded.

The newer ones follow the same principle. That an investigation's premise, notes
and findings never appear in anything queued for a wiki. That concluding a file
answers every report on it, and closes them as "action taken" or "closed, no
action" according to what was actually done. That a block with no wikis is refused
rather than recorded. That the wiki-facing erasure check answers a flat `false`
for an unknown reference, an unapproved one and a mismatched name alike, so it
cannot be used to ask whether an account has an erasure pending. And that every
reference the seeder invents resolves — including the `AC-` and `AP-` numbers
nothing allocates any more.

The newest four are about what was added last. That a file's type is read
from its bytes and not from what the upload claimed, that the disk it went to is
written on its row so changing the setting does not orphan it, and that a refused
file keeps a row saying why. That a threat-to-life report is urgent before anybody
has read it and sits at the top of the queue under every sort — that one loops over
the sorts, because the bug it prevents is a sort control burying an emergency. And
that Slack gets told about activity nobody routed, that only a threat to life
carries a mention, and that no case summary or account name reaches it. And that an erasure leaves the
portal's own record alone — the name, the address, the cases — while still
addressing the wiki by the name it now knows, which is the pair of properties that
has to hold together or one of them defeats the other.

The extension has a matching suite, and three tests there are worth knowing about.
`tests/php/HmacParityTest.php` signs the same requests with both implementations
and compares the bytes. `tests/php/SubmissionParityTest.php` checks that the two
submission paths — the wizard's API module and the no-JavaScript form — send the
same envelope, which they did not: the no-JavaScript one was quietly omitting
`categories`, so every report filed without JavaScript arrived uncategorised and
the only symptom was a figure that was too low in a published transparency report.
`tests/php/MessagesTest.php` checks that no message read by JavaScript contains
markup nothing on that path will render.

## Known gaps

- **No reconciliation.** The portal pushes and the wiki never pulls. If a push
  exhausts its retries, `Special:SafetyHome` quietly shows something stale, and
  the dashboard is the only place that says so.
- **Attachments need JavaScript on the wiki.** They arrive and are stored now —
  see "Evidence" below — but only from the wizard. The no-JavaScript form shows
  the field as a notice saying so, because HTMLForm would need a multipart round
  trip per step to hold a file across four page loads.
- **Retention is a list and a person, not a control.**
  `tsportal:retention` says which erased accounts are past a given period and what
  is attached to each; removing them is done against the database by hand. That is
  the intended arrangement — see "It erases the wiki, not the portal" — but it does
  mean there is no screen for it, and `AttachmentStore::forget()` has to be reached
  from tinker to take a file's bytes off the disk.
- **Nothing moves existing files between disks.** Each row records the disk its
  bytes went to, so switching `TS_ATTACHMENTS_DISK` to `s3` is safe and old files
  keep working from where they are — but they stay there. Migrating them is a job
  that does not exist yet.
- **No full-text search.** The queue's search box reaches the conversation, the
  wizard answers, the accounts a case names and the file it belongs to, and
  understands conditions — `harassment type:report with:me`, `is:stale`,
  `about:"Quiet Marlin"`. All of it is still `LIKE`, which is fine at this size and
  will not stay fine. When it stops being fine, the parsing in
  `App\Services\Safety\CaseSearch` is the part worth keeping and the `where`
  clauses are the part to replace.
- **An appeal from a login screen is unauthenticated.** It has to be — the
  premise is somebody who cannot log in — so the account name on it is a claim,
  shown to the reviewer as one.
- **Most appeals are matched to an action by inference.** The reference field is
  optional because the people filing these are locked out, so the portal reads what
  it can and says how confident it is. Nothing forces somebody to confirm the link
  before deciding; the Activity page counts the unconfirmed ones, and that count is
  the caveat on every acceptance rate broken down by category.
- **An appeal is recognised by its category, which each wiki controls.** A farm
  whose contact flow calls the option something not in `categories.appeal_categories`
  and not in the `appeal` group files appeals as messages again, silently. The
  extension's `$wgWikiOasisSafetyContactFieldRoles['appeal_target']` has the same
  shape of risk: undeclared, the link still resolves but only as an inference.
- **The wiki list is a cache with no expiry.** A wiki the farm has not pushed
  since it was renamed is offered under its old name. Nothing removes a row on a
  wiki's absence from a push, deliberately — a partial push would otherwise delete
  somewhere Trust & Safety has taken action — so a stale row is offered rather than
  hidden.
- **An unlocked-but-still-blocked account.** Lifting a suspension unlocks
  centrally; lifting a block queues an unblock per wiki. Neither knows about the
  other, so an account with both has to have both lifted, and the actions list is
  the only thing that says so.
- **`partial` is reported, not retried.** The portal notices that a suspension was
  recorded without being enforced and says so on the dashboard; nothing tries
  again on its own. That is on purpose — a retry loop against an enforcement that
  is refused on its merits would repeat forever — but it means somebody has to
  read the dashboard.
- **A block stuck in `queued` is not chased.** If a wiki's job queue loses the
  block job, the action waits on a report that will never arrive. It shows as
  "being applied" indefinitely rather than as a failure, and nothing times it out.
