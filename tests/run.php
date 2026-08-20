<?php

/**
 * @file
 * Tests for new_relic_deploy.php.
 *
 * No framework and no dependencies, so this runs anywhere PHP does:
 *
 *   php tests/run.php
 *
 * Everything that reaches git, the clock, the network or the platform is
 * either injected or split behind a pure function, so the payload shape, the
 * region selection, the error classification and the request headers can all
 * be asserted without a checkout and without a network.
 */

define('QS_NR_NO_RUN', TRUE);
require __DIR__ . '/../new_relic_deploy.php';

$failures = 0;
$assertions = 0;

/**
 * Asserts that two values match, and reports either way.
 */
function check(string $label, $expected, $actual): void {
  global $failures, $assertions;
  $assertions++;

  if ($expected === $actual) {
    echo "  ok    " . $label . "\n";
    return;
  }

  $failures++;
  echo "  FAIL  " . $label . "\n";
  echo "        expected: " . var_export($expected, TRUE) . "\n";
  echo "        actual:   " . var_export($actual, TRUE) . "\n";
}

/**
 * Asserts that a haystack contains a needle.
 *
 * The haystack is deliberately untyped. A regression often makes an upstream
 * expression return NULL, and a typed parameter would turn that into a
 * TypeError that aborts the run: the suite would exit non-zero with zero
 * reported failures, and every later assertion would silently never run.
 */
function check_contains(string $label, string $needle, $haystack): void {
  if (!is_string($haystack)) {
    check($label . ' (haystack was ' . gettype($haystack) . ', not a string)', 'string', gettype($haystack));
    return;
  }

  check($label, TRUE, strpos($haystack, $needle) !== FALSE);
}

/**
 * Returns the first element, or a placeholder rather than an undefined index.
 */
function first(array $items) {
  return $items === [] ? NULL : $items[array_key_first($items)];
}

/**
 * Runs a callable with output captured.
 */
function capture(callable $callable): array {
  ob_start();
  $return = $callable();

  return ['output' => (string) ob_get_clean(), 'return' => $return];
}

/**
 * Builds a git reader stub from an argument-string to output map.
 */
function git_stub(array $responses): callable {
  return static function (string $arguments) use ($responses): string {
    return $responses[$arguments] ?? '';
  };
}

/**
 * Records every git argument string a caller asks for.
 */
function git_spy(array &$seen, array $responses = []): callable {
  return static function (string $arguments) use (&$seen, $responses): string {
    $seen[] = $arguments;

    return $responses[$arguments] ?? '';
  };
}

/**
 * The git responses a normal authored commit produces.
 */
function git_responses(array $overrides = []): array {
  return $overrides + [
    'rev-parse --is-inside-work-tree' => 'true',
    'log --pretty=format:%H -1' => '9f8e7d6c5b4a39281706f5e4d3c2b1a098765432',
    'log --pretty=format:%h -1' => '9f8e7d6',
    'log --pretty=format:%s -1' => 'S511-1171: Purge Twig development settings',
    'log --pretty=format:%b -1' => 'Live was serving uncached Twig.',
    'log --pretty=format:%ae -1' => 'dashboard-user@example.com',
  ];
}

echo "\nsync_code\n";
$deployment = qs_nr_build_deployment(
  ['wf_type' => 'sync_code', 'user_email' => 'dev@example.com'],
  git_stub(git_responses()),
  'dev',
  1_700_000_000_000
);
check('version is the short SHA', '9f8e7d6', $deployment['version']);
check('description is the commit subject', 'S511-1171: Purge Twig development settings', $deployment['description']);
check_contains('changelog carries the commit body', 'Live was serving uncached Twig.', $deployment['changelog']);
check_contains('changelog notes the push', '(Triggered by a remote git push.)', $deployment['changelog']);
check('commit is the full SHA', '9f8e7d6c5b4a39281706f5e4d3c2b1a098765432', $deployment['commit']);
check('user comes from the workflow', 'dev@example.com', $deployment['user']);
check('deploymentType is BASIC', 'BASIC', $deployment['deploymentType']);
check('timestamp is passed through', 1_700_000_000_000, $deployment['timestamp']);

echo "\ntimestamp units\n";
$now = qs_nr_build_deployment(['wf_type' => 'sync_code'], git_stub([]), 'dev');
check(
  'the default timestamp is milliseconds, not seconds',
  TRUE,
  $now['timestamp'] > 1_600_000_000_000 && $now['timestamp'] < 100_000_000_000_000
);

echo "\nsync_code_with_build (integrated composer sites)\n";
check(
  'both sync workflow types are recognised',
  ['sync_code', 'sync_code_with_build'],
  qs_nr_sync_workflow_types()
);
$build = qs_nr_build_deployment(
  ['wf_type' => 'sync_code_with_build', 'user_email' => 'dev@example.com'],
  git_stub(git_responses()),
  'dev',
  1_700_000_000_000
);
check('handled like sync_code, not skipped', '9f8e7d6', $build['version']);
check('description is the commit subject', 'S511-1171: Purge Twig development settings', $build['description']);

echo "\nunknown workflow types are refused, not mislabelled\n";
$unknown = capture(static function (): bool {
  return qs_nr_run(['wf_type' => 'clear_cache']);
});
check('run() returns FALSE', FALSE, $unknown['return']);
check_contains('and says why', 'does not understand the workflow type "clear_cache"', $unknown['output']);
$empty_post = capture(static function (): bool {
  return qs_nr_run([]);
});
check('an empty POST is also refused', FALSE, $empty_post['return']);

echo "\ndeploy tag selection\n";
check(
  'a tag pointing at HEAD is used, not an ancestor',
  'pantheon_live_23',
  qs_nr_deploy_tag(git_stub(['tag --points-at HEAD' => 'pantheon_live_23']), 'live')
);
check(
  'the tag for this environment wins when several point at HEAD',
  'pantheon_test_38',
  qs_nr_deploy_tag(git_stub([
    'tag --points-at HEAD' => "pantheon_live_23\npantheon_test_38",
  ]), 'test')
);
check(
  'the highest sequence number wins within an environment',
  'pantheon_live_100',
  qs_nr_deploy_tag(git_stub([
    'tag --points-at HEAD' => "pantheon_live_9\npantheon_live_100\npantheon_live_23",
  ]), 'live')
);
check(
  'a human release tag is used when no platform tag matches',
  'v4.0.14',
  qs_nr_deploy_tag(git_stub(['tag --points-at HEAD' => 'v4.0.14']), 'live')
);
// A live marker labelled "pantheon_test_38" is plausible enough to be believed
// and wrong enough to send a regression hunt to the wrong build. An honest
// commit SHA is better, so the caller must be allowed to fall through to it.
check(
  'another environment\'s platform tag is never adopted',
  '',
  qs_nr_deploy_tag(git_stub(['tag --points-at HEAD' => 'pantheon_test_38']), 'live')
);
check(
  'a release tag still wins over a foreign platform tag',
  'v4.0.14',
  qs_nr_deploy_tag(git_stub([
    'tag --points-at HEAD' => "pantheon_test_38\nv4.0.14",
  ]), 'live')
);
check(
  'with no environment, a platform tag is still not adopted',
  '',
  qs_nr_deploy_tag(git_stub(['tag --points-at HEAD' => 'pantheon_live_23']), '')
);
check(
  'no tag at HEAD returns empty, so the caller can fall back',
  '',
  qs_nr_deploy_tag(git_stub([]), 'live')
);

