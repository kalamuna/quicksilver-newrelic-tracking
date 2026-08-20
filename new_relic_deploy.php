<?php

/**
 * @file
 * Quicksilver hook: records a New Relic deployment marker on each deploy.
 *
 * Uses the NerdGraph changeTrackingCreateDeployment mutation, authenticated by
 * a New Relic User API key held in Pantheon Secrets Manager. The predecessor
 * read credentials from Pantheon's "newrelic" platform binding and posted to
 * the Deployments v0 REST API; both have been retired by their vendors.
 *
 * See README.md for setup, troubleshooting, and the reasoning behind the
 * deploy-tag, region and bounding choices.
 */

// Keep argument values out of fatal-error traces: the API key is passed as a
// scalar to the transport.
@ini_set('zend.exception_ignore_args', '1');

// This request is plumbing, not site traffic. Both checks are needed because
// this runs at file scope, outside the exception guard in qs_nr_main().
if (extension_loaded('newrelic') && function_exists('newrelic_ignore_transaction')) {
  newrelic_ignore_transaction();
}

// Guarded individually so tests can load this file deliberately. Note that a
// whole-file include guard would not work: PHP binds top-level functions at
// compile time, before any statement here runs.
defined('QS_NR_SECRET_NAME') || define('QS_NR_SECRET_NAME', 'new_relic_api_key');
defined('QS_NR_ENDPOINT_SECRET_NAME') || define('QS_NR_ENDPOINT_SECRET_NAME', 'new_relic_region');

defined('QS_NR_CONNECT_TIMEOUT') || define('QS_NR_CONNECT_TIMEOUT', 5);
defined('QS_NR_HTTP_TIMEOUT') || define('QS_NR_HTTP_TIMEOUT', 15);

// Seconds the hook may spend before it stops paginating. A hung worker marks
// the whole deploy workflow failed, which is worse than a missing marker.
defined('QS_NR_TOTAL_BUDGET') || define('QS_NR_TOTAL_BUDGET', 45);

// New Relic trims string fields at 4096 characters itself; bounding here just
// chooses where the cut lands and keeps huge commit messages off the wire.
defined('QS_NR_CHANGELOG_LIMIT') || define('QS_NR_CHANGELOG_LIMIT', 4000);
defined('QS_NR_DESCRIPTION_LIMIT') || define('QS_NR_DESCRIPTION_LIMIT', 1024);
defined('QS_NR_VERSION_LIMIT') || define('QS_NR_VERSION_LIMIT', 256);
defined('QS_NR_USER_LIMIT') || define('QS_NR_USER_LIMIT', 256);
defined('QS_NR_APPNAME_LIMIT') || define('QS_NR_APPNAME_LIMIT', 256);

// Neither a commit message nor an HTTP body has an inherent size limit, and
// exhausting memory_limit raises an E_ERROR that no catch can absorb.
defined('QS_NR_GIT_OUTPUT_LIMIT') || define('QS_NR_GIT_OUTPUT_LIMIT', 65536);
defined('QS_NR_RESPONSE_LIMIT') || define('QS_NR_RESPONSE_LIMIT', 1048576);

// ini_get() returns this when New Relic is not configured, rather than ''.
defined('QS_NR_APPNAME_DEFAULT') || define('QS_NR_APPNAME_DEFAULT', 'PHP Application');

// One entity search page holds at most 200 entities.
defined('QS_NR_MAX_SEARCH_PAGES') || define('QS_NR_MAX_SEARCH_PAGES', 3);
defined('QS_NR_MAX_CANDIDATES_LOGGED') || define('QS_NR_MAX_CANDIDATES_LOGGED', 20);
defined('QS_NR_MAX_PROBLEMS_REPORTED') || define('QS_NR_MAX_PROBLEMS_REPORTED', 10);

/**
 * Workflow types meaning "new code arrived". Integrated Composer sites report
 * sync_code_with_build, which Pantheon does not document.
 */
function qs_nr_sync_workflow_types(): array {
  return ['sync_code', 'sync_code_with_build'];
}

