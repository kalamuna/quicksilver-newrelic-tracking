<?php

/**
 * @file
 * Quicksilver hook: record a New Relic deployment marker.
 *
 * Both halves of the original implementation have been retired by their
 * vendors, which is why this script was rewritten:
 *
 * - Pantheon removed the "newrelic" platform binding. A request to
 *   /sites/self/bindings?type=newrelic now answers HTTP 200 with an empty
 *   object, so the old credential lookup could never succeed. Pantheon's own
 *   example dropped binding usage in May 2024 (LOPS-2264).
 * - New Relic's REST API v2 and Deployments v0 API ("deployments.xml") reach
 *   end of life on 2027-07-31. Deployment markers are now created with the
 *   NerdGraph changeTrackingCreateDeployment mutation.
 *
 * One-time setup per site:
 *
 * 1. Get a New Relic *User* API key. Pantheon-provisioned New Relic accounts
 *    are reached by single sign-on: Site Dashboard, pick an environment, the
 *    New Relic tab, then "Go to New Relic". From there open
 *    https://one.newrelic.com/api-keys (EU accounts: one.eu.newrelic.com),
 *    create a key of type "User", and copy it before leaving the page.
 *    A license key or an ingest key will NOT authenticate against NerdGraph.
 *
 * 2. Store it as a Pantheon secret that PHP can read. Both flags matter:
 *    only the "runtime" type is visible to pantheon_get_secret(), and the
 *    type cannot be changed later without deleting the secret.
 *
 *      terminus secret:site:set <site> new_relic_api_key <KEY> \
 *        --type=runtime --scope=web
 *
 *    Pantheon caches secrets for up to 15 minutes, including for Quicksilver,
 *    so the first deploy after setting it may still report the key missing.
 *
 * 3. Add the hooks to pantheon.yml. See README.md.
 *
 * When the marker cannot be posted, the reason is explained in the workflow
 * log and the script returns normally rather than throwing.
 *
 * That is not the same as being unable to affect the deploy. Pantheon's own
 * tracker records that a Quicksilver script whose PHP worker hangs marks the
 * deploy workflow failed on the dashboard (quicksilver-examples#155, still
 * open, about this very hook).
 *
 * Two separate facts matter here, and they are easy to conflate:
 *
 * - max_execution_time, 120 seconds by default on Pantheon, fires as an
 *   uncatchable fatal. It is a configurable ini setting, not a hard platform
 *   kill.
 * - PHP does not count time blocked on the network toward it, so a hung
 *   request is not interrupted by that timeout at all. CURLOPT_TIMEOUT is the
 *   real defence, and adding one is exactly what issue #155 asks for.
 *
 * So: QS_NR_HTTP_TIMEOUT bounds each request, QS_NR_MAX_SEARCH_PAGES bounds
 * how many are made, and QS_NR_TOTAL_BUDGET trims pagination early when
 * requests are slow. The two pantheon_get_secret() reads are outside all of
 * that -- they are remote calls with no timeout this hook controls.
 *
 * @see https://docs.newrelic.com/docs/change-tracking/change-tracking-graphql/
 * @see https://docs.newrelic.com/eol/2026/07/eol-07-31-26-rest-api-v2/
 */

// This file is a Quicksilver entry point, not a library. Each hook entry in
// pantheon.yml runs in its own request, so it is never loaded twice in one
// process. Note that a guard here could not help if it were: PHP binds
// top-level function declarations at compile time, before any statement in the
// file runs, so an early `return` cannot prevent a redeclaration fatal.
// Only the constants are guarded, because tests load the file deliberately.

// Traces must never carry argument values: the API key is passed as a scalar
// to the transport, and PHP includes scalar arguments in fatal-error traces.
@ini_set('zend.exception_ignore_args', '1');

// This request is plumbing, not site traffic. Keep it out of APM. The function
// is checked as well as the extension: this runs at file scope, outside the
// exception guard below, so an undefined function here would be fatal.
if (extension_loaded('newrelic') && function_exists('newrelic_ignore_transaction')) {
  newrelic_ignore_transaction();
}

/**
 * Name of the Pantheon secret holding a New Relic User API key.
 */
defined('QS_NR_SECRET_NAME') || define('QS_NR_SECRET_NAME', 'new_relic_api_key');

/**
 * Optional secret naming the New Relic region, for accounts outside the US.
 *
 * Accepts "US", "EU" or "JP", or a full documented NerdGraph URL. Only needed
 * when the region guessed from the license key is wrong.
 */
defined('QS_NR_ENDPOINT_SECRET_NAME') || define('QS_NR_ENDPOINT_SECRET_NAME', 'new_relic_region');

/**
 * Seconds to wait for a connection, and for a whole request.
 */
defined('QS_NR_CONNECT_TIMEOUT') || define('QS_NR_CONNECT_TIMEOUT', 5);
defined('QS_NR_HTTP_TIMEOUT') || define('QS_NR_HTTP_TIMEOUT', 15);

/**
 * Longest values to send, in bytes.
 *
 * New Relic trims string fields itself at 4096 characters and appends an
 * ellipsis, unless FAIL_ON_FIELD_LENGTH is passed, which this hook does not
 * send. So the choice is not "truncate or lose the marker" but "choose where
 * the cut lands, or let New Relic cut mid-word". Bounding here also keeps a
 * huge commit message from being uploaded on every deploy.
 *
 * Every field derived from the repository is bounded, not just the changelog:
 * a commit subject is as attacker-sized as a commit body.
 */
defined('QS_NR_CHANGELOG_LIMIT') || define('QS_NR_CHANGELOG_LIMIT', 4000);
defined('QS_NR_DESCRIPTION_LIMIT') || define('QS_NR_DESCRIPTION_LIMIT', 1024);
defined('QS_NR_VERSION_LIMIT') || define('QS_NR_VERSION_LIMIT', 256);
defined('QS_NR_USER_LIMIT') || define('QS_NR_USER_LIMIT', 256);

/**
 * Longest git output to read into memory, in bytes.
 *
 * A commit message has no size limit, and shell_exec() buffers whatever it is
 * given. Without this bound a single pushed commit could exhaust memory_limit,
 * and the resulting E_ERROR is uncatchable, so it would fail the deployment
 * this hook promises never to disturb.
 */