echo "\ndeploy\n";
$deploy = qs_nr_build_deployment(
  ['wf_type' => 'deploy', 'user_email' => 'release@example.com'],
  git_stub(git_responses([
    'tag --points-at HEAD' => "pantheon_live_23\npantheon_test_38",
    "cat-file -t 'refs/tags/pantheon_live_23'" => 'tag',
    "tag -l -n99 'pantheon_live_23'" => "pantheon_live_23  'v4.0.14'",
  ])),
  'live',
  1_700_000_000_000
);
check('version is this environment\'s deploy tag', 'pantheon_live_23', $deploy['version']);
check('description names the environment', 'Deployed to live', $deploy['description']);
check_contains('changelog carries the tag annotation', "'v4.0.14'", $deploy['changelog']);
check(
  'the tag name is not repeated inside the changelog',
  FALSE,
  strpos($deploy['changelog'], 'pantheon_live_23') !== FALSE
);
check_contains('changelog notes the promotion', '(Deployed between environments via Pantheon.)', $deploy['changelog']);
check(
  'the misleading build-artifact subject is not used as the description',
  FALSE,
  strpos($deploy['description'], 'S511-1171') !== FALSE
);

echo "\ndeploy with no tag at HEAD\n";
$untagged = qs_nr_build_deployment(
  ['wf_type' => 'deploy', 'user_email' => 'release@example.com'],
  git_stub(git_responses()),
  'test',
  1_700_000_000_000
);
check('version falls back to the short SHA', '9f8e7d6', $untagged['version']);

echo "\nlightweight tags carry no annotation\n";
check(
  'a lightweight tag yields no annotation, so a commit subject cannot pose as a release note',
  '',
  qs_nr_tag_annotation(git_stub([
    "cat-file -t 'refs/tags/v1.0'" => 'commit',
    "tag -l -n99 'v1.0'" => 'v1.0 some commit subject',
  ]), 'v1.0')
);

echo "\nshell metacharacters in a tag name\n";
$seen = [];
$hostile_tag = 'v9$(touch /tmp/qs_nr_pwned)`id`;x';
qs_nr_tag_annotation(git_spy($seen, [
  "cat-file -t " . escapeshellarg('refs/tags/' . $hostile_tag) => 'tag',
]), $hostile_tag);
check('both git calls are made', 2, count($seen));
foreach ($seen as $index => $arguments) {
  check(
    'git call ' . $index . ' quotes every hostile character',
    FALSE,
    strpos($arguments, '$(touch /tmp') !== FALSE
      && strpos($arguments, escapeshellarg($hostile_tag)) === FALSE
      && strpos($arguments, escapeshellarg('refs/tags/' . $hostile_tag)) === FALSE
  );
}
check(
  'the annotation lookup passes the tag through escapeshellarg',
  'tag -l -n99 ' . escapeshellarg($hostile_tag),
  $seen[1]
);

echo "\nunsafe tag names are filtered before reaching git\n";
check('a normal tag is safe', TRUE, qs_nr_tag_is_safe('pantheon_live_23'));
check('an option-like tag is rejected', FALSE, qs_nr_tag_is_safe('--points-at=HEAD'));
check('a bare dash flag is rejected', FALSE, qs_nr_tag_is_safe('-d'));
check('an empty name is rejected', FALSE, qs_nr_tag_is_safe(''));
check('a shell metacharacter is rejected', FALSE, qs_nr_tag_is_safe('v1$(id)'));
check('a slashed tag is allowed', TRUE, qs_nr_tag_is_safe('release/4.0.14'));
check(
  'an option-like tag never becomes the deploy version',
  '',
  qs_nr_deploy_tag(git_stub(['tag --points-at HEAD' => "--points-at=HEAD\n-d"]), 'live')
);

echo "\ntag sequence poisoning\n";
check(
  'a non-numeric suffix cannot tie with the real tag',
  'pantheon_live_43',
  qs_nr_deploy_tag(git_stub([
    'tag --points-at HEAD' => "pantheon_live_43\npantheon_live_43abc",
  ]), 'live')
);
check(
  'a saturating numeric suffix does not win by integer overflow',
  'pantheon_live_43',
  qs_nr_deploy_tag(git_stub([
    'tag --points-at HEAD' => "pantheon_live_43\npantheon_live_99999999999999999999999",
  ]), 'live')
);
check(
  'a zero-padded duplicate does not silently displace the real tag',
  'pantheon_live_8',
  qs_nr_deploy_tag(git_stub([
    'tag --points-at HEAD' => "pantheon_live_08\npantheon_live_7\npantheon_live_8",
  ]), 'live')
);
check(
  'the same set in the opposite order picks the same tag',
  'pantheon_live_8',
  qs_nr_deploy_tag(git_stub([
    'tag --points-at HEAD' => "pantheon_live_8\npantheon_live_7\npantheon_live_08",
  ]), 'live')
);

echo "\ndashboard commit (user_role super)\n";
$dashboard = qs_nr_build_deployment(
  ['wf_type' => 'sync_code', 'user_role' => 'super', 'user_email' => ''],
  git_stub(git_responses()),
  'dev',
  1_700_000_000_000
);
check('user comes from the commit author', 'dashboard-user@example.com', $dashboard['user']);
check_contains('changelog notes the dashboard', '(Commit made via the Pantheon dashboard.)', $dashboard['changelog']);

echo "\nempty repository state\n";
$empty = qs_nr_build_deployment(['wf_type' => 'sync_code'], git_stub([]), '', 1_700_000_000_000);
check('version is never empty, because the schema requires it', 'unknown', $empty['version']);
check('description has a fallback', 'Code synced via Pantheon', $empty['description']);
check('no user key when nobody is known', FALSE, array_key_exists('user', $empty));
check('no commit key when git is silent', FALSE, array_key_exists('commit', $empty));

echo "\nmalformed POST values\n";
check('an array wf_type reads as empty, not "Array"', '', qs_nr_scalar(['wf_type' => ['deploy']], 'wf_type'));
check('an array user_email reads as empty', '', qs_nr_scalar(['user_email' => ['x@y.z']], 'user_email'));
check('a missing key reads as empty', '', qs_nr_scalar([], 'wf_type'));
check('surrounding whitespace is trimmed', 'deploy', qs_nr_scalar(['wf_type' => "  deploy\n"], 'wf_type'));
$array_post = qs_nr_build_deployment(
  ['wf_type' => 'sync_code', 'user_email' => ['a@b.c']],
  git_stub(git_responses()),
  'dev',
  1_700_000_000_000
);
check('an array user_email produces no user key', FALSE, array_key_exists('user', $array_post));

echo "\nhostile commit messages survive encoding\n";
$hostile = 'Fix "quoted" and \'quoted\' and $(whoami) and \\backslash';
$payload = qs_nr_deployment_payload(qs_nr_build_deployment(
  ['wf_type' => 'sync_code', 'user_email' => 'dev@example.com'],
  git_stub(git_responses([
    'log --pretty=format:%s -1' => $hostile,
    'log --pretty=format:%b -1' => "line one\nline two",
  ])),
  'dev',
  1_700_000_000_000
));
$round_tripped = json_decode((string) qs_nr_encode_body($payload), TRUE);
check(
  'quotes and metacharacters are preserved verbatim',
  $hostile,
  $round_tripped['variables']['deployment']['description']
);
check_contains('newlines are preserved', "line one\nline two", $round_tripped['variables']['deployment']['changelog']);

echo "\ninvalid UTF-8 does not lose the marker\n";
$latin1 = qs_nr_deployment_payload(qs_nr_build_deployment(
  ['wf_type' => 'sync_code'],
  git_stub(git_responses(['log --pretty=format:%s -1' => "Fix caf\xE9 encoding"])),
  'dev',
  1_700_000_000_000
));
$encoded = qs_nr_encode_body($latin1);
check('a latin-1 commit subject still encodes', TRUE, is_string($encoded));
check(
  'the bad byte is substituted rather than failing the whole request',
  TRUE,
  is_string($encoded) && strpos((string) json_decode($encoded, TRUE)['variables']['deployment']['description'], 'Fix caf') === 0
);