/**
 * Entry point: runs the hook and absorbs anything it throws.
 *
 * A deploy marker is never worth a stack trace in a deployment log, and
 * pantheon_get_secret() talks to a remote service with no documented failure
 * contract.
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
 *   Receives (endpoint, api_key, payload), returns a qs_nr_post_graphql()
 *   result. Injected for testing.
 * @param array|null $config
 *   Optional per-key overrides: 'api_key', 'app_name', 'endpoint', 'git',
 *   'clock'. Anything absent is read from the platform.
 */
function qs_nr_run(array $post, ?callable $transport = NULL, ?array $config = NULL): bool {
  $config = (array) $config;
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

  $api_key = array_key_exists('api_key', $config) ? $config['api_key'] : qs_nr_api_key();
  if ($api_key === NULL) {
    qs_nr_say('ERROR: no usable New Relic API key.');
    qs_nr_say('Store a New Relic *User* API key in the "' . QS_NR_SECRET_NAME . '" secret:');
    qs_nr_say('  terminus secret:site:set <site> ' . QS_NR_SECRET_NAME . ' <KEY> --type=runtime --scope=web');
    qs_nr_say('The scope must include "web" or the running site cannot read it, and Pantheon');
    qs_nr_say('caches secrets for 15 minutes, so a key set moments ago may not be visible yet.');
    return FALSE;
  }

  $app_name = array_key_exists('app_name', $config)
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

  $endpoint = array_key_exists('endpoint', $config)
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

    // GraphQL returns partial data alongside an errors array for shard and
    // field-level failures, so an entity that was found is still usable.
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

    $seen = array_slice(
      array_merge($seen, qs_nr_entity_names($search['data'] ?? NULL)),
      0,
      QS_NR_MAX_CANDIDATES_LOGGED + 1
    );

    $cursor = qs_nr_next_cursor($search['data'] ?? NULL);
    if ($cursor === NULL) {
      break;
    }

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

  qs_nr_say('Recording deployment "' . qs_nr_printable($deployment['version'])
    . '" against ' . qs_nr_printable($app_name) . ' (' . qs_nr_printable($guid) . ')...');

  $result = $transport($endpoint, $api_key, qs_nr_deployment_payload($deployment));

  // With no dataHandlingRules sent, a legacy REST failure behind an APM entity
  // is reported without blocking the save, so errors and a real deployment can
  // arrive together. Read the result first, then judge the errors.
  $created = qs_nr_dig($result['data'] ?? NULL, ['data', 'changeTrackingCreateDeployment']);
  $id = is_array($created) ? ($created['deploymentId'] ?? NULL) : NULL;
  $status = (int) ($result['status'] ?? 0);
  $plausible = (is_string($id) && trim($id) !== '') || (is_int($id) && $id > 0);
  $id = $status >= 200 && $status < 300 && $plausible ? (string) $id : NULL;

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
 * Reads a POST field as a trimmed string, tolerating arrays and missing keys.
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
 * Stored secrets routinely pick up a trailing newline. Printable ASCII is the
 * gate because whitespace, NUL bytes and non-breaking spaces all corrupt the
 * header and surface as a baffling 401 rather than a configuration error.
 */
function qs_nr_clean_api_key(string $key): ?string {
  $key = trim($key);
  if ($key === '') {
    return NULL;
  }

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
 * The ini value may be a semicolon-separated list; only the first entry is the
 * entity this deploy belongs to, the rest being rollup applications.
 */
function qs_nr_app_name(string $raw): ?string {
  $primary = trim(explode(';', $raw)[0]);

  if ($primary === '' || $primary === QS_NR_APPNAME_DEFAULT) {
    return NULL;
  }

  // No entity name can contain a control character, so such a name could never
  // match. Refusing beats reporting that two identical-looking names differ.
  if (preg_match('/[\x00-\x1F\x7F]/', $primary) === 1) {
    qs_nr_say('NOTE: newrelic.appname contains control characters and cannot match an entity.');
    return NULL;
  }

  return qs_nr_cut($primary, QS_NR_APPNAME_LIMIT);
}

/**
 * NerdGraph endpoints by region.
 *
 * GOV is absent from New Relic's published docs but present in their own
 * schema-generated client (newrelic-client-go pkg/region/region_constants.go).
 */
function qs_nr_endpoints(): array {
  return [
    'US' => 'https://api.newrelic.com/graphql',
    'EU' => 'https://api.eu.newrelic.com/graphql',
    'JP' => 'https://api.jp.newrelic.com/graphql',
    'GOV' => 'https://gov-api.newrelic.com/graphql',
  ];
}

/**
 * Returns the NerdGraph endpoint to use.
 *
 * Region is not recoverable from a User API key, so it is guessed from the
 * license key's prefix and can be overridden. The guess holds only while both
 * credentials belong to the same New Relic account.
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
    // The value is not echoed: transposing two secret:site:set commands would
    // otherwise print an API key into a durable workflow log.
    qs_nr_say('NOTE: the "' . QS_NR_ENDPOINT_SECRET_NAME . '" secret is not a region this hook knows.');
    qs_nr_say('Set it to one of: ' . implode(', ', array_keys($endpoints)) . '. Falling back to US.');
  }

  // ini values pick up stray quotes and whitespace in the wild.
  $license = trim($license, " \t\n\r\0\x0B\"'");
  $region = preg_match('/^(.+?)[xX]/', $license, $matches) === 1 ? $matches[1] : '';

  return $endpoints[qs_nr_region_name($region)];
}

/**
 * Maps a license key's region token to a region name.
 *
 * Real tokens look like eu01, eu03, euV09, jp01 and goV09: the leading letters
 * carry the region and the digits carry the cell, so only the letters can be
 * matched on.
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
 * The workflow payload is preferred: `environment` is a documented Quicksilver
 * variable, whereas PANTHEON_ENVIRONMENT is a platform detail a webphp hook
 * merely inherits.
 */
function qs_nr_environment(array $post = []): string {
  $from_payload = qs_nr_scalar($post, 'environment');
  if ($from_payload !== '') {
    return $from_payload;
  }

  return defined('PANTHEON_ENVIRONMENT') ? (string) constant('PANTHEON_ENVIRONMENT') : '';
}

/**
 * Runs a git command in the current directory and returns its trimmed stdout.
 *
 * Callers must escape any interpolated values.
 */
function qs_nr_git(string $arguments): string {
  if (!function_exists('shell_exec')) {
    return '';
  }

  $output = shell_exec(qs_nr_git_command($arguments));

  return is_string($output) ? trim($output) : '';
}

/**
 * Builds the shell command for a git read, capping its output.
 */
function qs_nr_git_command(string $arguments): string {
  return 'git ' . $arguments . ' 2>/dev/null | head -c ' . QS_NR_GIT_OUTPUT_LIMIT;
}

/**
 * Builds the ChangeTrackingDeploymentInput fields, minus entityGuid.
 *
 * @param array $post
 *   The Quicksilver POST body.
 * @param callable $git
 *   Receives a git argument string, returns trimmed stdout.
 * @param string $environment
 *   The environment being deployed to.
 * @param int|null $now_ms
 *   Marker timestamp in milliseconds. Defaults to now.
 */
function qs_nr_build_deployment(array $post, callable $git, string $environment, ?int $now_ms = NULL): array {
  $wf_type = qs_nr_scalar($post, 'wf_type');

  if ($wf_type === 'deploy') {
    // On test and live the tip commit is Pantheon's own build artifact, not
    // anyone's work, so its subject and author are useless and the deploy tag
    // is the only meaningful version.
    $tag = qs_nr_deploy_tag($git, $environment);
    $version = $tag !== '' ? $tag : $git('log --pretty=format:%h -1');
    $description = $environment !== ''
      ? 'Deployed to ' . $environment
      : 'Deployed via Pantheon';
    $changelog = $tag !== '' ? qs_nr_tag_annotation($git, $tag) : '';
  }
  else {
    $version = $git('log --pretty=format:%h -1');
    $subject = $git('log --pretty=format:%s -1');
    $description = $subject !== '' ? $subject : 'Code synced via Pantheon';
    $changelog = $git('log --pretty=format:%b -1');
  }

  // The schema requires a non-empty version.
  if (trim($version) === '') {
    $version = 'unknown';
  }

  $deployment = [
    'version' => qs_nr_cut($version, QS_NR_VERSION_LIMIT),
    'description' => qs_nr_clamp($description, QS_NR_DESCRIPTION_LIMIT),
    'deploymentType' => 'BASIC',
    'timestamp' => $now_ms ?? (int) round(microtime(TRUE) * 1000),
  ];

  // Identifier-shaped fields are cut without an ellipsis: a trailing "..." on
  // an address would split one deployer into two when faceting markers.
  $user = qs_nr_deploying_user($post, $git);
  if ($user !== '') {
    $deployment['user'] = qs_nr_cut($user, QS_NR_USER_LIMIT);
  }

  // Clamp the body before appending provenance, so truncation cannot delete
  // the line explaining where the code came from.
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
 * Finds this environment's deploy tag on the deployed commit.
 *
 * Only tags pointing at HEAD describe this deploy: `git describe` walks back to
 * the nearest ancestor tag, which on a Pantheon repository is a previous
 * release. A commit routinely carries tags for several environments, because
 * promoting to live re-tags a commit already tagged for test.
 */
function qs_nr_deploy_tag(callable $git, string $environment): string {
  $listing = $git('tag --points-at HEAD');
  $lines = explode("\n", $listing);

  // The read is byte-capped, so the last line may be a fragment of a real tag
  // name -- and a fragment still looks like a valid name.
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

  // A hand-made name like pantheon_live_43abc would cast to 43 and tie with
  // the genuine tag, and a 20-digit one would saturate to PHP_INT_MAX, so the
  // suffix must be a short run of digits to count.
  $prefix = 'pantheon_' . $environment . '_';
  $matching = [];
  foreach ($tags as $tag) {
    if (strpos($tag, $prefix) !== 0) {
      continue;
    }
    $sequence = substr($tag, strlen($prefix));
    if (preg_match('/^\d{1,9}$/', $sequence) !== 1) {
      continue;
    }
    $matching[] = ['sequence' => (int) $sequence, 'tag' => $tag];
  }

  if ($matching === []) {
    return qs_nr_non_platform_tag($tags);
  }

  // Highest sequence wins, breaking ties on the name so the choice never
  // depends on the order git listed refs in.
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
 * Labelling a live release "pantheon_test_38" is plausible enough to be
 * believed and wrong enough to misdirect a regression hunt, so returning
 * nothing lets the caller fall back to the commit SHA.
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
 * escapeshellarg() protects the shell but not git's option parsing: a tag named
 * "--points-at=HEAD" turns a tag lookup into a listing of every tag. `git tag`
 * will not create such names, but `git update-ref` will and a push carries it.
 */
function qs_nr_tag_is_safe(string $tag): bool {
  if ($tag === '' || strpos($tag, '-') === 0) {
    return FALSE;
  }

  return preg_match('#^[A-Za-z0-9._/+@-]+\z#', $tag) === 1;
}

/**
 * Reads an annotated tag's message, without the tag name git prefixes it with.
 *
 * Lightweight tags are skipped: `git tag -n` falls back to printing the tagged
 * commit's subject, which would read as a release note without being one.
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
 * Truncates a string, marking it, never exceeding $limit bytes in total.
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
 * The workflow's user_email is authoritative. The commit-author fallback is a
 * heuristic inherited from Pantheon's example, where user_role "super" means an
 * in-dashboard commit; it is limited to code syncs because a promotion's tip
 * commit is authored by Pantheon's own bot.
 */
function qs_nr_deploying_user(array $post, callable $git): string {
  $email = qs_nr_scalar($post, 'user_email');
  if ($email !== '') {
    return $email;
  }

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
 * The name travels as a typed variable rather than spliced into the query. The
 * filter is a substring match, so results still need filtering by exact name.
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
 * The deployment travels as a typed variable, so commit messages containing
 * quotes, newlines or backslashes reach New Relic intact.
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
 * Entity search treats `. , ; : * - _ ( )` as whitespace and matches substrings,
 * so a search for "site (live)" also returns "site (lastlive)". Only an exact
 * name is safe: a near match would attribute the deploy to another environment.
 */
function qs_nr_extract_entity_guid($response, string $app_name): ?string {
  foreach (qs_nr_entities($response) as $entity) {
    $name = $entity['name'] ?? NULL;
    $guid = $entity['guid'] ?? NULL;

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
 *
 * Untyped input: a transport can hand back NULL for a non-JSON body, and a
 * TypeError here would abort a hook that must not throw.
 */
function qs_nr_entities($response): array {
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
 *
 * Slices before mapping: a cap-sized response of empty objects decodes to
 * hundreds of thousands of arrays, and mapping them all would turn the byte cap
 * into an out-of-memory fatal.
 */
function qs_nr_entity_names($response, ?int $limit = NULL): array {
  $limit = $limit ?? QS_NR_MAX_CANDIDATES_LOGGED + 1;

  return array_map(static function (array $entity): string {
    return qs_nr_printable($entity['name'] ?? 'unnamed');
  }, array_slice(qs_nr_entities($response), 0, max(0, $limit)));
}

/**
 * Builds the curl options for a NerdGraph request.
 *
 * Passing $writer swaps CURLOPT_RETURNTRANSFER for a write callback; the two
 * set the same internal field, so only one may be used.
 */
function qs_nr_curl_options(string $endpoint, string $api_key, string $body, ?callable $writer = NULL): array {
  $options = [
    CURLOPT_URL => $endpoint,
    CURLOPT_POST => TRUE,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_CONNECTTIMEOUT => QS_NR_CONNECT_TIMEOUT,
    CURLOPT_TIMEOUT => QS_NR_HTTP_TIMEOUT,
    // Aborts on an over-limit declared size, and since curl 8.4.0 mid-transfer
    // too. The write callback is the backstop for older libcurl, where a server
    // can evade this by omitting Content-Length.
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
 * Returning fewer bytes than curl handed over aborts the transfer, which caps
 * the body without ever holding all of it.
 *
 * @param string $buffer
 *   Receives the body, by reference.
 * @param bool $overflowed
 *   Set to TRUE if the cap was hit, by reference.
 * @param int|null $limit
 *   Byte cap. Defaults to QS_NR_RESPONSE_LIMIT.
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
 * JSON_INVALID_UTF8_SUBSTITUTE is load-bearing: without it one non-UTF-8 byte
 * in a commit message, which any latin-1 authoring locale produces, makes
 * json_encode() return FALSE and the marker is lost.
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

  // curl_close() is a no-op since PHP 8.0 and deprecated in 8.5; unset() is the
  // portable way to free a refcounted CurlHandle.
  unset($handle);

  return qs_nr_interpret_response($ok !== FALSE, $status, $transport_error, $overflowed, $received);
}

/**
 * Turns a completed curl attempt into a result array.
 *
 * Split from the curl mechanics so the decisions -- whether a request failed,
 * which error wins, and whether the body is decoded -- are testable without a
 * socket.
 *
 * @return array
 *   Keys: 'status' (int), 'data' (decoded body or NULL), 'error' (string).
 */
function qs_nr_interpret_response(bool $ok, int $status, string $transport_error, bool $overflowed, string $body): array {
  // Overflow first: curl reports an aborted transfer as a generic write error,
  // which would otherwise hide the real reason.
  if ($overflowed) {
    return [
      'status' => $status,
      'data' => NULL,
      'error' => 'the response exceeded ' . QS_NR_RESPONSE_LIMIT . ' bytes and was abandoned',
    ];
  }

  if (!$ok) {
    return [
      'status' => $status,
      'data' => NULL,
      'error' => $transport_error !== '' ? $transport_error : 'the request failed',
    ];
  }

  return [
    'status' => $status,
    'data' => json_decode($body, TRUE),
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
 *   Problems found. Empty means the result is usable.
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
 * Prints anything wrong with a result. Returns TRUE when it is usable.
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
 * Follows $path through a decoded response. Returns NULL if any key is absent.
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
 * Makes an untrusted value safe and bounded for a log line.
 *
 * Newlines would let a value forge log lines and escape sequences would let it
 * rewrite the reader's terminal.
 */
function qs_nr_printable($value, int $limit = 200): string {
  if (!is_scalar($value)) {
    return '(' . gettype($value) . ')';
  }

  // ASCII controls first, so this works on invalid UTF-8 too.
  $text = (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', (string) $value);

  // Then C1 controls, line and paragraph separators, and bidi overrides, none
  // of which a byte-wise pass can see. Needs valid UTF-8, so repair first and
  // keep the byte-wise result if either step fails.
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

// Tests define QS_NR_NO_RUN to load these functions without firing a marker.
if (!defined('QS_NR_NO_RUN')) {
  qs_nr_main($_POST);
}