defined('QS_NR_GIT_OUTPUT_LIMIT') || define('QS_NR_GIT_OUTPUT_LIMIT', 65536);

/**
 * Longest HTTP response body to read into memory, in bytes.
 *
 * The same reasoning as the git cap, for the same reason: buffering an
 * unbounded body is an uncatchable fatal. A NerdGraph answer to either of
 * these documents is a few kilobytes, so anything approaching this is a
 * malfunction upstream rather than a large legitimate response.
 */
defined('QS_NR_RESPONSE_LIMIT') || define('QS_NR_RESPONSE_LIMIT', 1048576);

/**
 * The ini default when New Relic is not configured for the environment.
 *
 * ini_get() returns this rather than an empty string, so it has to be
 * recognised or the hook would search New Relic for "PHP Application".
 */
defined('QS_NR_APPNAME_DEFAULT') || define('QS_NR_APPNAME_DEFAULT', 'PHP Application');

/**
 * Workflow types that mean "new code arrived in this environment".
 *
 * Integrated Composer sites report sync_code_with_build rather than
 * sync_code, and that value is not documented by Pantheon.
 */
function qs_nr_sync_workflow_types(): array {
  return ['sync_code', 'sync_code_with_build'];
}

/**
 * Most entity search pages to walk before giving up.
 *
 * NerdGraph returns at most 200 entities per page and the name filter is a
 * substring match, so a large account can push the exact match onto a later
 * page. The cap keeps a pathological account from stalling the workflow.
 */
defined('QS_NR_MAX_SEARCH_PAGES') || define('QS_NR_MAX_SEARCH_PAGES', 3);

/**
 * Most candidate names to print when no exact match is found.
 */
defined('QS_NR_MAX_CANDIDATES_LOGGED') || define('QS_NR_MAX_CANDIDATES_LOGGED', 20);

/**
 * Most GraphQL error entries to echo from one response.
 */
defined('QS_NR_MAX_PROBLEMS_REPORTED') || define('QS_NR_MAX_PROBLEMS_REPORTED', 10);

/**
 * Longest APM application name to send, in bytes.
 */
defined('QS_NR_APPNAME_LIMIT') || define('QS_NR_APPNAME_LIMIT', 256);

/**
 * Seconds the whole hook may spend before it stops paginating.
 *
 * A hung worker marks the deploy workflow failed, and max_execution_time
 * defaults to 120 seconds. Per-request timeouts alone do not bound the total
 * number of requests, so the search stops walking pages once this is spent.
 * Note what it cannot cover: the mutation is still attempted afterwards, and
 * the secrets reads happen before the clock is ever consulted.
 */
defined('QS_NR_TOTAL_BUDGET') || define('QS_NR_TOTAL_BUDGET', 45);

/**
 * Entry point: runs the hook and absorbs anything it throws.
 *
 * A deploy marker is never worth a stack trace in a deployment log, and the
 * platform functions this hook calls are outside its control. pantheon_get_secret()
 * in particular talks to a remote service and is not documented to be
 * exception-free.
 */
function qs_nr_main(array $post, ?callable $transport = NULL, ?array $config = NULL): bool {
  try {
    return qs_nr_run($post, $transport, $config);
  }
  catch (\Throwable $unexpected) {
    qs_nr_say('ERROR: recording the deployment failed unexpectedly: '
      . qs_nr_printable($unexpected->getMessage()));
    qs_nr_say('  thrown by ' . qs_nr_printable(get_class($unexpected))
      . ' at ' . qs_nr_printable(basename($unexpected->getFile())) . ':' . $unexpected->getLine());
    return FALSE;
  }
}

/**
 * Runs the hook. Returns TRUE when a marker was created.
 *
 * @param array $post
 *   The Quicksilver POST body.
 * @param callable|null $transport
 *   Receives (endpoint, api_key, payload) and returns the qs_nr_post_graphql()
 *   result shape. Injected so the wiring can be tested without a network.
 * @param array|null $config
 *   Overrides the platform-derived configuration: 'api_key', 'app_name' and
 *   'endpoint'. Injected for the same reason; the platform values come from a
 *   PHP extension and a secrets backend that cannot be simulated in-process.
 */