echo "\noversized changelog\n";
$huge = qs_nr_build_deployment(
  ['wf_type' => 'sync_code'],
  git_stub(git_responses(['log --pretty=format:%b -1' => str_repeat('a', 50_000)])),
  'dev',
  1_700_000_000_000
);
check('the changelog is clamped', TRUE, strlen($huge['changelog']) <= QS_NR_CHANGELOG_LIMIT);
check_contains('and says it was cut', '...', $huge['changelog']);
check_contains(
  'the provenance note survives truncation',
  '(Triggered by a remote git push.)',
  $huge['changelog']
);
check('short text is untouched', 'abc', qs_nr_clamp('abc', 4000));
check('the clamp never exceeds its own limit', 5, strlen(qs_nr_clamp('abcdefghijklmnop', 5)));
check('a tiny limit still returns something', 2, strlen(qs_nr_clamp('abcdefgh', 2)));
check('a zero limit returns empty', '', qs_nr_clamp('abc', 0));
if (function_exists('mb_strcut')) {
  check(
    'clamping does not split a multi-byte character',
    TRUE,
    (bool) preg_match('//u', qs_nr_clamp(str_repeat("\u{20AC}", 10), 8))
  );
}
else {
  // The code falls back to substr() without mbstring, so the suite must not
  // assert a guarantee the code does not make in that configuration.
  check('without mbstring, clamping still respects its byte budget', TRUE,
    strlen(qs_nr_clamp(str_repeat("\u{20AC}", 10), 8)) <= 8);
}

echo "\nan oversized commit subject is bounded too\n";
$long_subject = qs_nr_build_deployment(
  ['wf_type' => 'sync_code'],
  git_stub(git_responses(['log --pretty=format:%s -1' => str_repeat('s', 50_000)])),
  'dev',
  1_700_000_000_000
);
check(
  'description is clamped, not only changelog',
  TRUE,
  strlen($long_subject['description']) <= QS_NR_DESCRIPTION_LIMIT
);
$long_user = qs_nr_build_deployment(
  ['wf_type' => 'sync_code', 'user_email' => str_repeat('u', 5000) . '@example.com'],
  git_stub(git_responses()),
  'dev',
  1_700_000_000_000
);
check('user is clamped', TRUE, strlen($long_user['user']) <= QS_NR_USER_LIMIT);

echo "\nGraphQL documents are static\n";
$expected_search = 'query($queryBuilder: EntitySearchQueryBuilder!, $cursor: String) {'
  . ' actor { entitySearch(queryBuilder: $queryBuilder) {'
  . ' results(cursor: $cursor) { nextCursor entities { guid name } } } } }';
$search_payload = qs_nr_entity_search_payload('my-site (live)');
check('the entity search query is a fixed document', $expected_search, $search_payload['query']);
check('the application name travels as a variable', 'my-site (live)', $search_payload['variables']['queryBuilder']['name']);
check('scoped to APM', 'APM', $search_payload['variables']['queryBuilder']['domain']);
check('scoped to applications', 'APPLICATION', $search_payload['variables']['queryBuilder']['type']);
check('the first page sends a null cursor', NULL, $search_payload['variables']['cursor']);
check(
  'a later page sends its cursor as a variable',
  'CURSOR-2',
  qs_nr_entity_search_payload('my-site (live)', 'CURSOR-2')['variables']['cursor']
);
check(
  'the query is identical whatever the cursor',
  $expected_search,
  qs_nr_entity_search_payload('my-site (live)', 'CURSOR-2')['query']
);

$expected_mutation = 'mutation($deployment: ChangeTrackingDeploymentInput!) {'
  . ' changeTrackingCreateDeployment(deployment: $deployment) {'
  . ' deploymentId entityGuid version } }';
$mutation_payload = qs_nr_deployment_payload(['version' => 'x") { evil } #']);
check('the mutation is a fixed document', $expected_mutation, $mutation_payload['query']);
check(
  'a hostile version lands in variables, not in the document',
  'x") { evil } #',
  $mutation_payload['variables']['deployment']['version']
);

echo "\nentity selection requires an exact name\n";
// The real shape returned by New Relic for a "511-org (live)" search: the
// name filter compiles to LIKE, so "lastlive" comes back too.
$fuzzy = [
  'data' => ['actor' => ['entitySearch' => ['count' => 2, 'results' => ['entities' => [
    ['guid' => 'guid-lastlive', 'name' => '511-org (lastlive)', 'entityType' => 'APM_APPLICATION_ENTITY'],
    ['guid' => 'guid-live', 'name' => '511-org (live)', 'entityType' => 'APM_APPLICATION_ENTITY'],
  ]]]]],
];
check('the exact name wins over search order', 'guid-live', qs_nr_extract_entity_guid($fuzzy, '511-org (live)'));
// The decoy above does not contain the target as a substring, so on its own it
// cannot detect a substring comparison. These do.
check(
  'a name strictly containing the target is refused',
  NULL,
  qs_nr_extract_entity_guid([
    'data' => ['actor' => ['entitySearch' => ['results' => ['entities' => [
      ['guid' => 'guid-superstring', 'name' => '511-org (live) old'],
    ]]]]],
  ], '511-org (live)')
);
check(
  'a suffixed canary name is refused',
  NULL,
  qs_nr_extract_entity_guid([
    'data' => ['actor' => ['entitySearch' => ['results' => ['entities' => [
      ['guid' => 'guid-canary', 'name' => '511-org (live)-canary'],
    ]]]]],
  ], '511-org (live)')
);
check(
  'the exact name still wins when a superstring is listed first',
  'guid-live',
  qs_nr_extract_entity_guid([
    'data' => ['actor' => ['entitySearch' => ['results' => ['entities' => [
      ['guid' => 'guid-superstring', 'name' => '511-org (live) old'],
      ['guid' => 'guid-live', 'name' => '511-org (live)'],
    ]]]]],
  ], '511-org (live)')
);
check(
  'a lone fuzzy match is refused, not accepted',
  NULL,
  qs_nr_extract_entity_guid([
    'data' => ['actor' => ['entitySearch' => ['count' => 1, 'results' => ['entities' => [
      ['guid' => 'guid-lastlive', 'name' => '511-org (lastlive)'],
    ]]]]],
  ], '511-org (live)')
);
check(
  'no results returns NULL',
  NULL,
  qs_nr_extract_entity_guid([
    'data' => ['actor' => ['entitySearch' => ['count' => 0, 'results' => ['entities' => []]]]],
  ], '511-org (live)')
);
check('an error response returns NULL', NULL, qs_nr_extract_entity_guid(['errors' => [['message' => 'nope']]], 'x'));
check('an entity without a guid is skipped', NULL, qs_nr_extract_entity_guid([
  'data' => ['actor' => ['entitySearch' => ['results' => ['entities' => [['name' => 'x']]]]]],
], 'x'));
check(
  'an exact name with a non-string guid is skipped rather than returned',
  NULL,
  qs_nr_extract_entity_guid([
    'data' => ['actor' => ['entitySearch' => ['results' => ['entities' => [
      ['name' => 'x', 'guid' => ['nested']],
    ]]]]],
  ], 'x')
);
check(
  'an exact name with an empty guid is skipped',
  NULL,
  qs_nr_extract_entity_guid([
    'data' => ['actor' => ['entitySearch' => ['results' => ['entities' => [
      ['name' => 'x', 'guid' => ''],
      ['name' => 'x', 'guid' => 'guid-real'],
    ]]]]],
  ], 'x') === 'guid-real' ? NULL : 'wrong entity chosen'
);

