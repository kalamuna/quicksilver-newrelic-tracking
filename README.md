# Quicksilver New Relic Deployment Tracking

Records a New Relic deployment marker every time code lands on a Pantheon
environment, so APM charts show a line at each deploy and you can tell a
regression from a coincidence.

## Requirements

- New Relic enabled for the site (`terminus new-relic:info <site>`).
- PHP 8.0 or newer. Pantheon's Secrets Manager, which is the only place this
  hook reads its credential from, is not supported on PHP 7.4.
- Pantheon Secrets Manager. The `secret:site:*` commands are built into
  Terminus 4.2.0 and newer; before that, including 4.0.x and 4.1.x, they come
  from the separately installed `terminus-secrets-manager-plugin` (now
  deprecated in favour of core).
- A New Relic **User** API key. License keys and ingest keys do not
  authenticate against NerdGraph.

## Installation

```bash
composer require kalamuna/quicksilver-newrelic-tracking
```

[Composer Installers](https://github.com/composer/installers) places the
package using its `quicksilver-script` type, whose path is hardcoded to
`web/private/scripts/quicksilver/{$name}`. That matches a nested docroot
(`web_docroot: true`). On a site whose docroot is the repository root,
override it so the file lands where Pantheon looks:

```json
"extra": {
    "installer-paths": {
        "private/scripts/quicksilver/{$name}": ["type:quicksilver-script"]
    }
}
```

### 1. Get a New Relic User API key

An API key is not a new requirement. The previous version of this hook used
one too, it just never asked you for it: Pantheon used to hand the key to the
script through the `newrelic` platform binding. That binding is gone, so the
key now has to be supplied explicitly.

Pantheon-provisioned New Relic accounts have no separate password. Reach the
account through single sign-on from the Pantheon dashboard:

1. Open the Site Dashboard and select an environment (Dev, Test or Live).
2. Click the **New Relic** tab, then **Go to New Relic**.

Every Pantheon user who follows that link signs in as the same New Relic
user, so the key you create is shared site infrastructure rather than
personal to you. Treat it accordingly.

If the account you land in contains no application for this site, stop: a key
from the wrong account produces a "no APM application is named exactly ..."
failure that looks like a naming problem. Check the account before the name.

Once you are in the right account:

3. Go to [one.newrelic.com/api-keys](https://one.newrelic.com/api-keys), or
   click your name in the New Relic UI and choose **API keys**. EU accounts
   use [one.eu.newrelic.com/api-keys](https://one.eu.newrelic.com/api-keys),
   JP accounts [one.jp.newrelic.com/api-keys](https://one.jp.newrelic.com/api-keys).
4. Click **Create a key** and choose key type **User**.
5. Copy the key before leaving the page. Once a key is created, the UI shows
   only its first 8 characters.

Key type matters. NerdGraph accepts only a **User** key. A license key or an
ingest key is rejected, which this hook reports in the workflow log. Posting a
deployment marker does not require a full platform user, so the shared
Pantheon New Relic user is sufficient.

See [New Relic API keys](https://docs.newrelic.com/docs/apis/intro-apis/new-relic-api-keys/)
for the key types in full.

### 2. Store the key as a Pantheon secret

```bash
terminus secret:site:set <site> new_relic_api_key <KEY> \
  --type=runtime --scope=web
```

Pass both flags rather than relying on defaults. Terminus sends neither field
when the flag is omitted, and what the server does then is not documented, so
neither default is something to bet a silent failure on:

- **`--scope=web`** is required for the secret to be readable by the running
  site. Pantheon's documentation is explicit that secrets must have the `web`
  scope to be visible from your application, and Quicksilver is no exception.
- **`--type=runtime`** is the only type `pantheon_get_secret()` can read, and
  the type is immutable once set, so a wrong value costs a delete-and-recreate
  rather than an edit.

Verify the type and scope, not just the name:

```bash
terminus secret:site:list <site>
```

Pantheon caches secrets for up to 15 minutes, Quicksilver included, so the
first deploy after setting the key may still report it missing. That is the
cache, not a misconfiguration.

### 3. Add the hooks to `pantheon.yml`

```yaml
workflows:
  # Markers for code arriving in dev and multidev environments.
  sync_code:
    after:
      - type: webphp
        description: Log to New Relic
        script: private/scripts/quicksilver/quicksilver-newrelic-tracking/new_relic_deploy.php
  # Markers for promotions to test and live.
  deploy:
    after:
      - type: webphp
        description: Log to New Relic
        script: private/scripts/quicksilver/quicksilver-newrelic-tracking/new_relic_deploy.php
```

The `script` path is written relative to the docroot, so on a nested-docroot
site the leading `web/` is omitted even though the file lives at
`web/private/...`. Keep the script under a path listed in
`protected_web_paths` (`/private/` is there by default) so it cannot be
requested over HTTP.

Commit `pantheon.yml`, push, and check the workflow output:

```bash
terminus workflow:info:logs <site>.<env> --id=<workflow-id>
```

A successful run looks like this:

```
Recording deployment "pantheon_live_23" against my-site (live) (NDU3NDAzM3xBUE18QVBQTElDQVRJT058MTA5NzI1MjA0Mg)...
Done. New Relic deployment ID d2b836c3-4a3f-49c4-bf5c-a07fe9bf9ff1.
```

## What gets recorded

| Marker field | On `sync_code` | On `deploy` |
| --- | --- | --- |
| `version` | short commit SHA | this environment's deploy tag at `HEAD` |
| `description` | commit subject | `Deployed to <environment>` |
| `changelog` | commit body, plus how it arrived | tag annotation, plus how it arrived |
| `commit` | full commit SHA | full commit SHA |
| `user` | the workflow's user, or the commit author for a dashboard commit | the workflow's user |

Each Pantheon environment reports to New Relic under its own application name
(`my-site (live)`), so markers land against the environment they belong to.

On `deploy` the commit subject and author are deliberately ignored. Pantheon
rewrites the tip commit on test and live as its own build artifact, so the
subject reads `Test/live build artifacts added by Pantheon` and the author is
`bot@getpantheon.com`. Attributing a release to either would be worse than
attributing it to nobody.

Tags are read with `git tag --points-at HEAD`, not `git describe`. `describe`
walks backwards to the nearest *ancestor* tag, which on a real Pantheon
repository is a previous release, so it would label every deploy with the last
one's version. A single commit also routinely carries tags for several
environments, because promoting to live re-tags a commit already tagged for
test, so the tag whose prefix matches the current environment wins and the
highest sequence number breaks ties.

A tag belonging to a *different* environment is never adopted. Labelling a live
release `pantheon_test_38` is plausible enough to be believed and wrong enough
to send a regression hunt to the wrong build, so when no tag matches this
environment the marker falls back to the commit SHA, which is at least honest.
A human release tag such as `v4.0.14` is still preferred over the SHA.

Only annotated tags contribute a changelog: for a lightweight tag `git tag -n`
prints the tagged commit's subject, which would read as a release note without
being one.

## Troubleshooting

Failures are explained in the workflow log rather than thrown. That is not the
same as being unable to affect the deploy, and it is worth being precise about
which, because the difference decides whether someone gets paged.

A workflow's status reflects whether its Quicksilver scripts *started*, and
each script runs after the previous one finishes or times out, so this hook
does not deliberately fail anything. But Pantheon's own tracker records that a
Quicksilver script whose PHP worker hangs marks the deploy workflow **failed**
on the dashboard --
[quicksilver-examples#155](https://github.com/pantheon-systems/quicksilver-examples/issues/155),
still open, about this very hook.

Two facts sit behind that, and they are easy to run together. Pantheon sets
`max_execution_time` to 120 seconds for non-web requests including Quicksilver
(PHP's own default is 30), and it fires as an uncatchable fatal -- but PHP does not
count time blocked on the network toward it, so a hung request is never
interrupted by that timeout at all. A request timeout is the real defence, and
adding one is exactly what issue #155 asks for.

So the defence is a set of bounds, not a promise: each request is capped at 15
seconds, at most three search pages are walked, and pagination stops early once
the hook has spent 45 seconds. The normal case is two requests. The two
`pantheon_get_secret()` reads sit outside all of it -- they are remote calls
with no timeout this hook controls.

| Log message | Cause |
| --- | --- |
| `no usable New Relic API key` | The `new_relic_api_key` secret is missing, empty, not scoped to `web`, or set less than 15 minutes ago. |
| `the API key contains whitespace or non-printable characters` | The stored value picked up a newline, a space or a NUL. Any of those corrupt the HTTP header: a newline makes New Relic answer `Invalid JSON`, and a NUL makes libcurl silently truncate the key, both of which look like auth failures. Re-save the secret. |
| `pantheon_get_secret() is unavailable` | Secrets Manager is not set up on the site. |
| `newrelic.appname is unset or still the PHP default` | New Relic is not enabled for this site or environment. `PHP Application` is the agent's default and is treated as unset. |
| `no APM application is named exactly ...` and the name looks right | If `settings.php` calls `newrelic_set_appname()`, the entity carries that runtime name while this hook reads the *ini* name. A `webphp` hook does not bootstrap the CMS, so it cannot see the runtime value. Rename via `newrelic.appname` instead, or accept that markers will not resolve. |
| `HTTP 401` | The key was rejected. It must be a **User** key. |
| `BAD_API_KEY` | New Relic returned HTTP 200 but rejected the key in the response body. |
| `no APM application is named exactly "..."` | Most often the key belongs to the wrong New Relic account. Candidates found are listed beneath, because New Relic matches names loosely: a search for `site (live)` also returns `site (lastlive)`. No marker is posted rather than posting it against a near match. A wrong region looks identical, so set `new_relic_region` if the account is not in the US. |
| `Search stopped after N pages` | The account has more matching applications than the search walked. |
| `WARNING: the marker was created, but ...` | The marker exists. New Relic also reported a problem, which for APM entities it does without blocking the save. |
| `HTTP 429` | Too many NerdGraph requests in flight for this New Relic user. The limit is 25 concurrent, not a rate quota, and it clears as those requests drain -- but the Pantheon SSO user is shared across every site, so a busy moment elsewhere can cause it. The marker is lost; there is no retry, deliberately, because a deploy hook should not sit and wait. |
| `does not understand the workflow type` | The hook is attached to a workflow other than `sync_code` or `deploy`. |

## Regions

The NerdGraph endpoint defaults to the US host and is otherwise guessed from
the license key's region prefix: the characters before the first `x` or `X`.
The token is not restricted to a letters-then-digits shape: New Relic's
cross-agent fixtures include `eu01xx...`, `gov01x...`, `foo1234x...` and
`20foox...`. So the region comes from the leading *letters* only, and anything
unrecognised falls back to US. Matching whole prefixes would route an `eu03` or
`jp01` key to the US, which is a silent failure: the US endpoint authenticates
fine and simply has no such application.

That first-`x` convention comes from the PHP agent's daemon, which uses it to
build a *collector* hostname; only the convention is shared, and an
unrecognised region falls back to US rather than being turned into a hostname.

The guess is only sound while the license key and the User API key belong to
the same New Relic account, which is normal but not guaranteed -- region is not
recoverable from a `NRAK-` key. To state it explicitly, set an optional second
secret to `US`, `EU`, or `JP`:

```bash
terminus secret:site:set <site> new_relic_region EU --type=runtime --scope=web
```

Two caveats:

- The FedRAMP endpoint, `gov-api.newrelic.com`, appears in New Relic's own
  schema-generated Go client but in none of their published documentation. A
  `gov01x` license prefix is attested in their cross-agent fixtures, so the
  region is real even though the endpoint is undocumented.
- New Relic's JP region excludes the legacy "Deployment marker API" and REST
  v2, and points users at exactly this NerdGraph mutation as the replacement, so
  a JP account should work. One caveat remains: for APM entities the mutation
  calls v2 REST internally, which JP also excludes, so a JP deploy may succeed
  while logging a non-blocking WARNING from that internal call.

## Development

Git, the clock, the network, and the platform's own configuration are all
either injected or split behind pure functions, so the payload shape, region
selection, error classification, request headers, and the whole orchestration
are testable without a checkout and without a network:

```bash
php tests/run.php     # or: composer test
```

`tests/` is `export-ignore`d, so it is absent from the package Composer
installs onto Pantheon. Run the suite from a git clone, not from a site's
Quicksilver directory.

## Why this uses NerdGraph

Two upstream retirements broke the previous implementation:

- **Pantheon removed the `newrelic` platform binding.** The old script read
  the New Relic credentials from
  `https://api.live.getpantheon.com/sites/self/bindings?type=newrelic`. That
  request now answers HTTP 200 with `{}`, so the script exited before
  contacting New Relic, on every deploy, without failing the workflow.
  Pantheon dropped binding usage from their own example in May 2024
  (LOPS-2264). This is why the API key has to be configured by hand now: the
  credential the hook always needed is no longer delivered by the platform.
- **New Relic retired the Deployments v0 API.** The old script posted to
  `api.newrelic.com/deployments.xml`, which is
  [end of life on 2027-07-31](https://docs.newrelic.com/eol/2026/07/eol-07-31-26-rest-api-v2/)
  along with REST API v2. Note that the `-26` in that URL is New Relic's
  *publish* date, not the EOL date: the page itself carries
  `eolEffectiveDate: '2027-07-31'`. It reads like a typo and is not one.

Markers are now created with the
[`changeTrackingCreateDeployment`](https://docs.newrelic.com/docs/change-tracking/change-tracking-graphql/)
NerdGraph mutation. The application's entity GUID is resolved at run time by
searching for the APM application whose name matches `newrelic.appname`
exactly, following pagination cursors because the search returns at most 200
entities per page.

New Relic labels this mutation the legacy path and suggests
`changeTrackingCreateEvent` for new work. It is not deprecated and has no
announced end of life, and it is what Pantheon's own current example uses, so
it is the conservative choice here. `changeTrackingCreateEvent` would also
remove a round trip, because it can take an entity search inline instead of
resolving a GUID first.

### Trust boundary on tags

`version` and the deploy changelog come from a tag at `HEAD`. Anyone who can
push a tag to the Pantheon repository can therefore choose what a deploy marker
says, by pushing `pantheon_<env>_<a higher number>` at the deployed commit.
Malformed and option-like names are filtered, and the sequence must be plain
digits, but a plausible higher number wins by design -- that is how Pantheon's
own counter works, and there is no local state to check it against. Push access
to the deploy repository is already deploy access; this only means the
observability record inherits that same trust boundary.

### Field lengths

New Relic trims string fields at 4096 characters and appends an ellipsis
unless `FAIL_ON_FIELD_LENGTH` is passed, which this hook does not send. So
values are bounded here to choose where the cut lands rather than to avoid
rejection, and to keep a huge commit message from being uploaded on every
deploy. Git output is separately capped in the shell before it reaches PHP, and
the HTTP response is capped in a write callback: neither a commit message nor a
response body has an inherent size limit, and buffering either one whole can
exhaust `memory_limit`, which is a fatal no `catch` can absorb.

### Injection surface

The GraphQL documents are fixed strings, and every value taken from a commit
message, tag annotation or author field travels as a typed GraphQL variable.
Nothing derived from repository content is interpolated into a query.

There is exactly one place where repository content reaches a shell: the tag
name passed to `git`, which goes through `escapeshellarg()`. That protects the
shell but not git's own option parsing -- a tag literally named
`--points-at=HEAD` would turn a tag listing into something else -- so tag names
are also filtered to a safe character set and rejected outright if they begin
with a dash. Such names cannot be created by `git tag`, but `git update-ref`
makes them and a push carries them.

Values that reach the workflow log have their control characters replaced --
including the C1 controls, the Unicode line and paragraph separators, and the
bidi overrides that a byte-wise filter cannot see -- so nothing arriving from a
commit message, an ini setting, or a New Relic response can forge additional log
lines, emit terminal escapes, or reorder what an operator reads. Log output is
also bounded, both per value and in the number of search candidates listed.

The value of the `new_relic_region` secret is deliberately never echoed, even
when it is rejected. Transposing the arguments of two `secret:site:set`
commands is an easy mistake, and printing the rejected value would put an API
key into a durable workflow log visible to every site collaborator.