function qs_nr_run(array $post, ?callable $transport = NULL, ?array $config = NULL): bool {
  $transport = $transport ?? 'qs_nr_post_graphql';
  $git = $config['git'] ?? 'qs_nr_git';
  $clock = $config['clock'] ?? static function (): float {
    return microtime(TRUE);
  };
  $deadline = $clock() + QS_NR_TOTAL_BUDGET;
  $wf_type = qs_nr_scalar($post, 'wf_type');
  if ($wf_type !== 'deploy' && !in_array($wf_type, qs_nr_sync_workflow_types(), TRUE)) {
    qs_nr_say('Nothing to record: this hook does not understand the workflow type "'
      . qs_nr_printable($wf_type) . '".');
    qs_nr_say('Attach it to sync_code or deploy in pantheon.yml.');
    return FALSE;
  }

  // Per-key fallback, so a caller stubbing one value does not silently blank
  // the others.
  $api_key = array_key_exists('api_key', (array) $config) ? $config['api_key'] : qs_nr_api_key();
  if ($api_key === NULL) {
    qs_nr_say('ERROR: no usable New Relic API key.');
    qs_nr_say('Store a New Relic *User* API key in the "' . QS_NR_SECRET_NAME . '" secret:');
    qs_nr_say('  terminus secret:site:set <site> ' . QS_NR_SECRET_NAME . ' <KEY> --type=runtime --scope=web');
    qs_nr_say('The scope must include "web" or the running site cannot read it, and Pantheon');
    qs_nr_say('caches secrets for 15 minutes, so a key set moments ago may not be visible yet.');
    return FALSE;
  }

  $app_name = array_key_exists('app_name', (array) $config)
    ? $config['app_name']
    : qs_nr_app_name((string) ini_get('newrelic.appname'));
  if ($app_name === NULL) {
    qs_nr_say('ERROR: newrelic.appname is unset or still the PHP default, so the APM entity is unknown.');
    qs_nr_say('Confirm New Relic is enabled for this site: terminus new-relic:info <site>');
    return FALSE;
  }

  if (!function_exists('curl_init')) {
    qs_nr_say('ERROR: the curl extension is unavailable, so New Relic cannot be reached.');
    return FALSE;
  }

  if ($git('rev-parse --is-inside-work-tree') !== 'true') {
    qs_nr_say('NOTE: this is not a git checkout, so commit details will be incomplete.');
  }

  $endpoint = array_key_exists('endpoint', (array) $config)
    ? $config['endpoint']
    : qs_nr_graphql_endpoint((string) ini_get('newrelic.license'), qs_nr_region_override());

  $guid = NULL;
  $seen = [];
  $cursor = NULL;
  $pages = 0;
  $stopped_early = FALSE;
  for ($page = 0; $page < QS_NR_MAX_SEARCH_PAGES; $page++) {
    $pages++;
    $search = $transport($endpoint, $api_key, qs_nr_entity_search_payload($app_name, $cursor));

    // Read the result before judging the errors array, the same way the
    // mutation below does. GraphQL returns partial data with a populated
    // `errors` array for shard and field-level failures, so an entity that
    // was found is still worth using.
    $guid = qs_nr_extract_entity_guid($search['data'] ?? NULL, $app_name);
    if ($guid !== NULL) {
      foreach (qs_nr_problems($search) as $problem) {
        qs_nr_say('WARNING: the application was found, but New Relic also reported: ' . $problem);
      }
      break;
    }

    if (!qs_nr_report($search, 'entity lookup')) {
      return FALSE;
    }

    // Keep only as many names as will ever be printed.
    $seen = array_slice(
      array_merge($seen, qs_nr_entity_names($search['data'] ?? NULL)),
      0,
      QS_NR_MAX_CANDIDATES_LOGGED + 1
    );

    $cursor = qs_nr_next_cursor($search['data'] ?? NULL);
    if ($cursor === NULL) {
      break;
    }

    // Stop walking pages once the hook has spent its budget. A killed worker
    // marks the whole deploy workflow failed, which is far worse than a
    // missing marker.
    if ($clock() >= $deadline) {
      qs_nr_say('NOTE: stopped searching after ' . QS_NR_TOTAL_BUDGET
        . ' seconds to stay inside the Quicksilver time limit.');
      $stopped_early = TRUE;
      break;
    }
  }

  if ($guid === NULL) {
    qs_nr_say('ERROR: no APM application is named exactly "' . qs_nr_printable($app_name)
      . '" in this API key\'s account.');
    qs_nr_say('New Relic matches names loosely, so a marker is skipped rather than posted to a near match.');
    qs_nr_say('If the account is in the EU or JP region, set the "' . QS_NR_ENDPOINT_SECRET_NAME
      . '" secret; a wrong region looks exactly like a missing application.');
    if ($cursor !== NULL && !$stopped_early) {
      qs_nr_say('Search stopped after ' . $pages . ' page(s) with more results available.');
    }
    // Enough to diagnose a near-miss without turning a large account into
    // thousands of log lines.
    foreach (array_slice($seen, 0, QS_NR_MAX_CANDIDATES_LOGGED) as $name) {
      qs_nr_say('  candidate seen: ' . $name);
    }
    if (count($seen) > QS_NR_MAX_CANDIDATES_LOGGED) {
      qs_nr_say('  ... and more not shown.');
    }
    return FALSE;
  }

  $deployment = qs_nr_build_deployment($post, $git, qs_nr_environment($post));
  $deployment['entityGuid'] = $guid;

  // The GUID comes from an HTTP response and the app name from an ini setting;
  // neither is this hook's own data, so both are sanitised before logging.
  qs_nr_say('Recording deployment "' . qs_nr_printable($deployment['version'])
    . '" against ' . qs_nr_printable($app_name) . ' (' . qs_nr_printable($guid) . ')...');

  $result = $transport($endpoint, $api_key, qs_nr_deployment_payload($deployment));

  // Read the result before judging the errors array. With no dataHandlingRules
  // sent, New Relic documents that a legacy REST failure behind an APM entity
  // is reported in `errors` without blocking the save, so errors and a real
  // deployment can arrive together.
  $created = qs_nr_dig($result['data'] ?? NULL, ['data', 'changeTrackingCreateDeployment']);
  $id = is_array($created) ? ($created['deploymentId'] ?? NULL) : NULL;
  // New Relic generates this as a string. A bool, a float or a zero means the
  // response is not what it claims, and reporting "deployment ID 1" for a
  // literal TRUE would announce a success that did not happen. The status has
  // to be a success too: a 500 carrying a deployment did not create one.
  $status_ok = ((int) ($result['status'] ?? 0)) >= 200 && ((int) ($result['status'] ?? 0)) < 300;
  $plausible = (is_string($id) && trim($id) !== '') || (is_int($id) && $id > 0);
  $id = $status_ok && $plausible ? (string) $id : NULL;

  if ($id === NULL) {
    qs_nr_report($result, 'deployment marker');
    if (qs_nr_problems($result) === []) {
      qs_nr_say('ERROR: New Relic accepted the request but returned no deployment.');
    }
    return FALSE;
  }

  foreach (qs_nr_problems($result) as $problem) {
    qs_nr_say('WARNING: the marker was created, but New Relic also reported: ' . $problem);
  }

  qs_nr_say('Done. New Relic deployment ID ' . qs_nr_printable($id) . '.');
  return TRUE;
}

/**
 * Reads a POST field as a string, tolerating arrays and missing keys.
 */
function qs_nr_scalar(array $post, string $key): string {
  $value = $post[$key] ?? '';

  return is_scalar($value) ? trim((string) $value) : '';
}

/**
 * Reads the New Relic User API key from Pantheon Secrets Manager.
 */
function qs_nr_api_key(): ?string {
  if (!function_exists('pantheon_get_secret')) {
    qs_nr_say('NOTE: pantheon_get_secret() is unavailable. Is Secrets Manager set up on this site?');
    return NULL;
  }

  $key = pantheon_get_secret(QS_NR_SECRET_NAME);

  return qs_nr_clean_api_key(is_string($key) ? $key : '');
}

/**
 * Reads the optional region override secret.
 */
function qs_nr_region_override(): string {
  if (!function_exists('pantheon_get_secret')) {
    return '';
  }

  $value = pantheon_get_secret(QS_NR_ENDPOINT_SECRET_NAME);

  return is_string($value) ? trim($value) : '';
}