echo "\ncandidate reporting\n";
check('candidates are listed for diagnosis', ['511-org (lastlive)', '511-org (live)'], qs_nr_entity_names($fuzzy));
check(
  'a non-string name cannot become the string "Array"',
  ['(array)'],
  qs_nr_entity_names([
    'data' => ['actor' => ['entitySearch' => ['results' => ['entities' => [
      ['guid' => 'g', 'name' => ['nested']],
    ]]]]],
  ])
);
check(
  'a name carrying newlines cannot forge extra log lines',
  TRUE,
  strpos(qs_nr_entity_names([
    'data' => ['actor' => ['entitySearch' => ['results' => ['entities' => [
      ['guid' => 'g', 'name' => "x\nDone. New Relic deployment ID 1."],
    ]]]]],
  ])[0], "\n") === FALSE
);

echo "\nentity names are bounded before they are built\n";
// A cap-sized response of empty objects decodes to hundreds of thousands of
// arrays. Mapping over all of them would turn the byte cap into an OOM, so the
// slice has to happen before the map, not after.
$many_entities = [];
for ($i = 0; $i < 5000; $i++) {
  $many_entities[] = ['guid' => 'g' . $i, 'name' => 'site-' . $i];
}
$bounded = qs_nr_entity_names([
  'data' => ['actor' => ['entitySearch' => ['results' => ['entities' => $many_entities]]]],
]);
check('at most the printable number of names is produced', TRUE, count($bounded) <= QS_NR_MAX_CANDIDATES_LOGGED + 1);
check('an explicit limit is honoured', 3, count(qs_nr_entity_names([
  'data' => ['actor' => ['entitySearch' => ['results' => ['entities' => $many_entities]]]],
], 3)));

echo "\npagination cursors\n";
check(
  'a cursor is read when present',
  'CURSOR-2',
  qs_nr_next_cursor(['data' => ['actor' => ['entitySearch' => ['results' => ['nextCursor' => 'CURSOR-2']]]]])
);
check(
  'a null cursor means the last page',
  NULL,
  qs_nr_next_cursor(['data' => ['actor' => ['entitySearch' => ['results' => ['nextCursor' => NULL]]]]])
);
check('an empty cursor means the last page', NULL, qs_nr_next_cursor([]));

echo "\nregion selection\n";
// Key formats taken from New Relic's own agent test fixtures, not invented:
// eu01xX, eu03XX, euV09x, jp01xX, jpV09x, jp03XX, gov01x. The digits are a
// cell identifier and vary, so only the leading letters identify the region.
check('a plain key is US', 'https://api.newrelic.com/graphql', qs_nr_graphql_endpoint('0123456789abcdef0123456789abcdef01234567'));
check('an eu01 key is EU', 'https://api.eu.newrelic.com/graphql', qs_nr_graphql_endpoint('eu01xX6789abcdef0123456789abcdef01234567'));
check('an eu03 key with an uppercase X is EU', 'https://api.eu.newrelic.com/graphql', qs_nr_graphql_endpoint('eu03XX6789abcdef0123456789abcdef01234567'));
check('a euV09 key is EU', 'https://api.eu.newrelic.com/graphql', qs_nr_graphql_endpoint('euV09x6789abcdef0123456789abcdef01234567'));
check('a jp01 key is JP', 'https://api.jp.newrelic.com/graphql', qs_nr_graphql_endpoint('jp01xX6789abcdef0123456789abcdef01234567'));
check('a jp03 key is JP', 'https://api.jp.newrelic.com/graphql', qs_nr_graphql_endpoint('jp03XX6789abcdef0123456789abcdef01234567'));
check('a jpV09 key is JP', 'https://api.jp.newrelic.com/graphql', qs_nr_graphql_endpoint('jpV09x6789abcdef0123456789abcdef01234567'));
// goV09x is the real fixture in New Relic's agent tests; gov01x is not a
// format they issue, so it is asserted only as a shape this must tolerate.
check('the real goV09x fixture is GOV', 'https://gov-api.newrelic.com/graphql', qs_nr_graphql_endpoint('goV09x6789abcdef0123456789abcdef01234567'));
check('a gov-prefixed variant is GOV', 'https://gov-api.newrelic.com/graphql', qs_nr_graphql_endpoint('gov01x6789abcdef0123456789abcdef01234567'));
// Both of these are documented upstream as NOT selecting a region: the first
// has the marker in the wrong place, the second has none at all.
check('an xGOV01 key is US', 'https://api.newrelic.com/graphql', qs_nr_graphql_endpoint('xGOV016789abcdef0123456789abcdef01234567'));
check('a GOV007 key with no marker is US', 'https://api.newrelic.com/graphql', qs_nr_graphql_endpoint('GOV0076789abcdef0123456789abcdef01234567'));
check('region names map from letters, ignoring the cell digits', 'EU', qs_nr_region_name('eu03'));
check('an unknown region family is US', 'US', qs_nr_region_name('zz01'));
check('an empty region is US', 'US', qs_nr_region_name(''));
check('an empty license is US', 'https://api.newrelic.com/graphql', qs_nr_graphql_endpoint(''));
check(
  'quoting and whitespace do not misroute an EU account',
  'https://api.eu.newrelic.com/graphql',
  qs_nr_graphql_endpoint(" \"eu01xx6b1d5f8a9c0e3b7d2f4a6c8e0b2d4f6a8c\"\n")
);
// New Relic publishes no FedRAMP NerdGraph endpoint, and the plausible-looking
// gov-api.newrelic.com answers identically to the US host. Falling back to US
// is honest; inventing a hostname would fake support for a region.
check(
  'a future EU cell still routes to EU',
  'https://api.eu.newrelic.com/graphql',
  qs_nr_graphql_endpoint('eu99xx6b1d5f8a9c0e3b7d2f4a6c8e0b2d4f6a8c')
);
check(
  'only known endpoints are reachable',
  ['US', 'EU', 'JP', 'GOV'],
  array_keys(qs_nr_endpoints())
);

echo "\nregion override\n";
check(
  'a named region overrides the license guess',
  'https://api.jp.newrelic.com/graphql',
  qs_nr_graphql_endpoint('eu01xx6b1d5f8a9c0e3b7d2f4a6c8e0b2d4f6a8c', 'JP')
);
check(
  'a lowercase region name is accepted',
  'https://api.eu.newrelic.com/graphql',
  qs_nr_graphql_endpoint('', 'eu')
);
check(
  'a full documented URL is accepted',
  'https://api.eu.newrelic.com/graphql',
  qs_nr_graphql_endpoint('', 'https://api.eu.newrelic.com/graphql')
);
$bad_override = capture(static function () {
  return qs_nr_graphql_endpoint('', 'https://evil.example.com/graphql');
});
check('an arbitrary URL is refused', 'https://api.newrelic.com/graphql', $bad_override['return']);
check_contains('and the refusal is logged', 'is not a region this hook knows', $bad_override['output']);
check_contains('naming the secret to fix', 'new_relic_region', $bad_override['output']);
// The override is read from a secret. An operator who transposes two
// secret:site:set commands would otherwise print their API key to a durable
// workflow log, so the value must never be echoed back.
$leaky = capture(static function () {
  return qs_nr_graphql_endpoint('', 'NRAK-ABCDEFGHIJKLMNOPQRSTUVWXYZ012345');
});
check(
  'the rejected value is never echoed, because it may be a misplaced secret',
  FALSE,
  strpos($leaky['output'], 'NRAK-ABCDEFGHIJKLMNOPQRSTUVWXYZ012345') !== FALSE
);
check('and it still falls back to US', 'https://api.newrelic.com/graphql', $leaky['return']);
check('an empty override changes nothing', 'https://api.newrelic.com/graphql', qs_nr_graphql_endpoint('', ''));

echo "\napplication name resolution\n";
check('a normal name passes through', '511-org (live)', qs_nr_app_name('511-org (live)'));
check('only the primary name of a rollup list is used', 'my-site (live)', qs_nr_app_name('my-site (live);rollup-one;rollup-two'));
check('the unconfigured PHP default is rejected', NULL, qs_nr_app_name('PHP Application'));
check('an empty value is rejected', NULL, qs_nr_app_name(''));
check('whitespace only is rejected', NULL, qs_nr_app_name("  \n"));
check('surrounding whitespace is trimmed', 'my-site (live)', qs_nr_app_name('  my-site (live)  '));
check('a very long name is bounded', TRUE, strlen((string) qs_nr_app_name(str_repeat('a', 5000))) <= QS_NR_APPNAME_LIMIT);
// A control character cannot appear in a New Relic entity name, so a name
// carrying one can never match. Refusing beats reporting that two
// identical-looking strings failed to match.
check('a name with a NUL byte is refused', NULL, capture(static function () {
  return qs_nr_app_name("my-site\x00 (live)");
})['return']);
check('the version field is cut without an ellipsis', TRUE, strpos(
  qs_nr_build_deployment(
    ['wf_type' => 'sync_code'],
    git_stub(git_responses(['log --pretty=format:%h -1' => str_repeat('a', 5000)])),
    'dev',
    1_700_000_000_000
  )['version'],
  '...'
) === FALSE);
// An address ending "..." would silently split one deployer into two when
// faceting markers by user.
check('the user field is cut without an ellipsis', TRUE, strpos(
  qs_nr_build_deployment(
    ['wf_type' => 'sync_code', 'user_email' => str_repeat('u', 5000) . '@example.com'],
    git_stub(git_responses()),
    'dev',
    1_700_000_000_000
  )['user'],
  '...'
) === FALSE);

echo "\ntruncated git output cannot invent a tag\n";
// The git read is byte-capped, so the last line can be a fragment of a real
// tag name -- and a fragment still looks like a valid name.
$truncated = str_repeat("pantheon_live_1\n", 4500);
$truncated = substr($truncated, 0, QS_NR_GIT_OUTPUT_LIMIT) . 'pantheon_live_999';
check(
  'the final, possibly-fragmentary line is discarded',
  FALSE,
  qs_nr_deploy_tag(git_stub(['tag --points-at HEAD' => $truncated]), 'live') === 'pantheon_live_999'
);
check('a tag name with a trailing newline is refused', FALSE, qs_nr_tag_is_safe("foo\n"));

echo "\nAPI key hygiene\n";
check('a trailing newline is trimmed rather than corrupting the header', 'NRAK-ABC123', qs_nr_clean_api_key("NRAK-ABC123\n"));
$absent = capture(static function () {
  return qs_nr_clean_api_key('');
});
check('an empty key is rejected', NULL, $absent['return']);
check(
  'an absent key says nothing extra, because the caller explains it',
  '',
  $absent['output']
);
$whitespace_only = capture(static function () {
  return qs_nr_clean_api_key("   \n  ");
});
check('a whitespace-only key is rejected', NULL, $whitespace_only['return']);
check('and is treated as absent, not malformed', '', $whitespace_only['output']);
$interior = capture(static function () {
  return qs_nr_clean_api_key("NRAK-ABC 123");
});
check('a key with interior whitespace is rejected', NULL, $interior['return']);
check_contains('and the reason is logged', 'contains whitespace', $interior['output']);
// No space in the injected header: with one, the space alone would fail the
// gate and the test would pass without proving newlines are rejected.
$newline_injected = capture(static function () {
  return qs_nr_clean_api_key("NRAK-ABC\nX-Injected:1");
});
check('a header-injecting key is rejected', NULL, $newline_injected['return']);
check(
  'a bare trailing newline is trimmed, not treated as malformed',
  'NRAK-ABC',
  qs_nr_clean_api_key("NRAK-ABC\n")
);
check('an interior NUL byte is rejected', NULL, capture(static function () {
  return qs_nr_clean_api_key("NRAK-ABC\x00X-Injected:1");
})['return']);
check('a non-breaking space is rejected', NULL, capture(static function () {
  return qs_nr_clean_api_key("NRAK-ABC\u{00A0}DEF");
})['return']);

echo "\nrequest construction\n";
$options = qs_nr_curl_options('https://api.eu.newrelic.com/graphql', 'NRAK-SECRET', '{"query":"{}"}');
check('the endpoint is used', 'https://api.eu.newrelic.com/graphql', $options[CURLOPT_URL]);
check('the API key is sent as a header', TRUE, in_array('API-Key: NRAK-SECRET', $options[CURLOPT_HTTPHEADER], TRUE));
check('the content type is JSON', TRUE, in_array('Content-Type: application/json', $options[CURLOPT_HTTPHEADER], TRUE));
check('the body is sent verbatim', '{"query":"{}"}', $options[CURLOPT_POSTFIELDS]);
check('it is a POST', TRUE, $options[CURLOPT_POST]);
check('the response is returned rather than printed', TRUE, $options[CURLOPT_RETURNTRANSFER]);
// Pinned to literals, not to the constants. Comparing a constant to itself
// would let a change from 15s to 600s pass, and these are the numbers that
// keep the hook inside Quicksilver's 120-second per-script ceiling.
check('the whole-request timeout is 15 seconds', 15, $options[CURLOPT_TIMEOUT]);
check('the connect timeout is 5 seconds', 5, $options[CURLOPT_CONNECTTIMEOUT]);
check('at most 3 search pages are walked', 3, QS_NR_MAX_SEARCH_PAGES);
check('the hook budget is 45 seconds', 45, QS_NR_TOTAL_BUDGET);
check(
  'the worst-case request time stays well inside the 120s Quicksilver ceiling',
  TRUE,
  (QS_NR_MAX_SEARCH_PAGES + 1) * QS_NR_HTTP_TIMEOUT <= 90
);
check(
  'the pagination budget cannot outlast the ceiling either',
  TRUE,
  QS_NR_TOTAL_BUDGET + QS_NR_HTTP_TIMEOUT <= 90
);

echo "\nresponse size is bounded\n";
// The cap is injected rather than taken from QS_NR_RESPONSE_LIMIT: allocating
// a buffer sized from the constant would crash the suite instead of failing it
// if anyone raised the constant.
$buffer = '';
$overflowed = FALSE;
$collector = qs_nr_response_collector($buffer, $overflowed, 8);
check('a normal chunk is accepted whole', 5, $collector(NULL, 'hello'));
check('and is buffered', 'hello', $buffer);
check('no overflow yet', FALSE, $overflowed);
check('a chunk past the cap aborts the transfer', 0, $collector(NULL, 'world'));
check('and the overflow is recorded', TRUE, $overflowed);
check('the buffer is not grown past the cap', 'hello', $buffer);
check('the production cap is a sane size', TRUE, QS_NR_RESPONSE_LIMIT === 1048576);
check(
  'the cap is declared to curl as well',
  QS_NR_RESPONSE_LIMIT,
  qs_nr_curl_options('https://api.newrelic.com/graphql', 'K', '{}')[CURLOPT_MAXFILESIZE]
);
check(
  'a writer replaces RETURNTRANSFER rather than joining it',
  FALSE,
  array_key_exists(CURLOPT_RETURNTRANSFER, qs_nr_curl_options('https://x/graphql', 'K', '{}', 'strlen'))
);