/**
 * Validates an API key for use in an HTTP header.
 *
 * Secrets routinely pick up a trailing newline in transit. A newline or space
 * inside a header value corrupts the whole request, and New Relic reports that
 * as "Invalid JSON" rather than as an auth problem, so reject it here where
 * the message can be accurate.
 */
function qs_nr_clean_api_key(string $key): ?string {
  $key = trim($key);
  if ($key === '') {
    return NULL;
  }

  // Printable ASCII only. A whitespace test alone would pass a NUL byte, which
  // libcurl silently truncates the header at, and non-breaking or line
  // separator characters, which arrive verbatim. Any of those produce a
  // baffling 401 rather than an obvious configuration error.
  if (preg_match('/^[\x21-\x7E]+$/', $key) !== 1) {
    qs_nr_say('NOTE: the API key contains whitespace or non-printable characters, which would');
    qs_nr_say('corrupt the request. Re-save the "' . QS_NR_SECRET_NAME . '" secret.');
    return NULL;
  }

  return $key;
}

/**
 * Resolves the APM application name from the raw ini value.
 *
 * New Relic accepts a semicolon-separated list where the extra names are
 * rollup applications; only the first is the entity this deploy belongs to.
 */
function qs_nr_app_name(string $raw): ?string {
  $primary = trim(explode(';', $raw)[0]);

  if ($primary === '' || $primary === QS_NR_APPNAME_DEFAULT) {
    return NULL;
  }

  // A control character cannot appear in a New Relic entity name, so a name
  // carrying one can never match. Refusing it beats reporting that two
  // identical-looking strings did not match.
  if (preg_match('/[\x00-\x1F\x7F]/', $primary) === 1) {
    qs_nr_say('NOTE: newrelic.appname contains control characters and cannot match an entity.');
    return NULL;
  }

  return qs_nr_cut($primary, QS_NR_APPNAME_LIMIT);
}

/**
 * The NerdGraph endpoints New Relic documents, keyed by region.
 *
 * Only these three are documented. A FedRAMP "gov" NerdGraph endpoint is not
 * published, and the plausible-looking gov-api.newrelic.com answers
 * identically to the US host, so guessing at one would fake support rather
 * than provide it.
 *
 * @see https://docs.newrelic.com/docs/apis/nerdgraph/get-started/introduction-new-relic-nerdgraph/
 */
function qs_nr_endpoints(): array {
  return [
    'US' => 'https://api.newrelic.com/graphql',
    'EU' => 'https://api.eu.newrelic.com/graphql',
    'JP' => 'https://api.jp.newrelic.com/graphql',
    // Absent from New Relic's published docs, but present in their own
    // schema-generated Go client as the FedRAMP deployment's NerdGraph host.
    // @see newrelic/newrelic-client-go pkg/region/region_constants.go
    'GOV' => 'https://gov-api.newrelic.com/graphql',
  ];
}

/**
 * Returns the NerdGraph endpoint to use.
 *
 * Region cannot be read off a User API key, so this is a best guess from the
 * license key's region prefix: the characters before the first "x" or "X", as
 * in "eu01xX...", "eu03XX...", "euV09x...", "jp01xX..." or "gov01x...". Only
 * the leading letters identify the region -- the digits vary per cell, so
 * matching whole prefixes would route eu03 and jp01 keys to the US.
 *
 * That delimiter convention comes from the PHP agent's daemon, which uses it
 * to derive a *collector* hostname; only the convention is borrowed, and an
 * unrecognised region falls back to US rather than being turned into a
 * hostname.
 *
 * @see newrelic/newrelic-java-agent AgentConfigImplTest for the real prefixes.
 *
 * The guess is only sound while the license key and the User API key belong to
 * the same New Relic account, which is the normal case but is not guaranteed.
 * Pass $override, from the optional endpoint secret, to state it explicitly.
 */
function qs_nr_graphql_endpoint(string $license, string $override = ''): string {
  $endpoints = qs_nr_endpoints();

  $override = trim($override);
  if ($override !== '') {
    if (in_array($override, $endpoints, TRUE)) {
      return $override;
    }
    $named = strtoupper($override);
    if (isset($endpoints[$named])) {
      return $endpoints[$named];
    }
    // Deliberately not echoing the value. This is read from a secret, and an
    // operator who transposes the arguments of two secret:site:set commands
    // would otherwise print their API key into a durable workflow log.
    qs_nr_say('NOTE: the "' . QS_NR_ENDPOINT_SECRET_NAME . '" secret is not a region this hook knows.');
    qs_nr_say('Set it to one of: ' . implode(', ', array_keys($endpoints)) . '. Falling back to US.');
  }

  // Strip the quotes and whitespace that ini values pick up in the wild.
  $license = trim($license, " \t\n\r\0\x0B\"'");
  $region = preg_match('/^(.+?)[xX]/', $license, $matches) === 1 ? $matches[1] : '';

  return $endpoints[qs_nr_region_name($region)];
}

/**
 * Maps a license key's region token to a region name.
 *
 * The token's leading letters carry the region and its digits carry the cell,
 * so "eu01", "eu03" and "euV09" are all EU.
 */
function qs_nr_region_name(string $region): string {
  $letters = strtolower((string) preg_replace('/[^A-Za-z]/', '', $region));

  foreach (['gov' => 'GOV', 'eu' => 'EU', 'jp' => 'JP'] as $prefix => $name) {
    if (strpos($letters, $prefix) === 0) {
      return $name;
    }
  }

  return 'US';
}

/**
 * Returns the Pantheon environment name, or an empty string if unknown.
 *
 * The workflow payload is preferred over the PANTHEON_ENVIRONMENT constant:
 * `environment` is a documented Quicksilver variable, whereas the constant is
 * a platform detail that a webphp hook merely happens to inherit. Either way
 * this drives deploy-tag selection, so losing it degrades the marker's version
 * to a bare commit SHA.
 */
function qs_nr_environment(array $post = []): string {
  $from_payload = qs_nr_scalar($post, 'environment');
  if ($from_payload !== '') {
    return $from_payload;
  }

  return defined('PANTHEON_ENVIRONMENT') ? (string) constant('PANTHEON_ENVIRONMENT') : '';
}