echo "\nuntrusted values cannot forge log lines\n";
check(
  'a newline in an entity GUID is neutralised',
  FALSE,
  strpos(qs_nr_printable("GUID\nDone. New Relic deployment ID 999999."), "\n") !== FALSE
);
check(
  'an escape sequence is neutralised',
  FALSE,
  strpos(qs_nr_printable("x\x1b[2Jcleared"), "\x1b") !== FALSE
);
foreach (['NEL' => "\u{0085}", 'LS' => "\u{2028}", 'PS' => "\u{2029}", 'RLO' => "\u{202E}"] as $label => $character) {
  check(
    'a ' . $label . ' character is neutralised',
    FALSE,
    strpos(qs_nr_printable('a' . $character . 'b'), $character) !== FALSE
  );
}
check(
  'invalid UTF-8 does not defeat sanitising',
  TRUE,
  is_string(qs_nr_printable("bad\xFF\xFEbytes\nforged"))
    && strpos(qs_nr_printable("bad\xFF\xFEbytes\nforged"), "\n") === FALSE
);
check('log output is bounded', TRUE, strlen(qs_nr_printable(str_repeat('a', 5000))) <= 200);
check('a non-scalar is described, not cast', '(array)', qs_nr_printable(['x']));

echo "\nGraphQL error text is sanitised and bounded\n";
$hostile_error = qs_nr_error_text([
  'message' => "boom\nDone. New Relic deployment ID 123456.\x1b]0;pwned\x07" . str_repeat('A', 5000),
]);
check('no newline survives', FALSE, strpos($hostile_error, "\n") !== FALSE);
check('no escape byte survives', FALSE, strpos($hostile_error, "\x1b") !== FALSE);
check('the text is bounded', TRUE, strlen($hostile_error) <= 500);

echo "\ntransport result interpretation\n";
// These decisions used to live inside the curl call, where no test could reach
// them: whether a failed request is reported at all, which error wins, and
// whether the body is decoded.
$good = qs_nr_interpret_response(TRUE, 200, '', FALSE, '{"data":{"x":1}}');
check('a good response decodes its body', ['x' => 1], $good['data']['data']);
check('and carries its status', 200, $good['status']);
check('and reports no error', '', $good['error']);

$failed = qs_nr_interpret_response(FALSE, 0, 'could not resolve host', FALSE, '');
check('a curl failure is reported, not swallowed', 'could not resolve host', $failed['error']);
check('and yields no data', NULL, $failed['data']);

$silent = qs_nr_interpret_response(FALSE, 0, '', FALSE, '');
check('a failure with no curl message still reports one', 'the request failed', $silent['error']);

$over = qs_nr_interpret_response(FALSE, 200, 'Failure writing output', TRUE, 'partial');
check_contains('overflow wins over curl\'s generic write error', 'was abandoned', $over['error']);
check('and the partial body is discarded', NULL, $over['data']);

$html = qs_nr_interpret_response(TRUE, 500, '', FALSE, '<html>nope</html>');
check('a non-JSON body decodes to NULL', NULL, $html['data']);
check('while keeping the real status', 500, $html['status']);
check(
  'a 500 is not laundered into a success',
  FALSE,
  qs_nr_problems($html) === []
);

echo "\nresponse cap boundary\n";
$at_limit = '';
$hit = FALSE;
$exact = qs_nr_response_collector($at_limit, $hit, 8);
check('a chunk exactly at the cap is accepted', 8, $exact(NULL, '12345678'));
check('and does not trip the overflow flag', FALSE, $hit);
check('one byte more is refused', 0, $exact(NULL, '9'));
check('and does trip it', TRUE, $hit);

echo "\nregion token is matched as a prefix\n";
// "eu" must anchor at the start: a token merely containing it is not EU.
check('a token containing but not starting with eu is US', 'US', qs_nr_region_name('xeu01'));
check('a token starting with eu is EU', 'EU', qs_nr_region_name('eu01'));
check('quotes and spaces around a license do not misroute it', 'https://api.eu.newrelic.com/graphql',
  qs_nr_graphql_endpoint("  'eu01xX6789abcdef0123456789abcdef01234567'  "));

echo "\nresponse classification\n";
check('a clean 200 has no problems', [], qs_nr_problems(['status' => 200, 'data' => ['data' => []], 'error' => '']));
check(
  'a transport error is reported',
  ['the request failed: could not resolve host'],
  qs_nr_problems(['status' => 0, 'data' => NULL, 'error' => 'could not resolve host'])
);

$unauthorized = qs_nr_problems(['status' => 401, 'data' => ['errors' => []], 'error' => '']);
check_contains('a 401 reports its status', 'HTTP 401', first($unauthorized));
check_contains('a 401 blames the key type', 'User* key', implode("\n", $unauthorized));

$forbidden = qs_nr_problems(['status' => 403, 'data' => ['errors' => []], 'error' => '']);
check_contains('a 403 reports its status', 'HTTP 403', implode("\n", $forbidden));
check(
  'a 403 does not assert an undocumented cause',
  FALSE,
  strpos(implode("\n", $forbidden), 'User* key') !== FALSE
);

$throttled = qs_nr_problems(['status' => 429, 'data' => ['errors' => []], 'error' => '']);
check_contains('a 429 mentions rate limiting', 'rate limiting', implode("\n", $throttled));

$html_500 = qs_nr_problems(['status' => 500, 'data' => NULL, 'error' => '']);
check_contains('an HTML 500 still reports its status', 'HTTP 500', first($html_500));
$html_401 = qs_nr_problems(['status' => 401, 'data' => NULL, 'error' => '']);
check_contains('an HTML 401 still reaches the key-type hint', 'User* key', implode("\n", $html_401));

$bad_key_200 = qs_nr_problems([
  'status' => 200,
  'data' => ['errors' => [['message' => 'Invalid API key', 'extensions' => ['error_code' => 'BAD_API_KEY']]]],
  'error' => '',
]);
check_contains('a 200 carrying BAD_API_KEY is caught', 'BAD_API_KEY', implode("\n", $bad_key_200));

$messageless = qs_nr_problems(['status' => 200, 'data' => ['errors' => [['extensions' => ['x' => 1]]]], 'error' => '']);
check(
  'an error with no message still prints something',
  TRUE,
  trim(str_replace('New Relic said:', '', (string) first($messageless))) !== ''
);
$scalar_error = qs_nr_problems(['status' => 200, 'data' => ['errors' => ['plain string error']], 'error' => '']);
check_contains('a scalar error is handled', 'plain string error', first($scalar_error));
$string_errors = qs_nr_problems(['status' => 200, 'data' => ['errors' => 'boom'], 'error' => '']);
check_contains('errors as a bare string is still reported', 'boom', first($string_errors));
$flood_errors = qs_nr_problems([
  'status' => 200,
  'data' => ['errors' => array_fill(0, 5000, ['message' => 'e'])],
  'error' => '',
]);
check('the error list is capped', TRUE, count($flood_errors) <= QS_NR_MAX_PROBLEMS_REPORTED + 1);
check_contains('and the remainder is counted', 'further errors', implode("\n", $flood_errors));
check(
  'a non-finite status does not emit a cast warning',
  TRUE,
  is_array(qs_nr_problems(['status' => NAN, 'data' => NULL, 'error' => '']))
);
check(
  'an error with invalid UTF-8 and no message still says something',
  TRUE,
  trim(str_replace('New Relic said:', '', (string) first(qs_nr_problems([
    'status' => 200,
    'data' => ['errors' => [['extensions' => ['detail' => "bad\xFFbyte"]]]],
    'error' => '',
  ])))) !== ''
);

echo "\nreporting\n";
$reported = capture(static function (): bool {
  return qs_nr_report(['status' => 401, 'data' => NULL, 'error' => ''], 'entity lookup');
});
check('report() returns FALSE on a problem', FALSE, $reported['return']);
check_contains('and names the stage', 'during the entity lookup', $reported['output']);
$clean = capture(static function (): bool {
  return qs_nr_report(['status' => 200, 'data' => ['data' => []], 'error' => ''], 'entity lookup');
});
check('report() returns TRUE when usable', TRUE, $clean['return']);
check('and stays silent', '', $clean['output']);

echo "\nend-to-end wiring\n";

/**
 * A transport stub that records calls and replays canned responses.
 */
function transport_stub(array $responses, array &$calls): callable {
  return static function (string $endpoint, string $api_key, array $payload) use ($responses, &$calls): array {
    $calls[] = ['endpoint' => $endpoint, 'api_key' => $api_key, 'payload' => $payload];
    $index = count($calls) - 1;

    return $responses[$index] ?? $responses[count($responses) - 1];
  };
}

/**
 * A NerdGraph entity search response.
 */
function search_response(array $entities, ?string $next_cursor = NULL): array {
  return [
    'status' => 200,
    'error' => '',
    'data' => ['data' => ['actor' => ['entitySearch' => [
      'count' => count($entities),
      'results' => ['nextCursor' => $next_cursor, 'entities' => $entities],
    ]]]],
  ];
}

/**
 * A changeTrackingCreateDeployment response.
 */
function marker_response(?string $id, array $errors = []): array {
  $data = ['data' => ['changeTrackingCreateDeployment' => $id === NULL ? NULL : [
    'deploymentId' => $id,
    'entityGuid' => 'guid-live',
    'version' => 'pantheon_live_23',
  ]]];
  if ($errors !== []) {
    $data['errors'] = $errors;
  }

  return ['status' => 200, 'error' => '', 'data' => $data];
}

// The git reader is stubbed too, so these tests never shell out to whatever
// directory the suite happens to be run from.
$config = [
  'api_key' => 'NRAK-TESTKEY',
  'app_name' => '511-org (live)',
  'endpoint' => 'https://api.eu.newrelic.com/graphql',
  'git' => git_stub(git_responses()),
];
$live_entities = [
  ['guid' => 'guid-lastlive', 'name' => '511-org (lastlive)'],
  ['guid' => 'guid-live', 'name' => '511-org (live)'],
];

$calls = [];
$success = capture(static function () use ($config, $live_entities, &$calls): bool {
  return qs_nr_run(
    ['wf_type' => 'sync_code', 'user_email' => 'dev@example.com'],
    transport_stub([search_response($live_entities), marker_response('dep-1')], $calls),
    $config
  );
});
check('the happy path returns TRUE', TRUE, $success['return']);
check('exactly two requests are made', 2, count($calls));
check('the configured endpoint is used', 'https://api.eu.newrelic.com/graphql', $calls[0]['endpoint']);
check('the API key is sent with the search', 'NRAK-TESTKEY', $calls[0]['api_key']);
check('the API key is sent with the mutation too', 'NRAK-TESTKEY', $calls[1]['api_key']);
check('the mutation goes to the same endpoint', 'https://api.eu.newrelic.com/graphql', $calls[1]['endpoint']);
check_contains('the first request is the entity search', 'entitySearch', $calls[0]['payload']['query']);
check_contains('the second request is the mutation', 'changeTrackingCreateDeployment', $calls[1]['payload']['query']);
check(
  'the mutation carries the exact-matched entity GUID',
  'guid-live',
  $calls[1]['payload']['variables']['deployment']['entityGuid']
);
check_contains('and reports the deployment ID', 'Done. New Relic deployment ID dep-1.', $success['output']);
// The single most important thing this hook must never do.
check(
  'the API key never reaches the workflow log',
  FALSE,
  strpos($success['output'], 'NRAK-TESTKEY') !== FALSE
);

echo "\nend-to-end: no exact match\n";
$calls = [];
$no_match = capture(static function () use ($config, &$calls): bool {
  return qs_nr_run(
    ['wf_type' => 'sync_code'],
    transport_stub([search_response([['guid' => 'g', 'name' => '511-org (lastlive)']])], $calls),
    $config
  );
});
check('it returns FALSE', FALSE, $no_match['return']);
check('no mutation is attempted', 1, count($calls));
check_contains('the candidate is named', '511-org (lastlive)', $no_match['output']);
check_contains('and the region is offered as an explanation', 'new_relic_region', $no_match['output']);

echo "\nend-to-end: pagination\n";
$calls = [];
$paged = capture(static function () use ($config, $live_entities, &$calls): bool {
  return qs_nr_run(
    ['wf_type' => 'sync_code'],
    transport_stub([
      search_response([['guid' => 'g1', 'name' => 'other-site (live)']], 'CURSOR-2'),
      search_response($live_entities),
      marker_response('dep-2'),
    ], $calls),
    $config
  );
});
check('a match on page two is found', TRUE, $paged['return']);
check('three requests are made', 3, count($calls));
check('the first page sends no cursor', NULL, $calls[0]['payload']['variables']['cursor']);
check('the second page sends the cursor', 'CURSOR-2', $calls[1]['payload']['variables']['cursor']);

echo "\nend-to-end: pagination is bounded\n";
$calls = [];
$endless = capture(static function () use ($config, &$calls): bool {
  return qs_nr_run(
    ['wf_type' => 'sync_code'],
    transport_stub([search_response([['guid' => 'g', 'name' => 'nope']], 'CURSOR-NEXT')], $calls),
    $config
  );
});
check('it gives up rather than looping forever', FALSE, $endless['return']);
check('the page cap is respected', QS_NR_MAX_SEARCH_PAGES, count($calls));
check_contains('and says it stopped early', 'Search stopped after', $endless['output']);

echo "\nend-to-end: the hook stops when its time budget is spent\n";
// A Quicksilver script that overruns gets its worker killed, and a killed
// worker marks the whole deploy workflow failed. So the budget must cut
// pagination short even when pages remain.
$calls = [];
$ticks = 0;
$slow = capture(static function () use ($config, &$calls, &$ticks): bool {
  return qs_nr_run(
    ['wf_type' => 'sync_code'],
    transport_stub([search_response([['guid' => 'g', 'name' => 'nope']], 'CURSOR-NEXT')], $calls),
    $config + [
      'clock' => static function () use (&$ticks): float {
        // First call sets the deadline; the next is already past it.
        $ticks++;

        return $ticks === 1 ? 0.0 : (float) (QS_NR_TOTAL_BUDGET + 1);
      },
    ]
  );
});
check('it gives up', FALSE, $slow['return']);
check('after a single search, not the full page allowance', 1, count($calls));
check_contains('and says why', 'to stay inside the Quicksilver time limit', $slow['output']);

echo "\nend-to-end: a found entity survives a partial GraphQL error\n";
// GraphQL returns partial data with a populated errors array for shard and
// field-level failures. The GUID was in hand; dropping the marker over a
// warning would lose a real deploy record.
$calls = [];
$partial_search = capture(static function () use ($config, $live_entities, &$calls): bool {
  $found = search_response($live_entities);
  $found['data']['errors'] = [['message' => 'one shard temporarily unavailable']];

  return qs_nr_run(
    ['wf_type' => 'sync_code'],
    transport_stub([$found, marker_response('dep-partial')], $calls),
    $config
  );
});
check('the marker is still created', TRUE, $partial_search['return']);
check('the mutation was reached', 2, count($calls));
check_contains('and the shard error is surfaced', 'one shard temporarily unavailable', $partial_search['output']);
check_contains('as a warning, not an error', 'WARNING: the application was found', $partial_search['output']);

echo "\nend-to-end: a non-2xx cannot report success\n";
foreach ([500, 401, 403] as $status) {
  $calls = [];
  $bad_status = capture(static function () use ($config, $live_entities, $status, &$calls): bool {
    return qs_nr_run(
      ['wf_type' => 'sync_code'],
      transport_stub([
        search_response($live_entities),
        ['status' => $status, 'error' => '', 'data' => ['data' => [
          'changeTrackingCreateDeployment' => ['deploymentId' => 'dep-should-not-count'],
        ]]],
      ], $calls),
      $config
    );
  });
  check('HTTP ' . $status . ' with a deploymentId is not a success', FALSE, $bad_status['return']);
  check(
    'and no Done line is printed for HTTP ' . $status,
    FALSE,
    strpos($bad_status['output'], 'Done. New Relic deployment ID') !== FALSE
  );
}