/**
 * Runs a git command in the current working directory and returns its stdout.
 *
 * Quicksilver runs from inside the deployed checkout, so no path is passed.
 * If that ever stops being true, every git-derived field degrades to its
 * fallback and the "not a git checkout" note in qs_nr_run() fires.
 *
 * Output is capped in the shell, before it reaches PHP, because a commit
 * message is unbounded and attacker-sized: buffering one whole into a PHP
 * string can exhaust memory_limit, and that fatal cannot be caught.
 *
 * Callers are responsible for escaping any interpolated values.
 */
function qs_nr_git(string $arguments): string {
  if (!function_exists('shell_exec')) {
    return '';
  }

  $output = shell_exec(qs_nr_git_command($arguments));

  return is_string($output) ? trim($output) : '';
}

/**
 * Builds the shell command for a git read, including the output cap.
 *
 * Separated so the cap can be asserted; without it a single large commit
 * message is enough to exhaust memory_limit.
 */
function qs_nr_git_command(string $arguments): string {
  return 'git ' . $arguments . ' 2>/dev/null | head -c ' . QS_NR_GIT_OUTPUT_LIMIT;
}

/**
 * Builds the ChangeTrackingDeploymentInput fields for this workflow.
 *
 * Pure apart from the injected $git reader, so the shape of every workflow
 * type can be asserted in tests.
 *
 * @param array $post
 *   The Quicksilver POST body.
 * @param callable $git
 *   Receives a git argument string, returns trimmed stdout.
 * @param string $environment
 *   The Pantheon environment being deployed to.
 * @param int|null $now_ms
 *   Marker timestamp in milliseconds. Defaults to now.
 *
 * @return array
 *   Deployment input fields, minus entityGuid.
 */
function qs_nr_build_deployment(array $post, callable $git, string $environment, ?int $now_ms = NULL): array {
  $wf_type = qs_nr_scalar($post, 'wf_type');

  if ($wf_type === 'deploy') {
    // Deploys promote an existing commit, and on test and live the tip commit
    // is Pantheon's own build artifact rather than anyone's work, so the
    // deploy tag is the only meaningful version.
    $tag = qs_nr_deploy_tag($git, $environment);
    $version = $tag !== '' ? $tag : $git('log --pretty=format:%h -1');
    $description = $environment !== ''
      ? 'Deployed to ' . $environment
      : 'Deployed via Pantheon';
    $changelog = $tag !== '' ? qs_nr_tag_annotation($git, $tag) : '';
  }
  else {
    // A code sync carries a real authored commit at the tip.
    $version = $git('log --pretty=format:%h -1');
    $subject = $git('log --pretty=format:%s -1');
    $description = $subject !== '' ? $subject : 'Code synced via Pantheon';
    $changelog = $git('log --pretty=format:%b -1');
  }

  // The schema requires a non-empty version, so never send an empty string.
  if (trim($version) === '') {
    $version = 'unknown';
  }

  $deployment = [
    'version' => qs_nr_cut($version, QS_NR_VERSION_LIMIT),
    'description' => qs_nr_clamp($description, QS_NR_DESCRIPTION_LIMIT),
    'deploymentType' => 'BASIC',
    'timestamp' => $now_ms ?? (int) round(microtime(TRUE) * 1000),
  ];

  $user = qs_nr_deploying_user($post, $git);
  if ($user !== '') {
    // Cut without an ellipsis: "...' on the end of an address would split one
    // deployer into two when faceting markers by user.
    $deployment['user'] = qs_nr_cut($user, QS_NR_USER_LIMIT);
  }

  // Clamp the body before appending the provenance note, so that truncating a
  // huge commit message cannot delete the line explaining where it came from.
  $provenance = qs_nr_provenance($wf_type, $post);
  $body = qs_nr_clamp(trim($changelog), max(0, QS_NR_CHANGELOG_LIMIT - strlen($provenance) - 2));
  $deployment['changelog'] = trim($body . "\n\n" . $provenance);

  $commit = $git('log --pretty=format:%H -1');
  if ($commit !== '') {
    $deployment['commit'] = qs_nr_cut($commit, QS_NR_VERSION_LIMIT);
  }

  return $deployment;
}

/**
 * Finds the deploy tag for this environment on the deployed commit.
 *
 * `git describe` walks backwards to the nearest *ancestor* tag, which on a
 * real Pantheon repository means a previous release's tag rather than this
 * one. Only tags that point at HEAD describe this deploy.
 *
 * A single commit routinely carries tags for several environments, because
 * promoting to live re-tags the commit already tagged for test, so the tag
 * matching this environment wins and the highest sequence number breaks ties.
 */
function qs_nr_deploy_tag(callable $git, string $environment): string {
  $listing = $git('tag --points-at HEAD');
  $lines = explode("\n", $listing);
  // The git read is byte-capped, so the final line may be a fragment of a real
  // tag name. A fragment still looks like a valid name, so drop it rather than
  // name a tag that does not exist.
  if (strlen($listing) >= QS_NR_GIT_OUTPUT_LIMIT - 1 && count($lines) > 1) {
    array_pop($lines);
  }
  $tags = array_values(array_filter(array_map('trim', $lines), 'qs_nr_tag_is_safe'));
  if ($tags === []) {
    return '';
  }

  if ($environment === '') {
    return qs_nr_non_platform_tag($tags);
  }

  // Only a purely numeric suffix counts as this environment's deploy tag.
  // Pantheon's own tags look like pantheon_live_23; anything else pointing at
  // HEAD was put there by hand, and a name like pantheon_live_43abc would
  // otherwise cast to 43 and tie with, or beat, the genuine tag.
  $prefix = 'pantheon_' . $environment . '_';
  $matching = [];
  foreach ($tags as $tag) {
    if (strpos($tag, $prefix) !== 0) {
      continue;
    }
    // Digits only, and few enough that the integer cast cannot saturate:
    // "pantheon_live_999...9" would otherwise cast to PHP_INT_MAX and outrank
    // every genuine tag. Pantheon's sequences are small counters.
    $sequence = substr($tag, strlen($prefix));
    if (preg_match('/^\d{1,9}$/', $sequence) !== 1) {
      continue;
    }
    $matching[] = ['sequence' => (int) $sequence, 'tag' => $tag];
  }

  if ($matching === []) {
    return qs_nr_non_platform_tag($tags);
  }

  // Highest sequence wins; equal sequences (pantheon_live_8 against
  // pantheon_live_08) fall back to the name, so the choice is never decided by
  // the order git happened to list refs in.
  $best = $matching[0];
  foreach ($matching as $candidate) {
    if ($candidate['sequence'] > $best['sequence']
      || ($candidate['sequence'] === $best['sequence'] && strcmp($candidate['tag'], $best['tag']) > 0)) {
      $best = $candidate;
    }
  }

  return $best['tag'];
}

/**
 * Picks a human release tag, never another environment's platform tag.
 *
 * A tag like v4.0.14 is a useful version for a marker. A tag belonging to a
 * different environment is not: labelling a live release "pantheon_test_38"
 * is plausible enough to be believed and wrong enough to send a regression
 * investigation to the wrong build. When only foreign platform tags are
 * present, returning nothing lets the caller fall back to the commit SHA,
 * which is honest.
 */
function qs_nr_non_platform_tag(array $tags): string {
  foreach ($tags as $tag) {
    if (strpos($tag, 'pantheon_') !== 0) {
      return $tag;
    }
  }

  return '';
}

/**
 * Rejects tag names that are unsafe to pass to git as an argument.
 *
 * escapeshellarg() protects the shell, but git still reads a leading dash as
 * an option: a tag literally named "--points-at=HEAD" turns `git tag -l -n99
 * <tag>` into a listing of every tag. Such names cannot be created with
 * `git tag`, but `git update-ref` makes them and a push carries them, so they
 * have to be filtered rather than assumed away.
 */
function qs_nr_tag_is_safe(string $tag): bool {
  if ($tag === '' || strpos($tag, '-') === 0) {
    return FALSE;
  }

  return preg_match('#^[A-Za-z0-9._/+@-]+\z#', $tag) === 1;
}

/**
 * Reads a tag's annotation without the tag name git prefixes it with.
 *
 * Only annotated tags have an annotation. For a lightweight tag, `git tag -n`
 * falls back to printing the tagged commit's subject, which would present a
 * commit message as though it were a release note.
 */
function qs_nr_tag_annotation(callable $git, string $tag): string {
  if ($git('cat-file -t ' . escapeshellarg('refs/tags/' . $tag)) !== 'tag') {
    return '';
  }

  $annotation = $git('tag -l -n99 ' . escapeshellarg($tag));

  if (strpos($annotation, $tag) === 0) {
    $annotation = ltrim(substr($annotation, strlen($tag)));
  }

  return $annotation;
}

/**
 * Truncates a string, marking it so a reader knows something was cut.
 *
 * Never returns more than $limit bytes, including the marker, so that a
 * caller's budget arithmetic holds even for very small limits.
 */
function qs_nr_clamp(string $text, int $limit): string {
  if ($limit <= 0) {
    return '';
  }

  if (strlen($text) <= $limit) {
    return $text;
  }

  $suffix = '...';
  if ($limit <= strlen($suffix)) {
    return qs_nr_cut($text, $limit);
  }

  return qs_nr_cut($text, $limit - strlen($suffix)) . $suffix;
}

/**
 * Cuts a string to a byte budget without splitting a character.
 *
 * A byte-wise substr() can leave half a multi-byte character at the end, which
 * is invalid UTF-8. json_encode() only tolerates that because of
 * JSON_INVALID_UTF8_SUBSTITUTE, and the reader still sees a replacement
 * character, so cut on a character boundary where mbstring is available.
 */
function qs_nr_cut(string $text, int $bytes): string {
  if ($bytes <= 0) {
    return '';
  }

  if (!function_exists('mb_strcut')) {
    return substr($text, 0, $bytes);
  }

  return mb_strcut($text, 0, $bytes, 'UTF-8');
}

/**
 * Identifies who triggered the workflow.
 *
 * The workflow's own user_email is authoritative and is preferred whenever it
 * is present. Only when it is absent does a code sync fall back to the commit
 * author, and only for user_role "super".
 *
 * Pantheon publishes no vocabulary for user_role; "super" has meant an
 * in-dashboard SFTP commit since their 2016 example script, where the acting
 * user appears in the commit rather than the payload. That is a heuristic
 * inherited from quicksilver-examples/new_relic_deploy, not a documented rule,
 * which is why it now only ever adds information rather than overriding it.
 */
function qs_nr_deploying_user(array $post, callable $git): string {
  $email = qs_nr_scalar($post, 'user_email');
  if ($email !== '') {
    return $email;
  }

  // Only a code sync has an authored commit at the tip worth falling back to.
  // On a promotion the tip is Pantheon's build artifact, authored by
  // bot@getpantheon.com, which would attribute the deploy to the platform.
  if (qs_nr_scalar($post, 'wf_type') === 'deploy') {
    return '';
  }

  if (qs_nr_scalar($post, 'user_role') === 'super') {
    return $git('log --pretty=format:%ae -1');
  }

  return '';
}

/**
 * Describes how the code arrived, for the tail of the changelog.
 */
function qs_nr_provenance(string $wf_type, array $post): string {
  if ($wf_type === 'deploy') {
    return '(Deployed between environments via Pantheon.)';
  }

  if (qs_nr_scalar($post, 'user_role') === 'super') {
    return '(Commit made via the Pantheon dashboard.)';
  }

  return '(Triggered by a remote git push.)';
}

/**
 * Builds the entity search request for an APM application by name.
 *
 * queryBuilder takes typed fields, so the application name travels as a
 * GraphQL variable and never as text spliced into a query string. The name
 * filter is not an exact comparison -- a search for "site (live)" also returns
 * "site (lastlive)" -- so the response is filtered by exact name afterwards,
 * and `nextCursor` is followed because one page holds at most 200 entities.
 */
function qs_nr_entity_search_payload(string $app_name, ?string $cursor = NULL): array {
  return [
    'query' => 'query($queryBuilder: EntitySearchQueryBuilder!, $cursor: String) {'
      . ' actor { entitySearch(queryBuilder: $queryBuilder) {'
      . ' results(cursor: $cursor) { nextCursor entities { guid name } } } } }',
    'variables' => [
      'queryBuilder' => [
        'domain' => 'APM',
        'type' => 'APPLICATION',
        'name' => $app_name,
      ],
      'cursor' => $cursor,
    ],
  ];
}

/**
 * Reads the cursor for the next page of entity results, if there is one.
 */
function qs_nr_next_cursor($response): ?string {
  $cursor = qs_nr_dig($response, [
    'data', 'actor', 'entitySearch', 'results', 'nextCursor',
  ]);

  return is_string($cursor) && $cursor !== '' ? $cursor : NULL;
}