echo "\nthe environment comes from the workflow payload\n";
check('the payload wins', 'live', qs_nr_environment(['environment' => 'live']));
check('an array value is ignored', '', qs_nr_environment(['environment' => ['live']]));
check('an absent value is empty off-platform', '', qs_nr_environment([]));

echo "\nend-to-end: partial success\n";
// With no dataHandlingRules sent, New Relic documents that a legacy REST
// failure behind an APM entity is reported without blocking the save.
$calls = [];
$partial = capture(static function () use ($config, $live_entities, &$calls): bool {
  return qs_nr_run(
    ['wf_type' => 'sync_code'],
    transport_stub([
      search_response($live_entities),
      marker_response('dep-3', [['message' => 'legacy REST call failed']]),
    ], $calls),
    $config
  );
});
check('a created marker is reported as success', TRUE, $partial['return']);
check_contains('the deployment ID is still reported', 'deployment ID dep-3', $partial['output']);
check_contains('and the error is surfaced as a warning', 'WARNING', $partial['output']);
check_contains('naming the underlying problem', 'legacy REST call failed', $partial['output']);

echo "\nend-to-end: a hostile deploymentId is not reported as success\n";
foreach ([TRUE, 1.5, ['x'], []] as $index => $hostile_id) {
  $calls = [];
  $bogus = capture(static function () use ($config, $live_entities, $hostile_id, &$calls): bool {
    return qs_nr_run(
      ['wf_type' => 'sync_code'],
      transport_stub([
        search_response($live_entities),
        ['status' => 200, 'error' => '', 'data' => ['data' => [
          'changeTrackingCreateDeployment' => ['deploymentId' => $hostile_id],
        ]]],
      ], $calls),
      $config
    );
  });
  check('a ' . gettype($hostile_id) . ' deploymentId is refused', FALSE, $bogus['return']);
  check(
    'and no success line is printed for it',
    FALSE,
    strpos($bogus['output'], 'Done. New Relic deployment ID') !== FALSE
  );
}
$calls = [];
$integer_id = capture(static function () use ($config, $live_entities, &$calls): bool {
  return qs_nr_run(
    ['wf_type' => 'sync_code'],
    transport_stub([
      search_response($live_entities),
      ['status' => 200, 'error' => '', 'data' => ['data' => [
        'changeTrackingCreateDeployment' => ['deploymentId' => 12345],
      ]]],
    ], $calls),
    $config
  );
});
check('an integer deploymentId is still accepted', TRUE, $integer_id['return']);

echo "\nend-to-end: candidate logging is bounded\n";
$many = [];
for ($i = 0; $i < 500; $i++) {
  $many[] = ['guid' => 'g' . $i, 'name' => 'other-site-' . $i . ' (live)'];
}
$calls = [];
$flood = capture(static function () use ($config, $many, &$calls): bool {
  return qs_nr_run(
    ['wf_type' => 'sync_code'],
    transport_stub([search_response($many)], $calls),
    $config
  );
});
check('it returns FALSE', FALSE, $flood['return']);
check(
  'the candidate list is capped',
  TRUE,
  substr_count($flood['output'], 'candidate seen:') <= QS_NR_MAX_CANDIDATES_LOGGED
);
check_contains('and the remainder is acknowledged', 'and more not shown', $flood['output']);
// The names are collected lazily as well as printed lazily: a cap-sized
// response of empty objects decodes to hundreds of thousands of arrays, so
// accumulating every name across every page would amplify the byte cap into
// an out-of-memory fatal.
check(
  'candidate collection is bounded, not just candidate printing',
  TRUE,
  substr_count($flood['output'], 'candidate seen:') === QS_NR_MAX_CANDIDATES_LOGGED
);

echo "\nend-to-end: errors with no deployment\n";
$calls = [];
$failed = capture(static function () use ($config, $live_entities, &$calls): bool {
  return qs_nr_run(
    ['wf_type' => 'sync_code'],
    transport_stub([
      search_response($live_entities),
      marker_response(NULL, [['message' => 'entity not found']]),
    ], $calls),
    $config
  );
});
check('it returns FALSE', FALSE, $failed['return']);
check_contains('the error is reported', 'entity not found', $failed['output']);
check(
  'and the vaguer fallback message is not also printed',
  FALSE,
  strpos($failed['output'], 'returned no deployment') !== FALSE
);

echo "\nend-to-end: a thrown exception never escapes\n";
$thrown = capture(static function (): bool {
  return qs_nr_main(
    ['wf_type' => 'sync_code'],
    static function (): array {
      throw new \RuntimeException('secrets backend unavailable');
    },
    [
      'api_key' => 'NRAK-TESTKEY',
      'app_name' => 'x',
      'endpoint' => 'https://api.newrelic.com/graphql',
      'git' => git_stub([]),
    ]
  );
});
check('qs_nr_main returns FALSE instead of propagating', FALSE, $thrown['return']);
check_contains('the message is logged as prose', 'failed unexpectedly', $thrown['output']);
check_contains('naming the cause', 'secrets backend unavailable', $thrown['output']);
check_contains('and the exception class', 'RuntimeException', $thrown['output']);

echo "\ndeploy attribution\n";
check(
  'the workflow user wins over the commit author',
  'release@example.com',
  qs_nr_deploying_user(
    ['wf_type' => 'deploy', 'user_email' => 'release@example.com', 'user_role' => 'super'],
    git_stub(git_responses())
  )
);
check(
  'a promotion never falls back to the build-artifact author',
  '',
  qs_nr_deploying_user(
    ['wf_type' => 'deploy', 'user_role' => 'super'],
    git_stub(['log --pretty=format:%ae -1' => 'bot@getpantheon.com'])
  )
);
check(
  'a dashboard code sync still falls back to the commit author',
  'dashboard-user@example.com',
  qs_nr_deploying_user(
    ['wf_type' => 'sync_code', 'user_role' => 'super'],
    git_stub(git_responses())
  )
);

echo "\ngit output is bounded before it reaches PHP\n";
$command = qs_nr_git_command('log --pretty=format:%s -1');
check_contains('the read is capped in the shell', 'head -c ' . QS_NR_GIT_OUTPUT_LIMIT, $command);
check_contains('stderr is discarded', '2>/dev/null', $command);
check('the cap is small enough to be safe', TRUE, QS_NR_GIT_OUTPUT_LIMIT <= 1_048_576);
check(
  'the arguments are passed through unchanged',
  0,
  strpos($command, 'git log --pretty=format:%s -1 ')
);

echo "\nresponse walking\n";
check('follows a path', 'found', qs_nr_dig(['a' => ['b' => 'found']], ['a', 'b']));
check('missing key returns NULL', NULL, qs_nr_dig(['a' => ['b' => 'found']], ['a', 'c']));
check('scalar mid-path returns NULL', NULL, qs_nr_dig(['a' => 'scalar'], ['a', 'b']));
check('preserves a NULL value', NULL, qs_nr_dig(['a' => NULL], ['a']));
check('a non-array input returns NULL', NULL, qs_nr_dig('string', ['a']));

// Pin the total. Without this, anything that aborts the run early -- an
// uncaught TypeError from a regression, say -- exits non-zero with zero
// reported failures, and a truncated run reads as a healthy one.
$expected_assertions = 294;
if ($assertions !== $expected_assertions) {
  $failures++;
  echo "  FAIL  the whole suite ran\n";
  echo "        expected: " . $expected_assertions . " assertions\n";
  echo "        actual:   " . $assertions . "\n";
  echo "        If this change is intended, update \$expected_assertions.\n";
}

echo "\n" . ($failures === 0
  ? $assertions . " assertions passed.\n\n"
  : $failures . " of " . $assertions . " assertions FAILED.\n\n");

exit($failures === 0 ? 0 : 1);