/**
 * Builds the changeTrackingCreateDeployment request.
 *
 * The deployment travels as a typed GraphQL variable, so commit messages
 * containing quotes, newlines or backslashes reach New Relic intact.
 */
function qs_nr_deployment_payload(array $deployment): array {
  return [
    'query' => 'mutation($deployment: ChangeTrackingDeploymentInput!) {'
      . ' changeTrackingCreateDeployment(deployment: $deployment) {'
      . ' deploymentId entityGuid version } }',
    'variables' => [
      'deployment' => $deployment,
    ],
  ];
}

/**
 * Returns the entity GUID whose name matches exactly, or NULL.
 *
 * New Relic's entity search treats `. , ; : * - _ ( )` as whitespace and
 * matches names as substrings, so a search for "site (live)" also returns
 * "site (lastlive)". Only an exact name is safe to post against; anything
 * else risks attributing a deploy to a different environment.
 */
function qs_nr_extract_entity_guid($response, string $app_name): ?string {
  foreach (qs_nr_entities($response) as $entity) {
    $name = $entity['name'] ?? NULL;
    $guid = $entity['guid'] ?? NULL;

    // A hostile or malformed response can put an array where a string belongs;
    // casting one would emit a warning and produce the string "Array".
    if (!is_string($name) || !is_string($guid) || $guid === '') {
      continue;
    }

    if ($name === $app_name) {
      return $guid;
    }
  }

  return NULL;
}

/**
 * Lists the entities in an entity search response.
 */
function qs_nr_entities($response): array {
  // Untyped: a transport can legitimately hand back NULL for a non-JSON body,
  // and a TypeError here would abort a deploy hook that must not throw.
  $entities = qs_nr_dig($response, [
    'data', 'actor', 'entitySearch', 'results', 'entities',
  ]);

  if (!is_array($entities)) {
    return [];
  }

  return array_values(array_filter($entities, 'is_array'));
}

/**
 * Names the entities a search returned, for diagnostics.
 */
function qs_nr_entity_names($response, ?int $limit = NULL): array {
  $limit = $limit ?? QS_NR_MAX_CANDIDATES_LOGGED + 1;

  // Slice before mapping. A cap-sized response of empty objects decodes to
  // hundreds of thousands of arrays, and mapping over all of them would
  // amplify the byte cap into an out-of-memory fatal.
  return array_map(static function (array $entity): string {
    return qs_nr_printable($entity['name'] ?? 'unnamed');
  }, array_slice(qs_nr_entities($response), 0, max(0, $limit)));
}

/**
 * Builds the curl options for a NerdGraph request.
 *
 * Separated from the call so tests can assert the endpoint, the headers and
 * the timeouts without a network.
 */
function qs_nr_curl_options(string $endpoint, string $api_key, string $body, ?callable $writer = NULL): array {
  $options = [
    CURLOPT_URL => $endpoint,
    CURLOPT_POST => TRUE,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_CONNECTTIMEOUT => QS_NR_CONNECT_TIMEOUT,
    CURLOPT_TIMEOUT => QS_NR_HTTP_TIMEOUT,
    // libcurl aborts before the transfer if the declared size is over the
    // limit, and since curl 8.4.0 also mid-transfer once the received bytes
    // exceed it. The write callback below is the backstop for older libcurl,
    // where only the declared-size check exists and a server can simply omit
    // Content-Length.
    CURLOPT_MAXFILESIZE => QS_NR_RESPONSE_LIMIT,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'API-Key: ' . $api_key,
    ],
  ];

  if ($writer === NULL) {
    $options[CURLOPT_RETURNTRANSFER] = TRUE;
    return $options;
  }

  $options[CURLOPT_WRITEFUNCTION] = $writer;

  return $options;
}

/**
 * Builds a bounded response collector.
 *
 * Returning fewer bytes than curl handed over aborts the transfer, which is
 * how the cap is enforced without ever holding the whole body.
 *
 * @param string $buffer
 *   Receives the body, by reference.
 * @param bool $overflowed
 *   Set to TRUE if the cap was hit, by reference.
 */
function qs_nr_response_collector(string &$buffer, bool &$overflowed, ?int $limit = NULL): callable {
  $limit = $limit ?? QS_NR_RESPONSE_LIMIT;

  return static function ($handle, string $chunk) use (&$buffer, &$overflowed, $limit): int {
    if (strlen($buffer) + strlen($chunk) > $limit) {
      $overflowed = TRUE;
      return 0;
    }

    $buffer .= $chunk;

    return strlen($chunk);
  };
}

/**
 * Encodes a GraphQL request body.
 *
 * JSON_INVALID_UTF8_SUBSTITUTE matters more than it looks: without it a
 * single non-UTF-8 byte in a commit message, which any latin-1 authoring
 * locale produces, makes json_encode() return FALSE and the marker is lost.
 *
 * @return string|null
 *   The encoded body, or NULL if it could not be encoded.
 */
function qs_nr_encode_body(array $payload): ?string {
  $body = json_encode(
    $payload,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
  );

  return $body === FALSE ? NULL : $body;
}

/**
 * POSTs a GraphQL document to NerdGraph.
 *
 * @return array
 *   Keys: 'status' (int), 'data' (decoded body or NULL), 'error' (string).
 */
function qs_nr_post_graphql(string $endpoint, string $api_key, array $payload): array {
  $body = qs_nr_encode_body($payload);
  if ($body === NULL) {
    return [
      'status' => 0,
      'data' => NULL,
      'error' => 'could not encode the request: ' . json_last_error_msg(),
    ];
  }

  $received = '';
  $overflowed = FALSE;

  $handle = curl_init();
  curl_setopt_array($handle, qs_nr_curl_options(
    $endpoint,
    $api_key,
    $body,
    qs_nr_response_collector($received, $overflowed)
  ));

  $ok = curl_exec($handle);
  $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
  $transport_error = curl_error($handle);

  // Release the handle. curl_close() is a no-op since PHP 8.0 and deprecated
  // in 8.5, so unset() is the portable form: CurlHandle is refcounted, and
  // dropping the last reference frees it immediately.
  unset($handle);

  if ($overflowed) {
    return [
      'status' => $status,
      'data' => NULL,
      'error' => 'the response exceeded ' . QS_NR_RESPONSE_LIMIT . ' bytes and was abandoned',
    ];
  }

  if ($ok === FALSE) {
    return [
      'status' => $status,
      'data' => NULL,
      'error' => $transport_error !== '' ? $transport_error : 'the request failed',
    ];
  }

  return [
    'status' => $status,
    'data' => json_decode($received, TRUE),
    'error' => '',
  ];
}

/**
 * Describes everything wrong with a NerdGraph result.
 *
 * Status is judged before response shape, so an HTML error page from a proxy
 * still reports its status code rather than hiding behind "not JSON".
 *
 * @return string[]
 *   Problems found, most important first. Empty means the result is usable.
 */
function qs_nr_problems(array $result): array {
  $raw_status = $result['status'] ?? NULL;
  $status = is_int($raw_status) || (is_float($raw_status) && is_finite($raw_status)) || is_string($raw_status)
    ? (int) $raw_status
    : 0;
  $data = $result['data'] ?? NULL;

  $error = $result['error'] ?? '';
  if (!is_string($error)) {
    $error = qs_nr_printable($error);
  }
  if ($error !== '') {
    return ['the request failed: ' . qs_nr_printable($error, 500)];
  }

  $problems = [];

  if ($status < 200 || $status >= 300) {
    $problems[] = 'the request returned HTTP ' . $status . '.';
    if ($status === 401) {
      $problems[] = 'HTTP 401 means the key was rejected. It must be a New Relic *User* key,'
        . ' not a license key or an ingest key.';
    }
    if ($status === 403) {
      $problems[] = 'HTTP 403 means the key was refused for this request.'
        . ' Check that it belongs to the account owning this application.';
    }
    if ($status === 429) {
      $problems[] = 'HTTP 429 means New Relic is rate limiting this user.';
    }
  }

  if (!is_array($data)) {
    $problems[] = 'the response was not JSON (HTTP ' . $status . ').';
    return $problems;
  }

  $errors = $data['errors'] ?? NULL;
  if (is_scalar($errors) && (string) $errors !== '') {
    // Not the documented shape, but saying what arrived beats saying nothing.
    $problems[] = 'New Relic said: ' . qs_nr_error_text($errors);
  }
  elseif (is_array($errors) && $errors !== []) {
    // Capped: a cap-sized response of tiny error objects would otherwise
    // become tens of thousands of log lines.
    foreach (array_slice($errors, 0, QS_NR_MAX_PROBLEMS_REPORTED) as $error) {
      $problems[] = 'New Relic said: ' . qs_nr_error_text($error);
      if (qs_nr_error_code($error) === 'BAD_API_KEY') {
        $problems[] = 'BAD_API_KEY means the "' . QS_NR_SECRET_NAME . '" secret is not a valid User key.';
      }
    }
    if (count($errors) > QS_NR_MAX_PROBLEMS_REPORTED) {
      $problems[] = 'and ' . (count($errors) - QS_NR_MAX_PROBLEMS_REPORTED) . ' further errors, not shown.';
    }
  }

  return $problems;
}

/**
 * Renders one GraphQL error for a human, whatever shape it arrived in.
 */
function qs_nr_error_text($error): string {
  if (is_array($error)) {
    $message = $error['message'] ?? NULL;
    if (is_string($message) && trim($message) !== '') {
      return qs_nr_printable($message, 500);
    }

    $encoded = json_encode($error, JSON_INVALID_UTF8_SUBSTITUTE);

    return qs_nr_printable(is_string($encoded) ? $encoded : '(an error New Relic did not describe)', 500);
  }

  return is_scalar($error) ? qs_nr_printable($error, 500) : gettype($error);
}

/**
 * Extracts New Relic's machine-readable error code, when present.
 */
function qs_nr_error_code($error): ?string {
  $code = is_array($error) ? qs_nr_dig($error, ['extensions', 'error_code']) : NULL;

  return is_string($code) ? $code : NULL;
}

/**
 * Prints anything wrong with a NerdGraph result.
 *
 * @return bool
 *   TRUE when the result is usable and the caller may continue.
 */
function qs_nr_report(array $result, string $stage): bool {
  $problems = qs_nr_problems($result);
  if ($problems === []) {
    return TRUE;
  }

  foreach ($problems as $problem) {
    qs_nr_say('ERROR during the ' . $stage . ': ' . $problem);
  }

  return FALSE;
}

/**
 * Reads a nested value out of a decoded response, or NULL if absent.
 *
 * @param mixed $data
 *   The structure to walk.
 * @param string[] $path
 *   Keys to follow, outermost first.
 *
 * @return mixed
 *   The value found, or NULL.
 */
function qs_nr_dig($data, array $path) {
  foreach ($path as $key) {
    if (!is_array($data) || !array_key_exists($key, $data)) {
      return NULL;
    }
    $data = $data[$key];
  }

  return $data;
}

/**
 * Writes a line to the Quicksilver workflow log.
 */
function qs_nr_say(string $message): void {
  echo $message . "\n";
}

/**
 * Makes an untrusted value safe to put in a log line.
 *
 * Newlines would let a value forge additional log lines, and escape sequences
 * would let it rewrite the reader's terminal, so control characters are
 * replaced rather than printed. The result is also bounded, because the value
 * may be a whole commit message.
 */
function qs_nr_printable($value, int $limit = 200): string {
  if (!is_scalar($value)) {
    return '(' . gettype($value) . ')';
  }

  $text = (string) $value;

  // ASCII control bytes first, so this works even on invalid UTF-8.
  $text = (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $text);

  // Then the characters a byte-wise pass cannot see: C1 controls such as NEL,
  // the Unicode line and paragraph separators, and the bidi overrides that
  // reorder displayed text. Requires valid UTF-8, so repair it first, and keep
  // the byte-wise result if either step fails.
  if (function_exists('mb_convert_encoding')) {
    $utf8 = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    if (is_string($utf8)) {
      $text = $utf8;
    }
  }
  $stripped = preg_replace('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]/u', ' ', $text);
  if (is_string($stripped)) {
    $text = $stripped;
  }

  return qs_nr_clamp($text, $limit);
}

// Tests define QS_NR_NO_RUN so they can load the functions above without
// firing a deployment marker.
if (!defined('QS_NR_NO_RUN')) {
  qs_nr_main($_POST);
}
