<?php

declare(strict_types=1);

/**
 * Fetch the Rybbit tracking script, strip its device storage, and vendor the
 * result into html/assets/js/analytics.js.
 *
 * Usage:
 *   php /var/www/app/bin/analytics-script.php           write the vendored copy
 *   php /var/www/app/bin/analytics-script.php --check   report drift, write nothing
 *
 * WHY THIS FILE EXISTS AT ALL
 *
 * The upstream script is served from the Rybbit instance and would normally be
 * loaded straight from it. It is vendored instead, patched, because of one
 * thing it does on every page load, before it fetches any configuration and
 * with no attribute or dashboard setting to prevent it:
 *
 *     function ze(n){let e=`${n}-visitor-id`;
 *       try{let t=localStorage.getItem(e);if(t)return t;
 *           let r=ue();return localStorage.setItem(e,r),r}catch{return ue()}}
 *
 * A persistent UUID written to the visitor's device. Sec. 25 Abs. 1 TDDDG is
 * technology-neutral: storing that is the same act as setting a cookie, and
 * analytics does not reach the "strictly necessary" exception in Abs. 2. So an
 * unpatched script needs a consent banner, and /datenschutz on both sites says
 * -- correctly, and as a description of how the site is built rather than as a
 * promise -- that there is nothing here to consent to.
 *
 * Removing it costs nothing. The id is traceable through the whole file and
 * reaches exactly two places: the config object, and the body of
 * POST /site/:id/feature-flags/evaluate as `anonymousId`. It is NOT in
 * createBasePayload(), so it never reaches /track, and the feature-flag call is
 * gated on `featureFlagsEnabled === true`, which is off unless flags are
 * enabled for the site. Visitor and session identity are computed server-side
 * from IP and user agent -- that is what the `_bs`/`_bsm` bot-score fields
 * beside it are for. Pageviews, visitors, sessions and referrers are unchanged.
 *
 * The Rybbit instance itself is deliberately NOT patched: it serves other
 * things, and a `docker pull` would silently restore the original under them.
 * The patch lives here, on the side that publishes the pages that make the
 * claim.
 *
 * WHY IT REFUSES INSTEAD OF GUESSING
 *
 * The upstream is minified and its symbol names (`ze`, `je`, `ue`) change on
 * every build, so the patterns below match on the shape of the code rather
 * than on the names. Each must match exactly once. Zero matches means upstream
 * moved and the strip would silently not happen; more than one means the shape
 * is no longer unique and the edit is not the one that was reviewed. Either
 * way this script writes nothing and exits non-zero, which is the same bargain
 * bin/build.php makes with the CSP hash: a check that fails loudly is worth
 * more than a copy that is quietly wrong.
 */

if (PHP_SAPI !== 'cli') {
    exit("This script is for the command line only.\n");
}

require __DIR__ . '/../src/bootstrap.php';

$check  = in_array('--check', $argv, true);
$source = (string) App::config('analytics.source', '');
$target = App::WEBROOT . '/assets/js/analytics.js';

if ($source === '') {
    fwrite(STDERR, "analytics: analytics.source is empty in config.php; nothing to fetch.\n");
    exit(2);
}

/**
 * The edits, in the order they are applied.
 *
 * `find` is matched against the whole file and must hit exactly once.
 * `replace` may use $1..$n from it.
 */
$patches = [
    [
        'what' => 'visitor id: never stored, never read',
        // function ze(n){let e=`${n}-visitor-id`;try{...}catch{return ue()}}
        'find' => '/function (\w+)\(\w+\)\{let \w+=`\$\{\w+\}-visitor-id`;'
            . 'try\{let \w+=localStorage\.getItem\(\w+\);if\(\w+\)return \w+;'
            . 'let \w+=(\w+)\(\);return localStorage\.setItem\(\w+,\w+\),\w+\}'
            . 'catch\{return \2\(\)\}\}/',
        // Still hands back a well-formed id for the (disabled) feature-flag
        // path; it is simply new on every page load and never leaves memory.
        'replace' => 'function $1(n){return $2()}',
    ],
    [
        'what' => 'identified user id: never read',
        // function je(n){try{return localStorage.getItem(`${n}-user-id`)||void 0}catch{return}}
        'find' => '/function (\w+)\(\w+\)\{try\{return localStorage\.getItem\('
            . '`\$\{\w+\}-user-id`\)\|\|void 0\}catch\{return\}\}/',
        'replace' => 'function $1(n){return}',
    ],
    [
        'what' => 'identified user id: never read on the tracker',
        // loadUserId(){try{let e=localStorage.getItem(...);e&&(this.customUserId=e)}catch{}}
        'find' => '/loadUserId\(\)\{try\{let (\w+)=localStorage\.getItem\('
            . '`\$\{this\.config\.namespace\}-user-id`\);'
            . '\1&&\(this\.customUserId=\1\)\}catch\{\}\}/',
        'replace' => 'loadUserId(){}',
    ],
    [
        'what' => 'endpoint base from data-host',
        // The script derives the API base by splitting its own src on
        // "/script.js". Served from our own origin as analytics.js that yields
        // the asset path, so the base is passed in explicitly instead.
        'find' => '/(async function \w+\((\w+)\)\{let (\w+)=\2\.getAttribute\("src"\);'
            . 'if\(!\3\)return console\.error\("Script src attribute is missing"\),null;'
            . 'let \w+=)\3\.split\("\/script\.js"\)\[0\];/',
        'replace' => '$1$2.getAttribute("data-host")||$3.split("/script.js")[0];',
    ],
];

// ---------------------------------------------------------------------------
// Fetch
// ---------------------------------------------------------------------------

$context = stream_context_create(['http' => [
    'timeout'       => 15,
    'ignore_errors' => true,
    'header'        => "Accept: application/javascript\r\n",
]]);

$upstream = @file_get_contents($source, false, $context);
$status   = 0;

foreach ($http_response_header ?? [] as $line) {
    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
        $status = (int) $m[1];
    }
}

if ($upstream === false || $status !== 200 || trim($upstream) === '') {
    fwrite(STDERR, "analytics: could not fetch {$source} (HTTP {$status}).\n");
    fwrite(STDERR, "           the vendored copy is left alone.\n");
    exit(2);
}

// ---------------------------------------------------------------------------
// Patch
// ---------------------------------------------------------------------------

$patched = $upstream;
$failed  = [];

foreach ($patches as $patch) {
    $count   = 0;
    $result  = preg_replace($patch['find'], $patch['replace'], $patched, -1, $count);

    if ($result === null || $count !== 1) {
        $failed[] = $patch['what'] . ' (' . $count . ' matches, expected 1)';
        continue;
    }

    $patched = $result;
}

if ($failed !== []) {
    fwrite(STDERR, "analytics: the upstream script changed shape and these edits no longer apply:\n");
    foreach ($failed as $line) {
        fwrite(STDERR, '         ' . $line . "\n");
    }
    fwrite(STDERR, "       refusing to vendor an unpatched or half-patched script.\n");
    fwrite(STDERR, "       re-read the storage handling upstream, then update the patterns here.\n");
    exit(2);
}

// Belt and braces. The three edits above are the ones that fire on a page
// view; what is left has to be storage that only a call we never make can
// reach, and this is the assertion that says so rather than the comment.
//
// Allowed, in order: the session-replay sampling flag (replay is off), the
// identify()/clearUserId() pair (never called), and the `disable-<namespace>`
// opt-out read -- which is kept deliberately, because it is a read in the
// visitor's favour and is the one thing here that is strictly necessary.
$allowed = [
    'sessionStorage.getItem(',
    'sessionStorage.setItem(',
    'localStorage.setItem(`${this.config.namespace}-user-id`',
    'localStorage.removeItem(`${this.config.namespace}-user-id`)',
    'localStorage.getItem(t)!==null',
];

$residual = $patched;
foreach ($allowed as $known) {
    $residual = str_replace($known, '', $residual);
}

if (preg_match_all('/(?:local|session)Storage\s*\.\s*(?:get|set|remove)Item/', $residual, $m) > 0) {
    fwrite(STDERR, "analytics: unrecognised device storage remains after patching:\n");
    foreach (array_unique($m[0]) as $hit) {
        fwrite(STDERR, '         ' . $hit . "\n");
    }
    fwrite(STDERR, "       refusing to vendor it. Read the new call and decide before shipping.\n");
    exit(2);
}

// ---------------------------------------------------------------------------
// Write
// ---------------------------------------------------------------------------

/* Provenance, so nobody reads a 32 KB minified blob in the assets directory
   and wonders where it came from or whether it may be edited. */
$header = "/* Rybbit tracking script, vendored and patched. DO NOT EDIT.\n"
    . " *\n"
    . ' * Source:    ' . $source . "\n"
    . ' * Fetched:   ' . date('Y-m-d H:i:s T') . "\n"
    . ' * Upstream:  sha256-' . base64_encode(hash('sha256', $upstream, true)) . "\n"
    . " *\n"
    . " * Regenerate with app/bin/analytics-script.php, which strips the\n"
    . " * localStorage visitor id (Sec. 25 TDDDG) and refuses if the upstream\n"
    . " * has moved. The reasoning is in the header of that file.\n"
    . " *\n"
    . " * Published source (AGPL-3.0, section 13):\n"
    . " * https://github.com/Gelhaus-Solutions/rybbit-script\n"
    . " */\n";

$output   = $header . $patched;
$existing = is_file($target) ? (string) file_get_contents($target) : '';

/* Compared without the header: it carries a fetch timestamp, so including it
   would report drift on every run and never report none. */
$strip  = static fn (string $s): string => (string) preg_replace('#^/\*.*?\*/\n#s', '', $s);
$same   = $strip($existing) === $strip($output);

if ($check) {
    if ($existing === '') {
        echo "analytics: nothing vendored yet at " . $target . "\n";
        exit(1);
    }

    echo $same
        ? "analytics: vendored copy matches the patched upstream\n"
        : "analytics: DRIFT -- upstream has changed since the vendored copy was written\n";

    exit($same ? 0 : 1);
}

if ($same) {
    /* Leave the mtime alone. asset() versions the URL by it, so rewriting an
       identical file would expire it from every cache for nothing. */
    echo "analytics: unchanged, left alone (" . strlen($output) . " bytes)\n";
    exit(0);
}

if (file_put_contents($target, $output) === false) {
    fwrite(STDERR, "analytics: could not write {$target}\n");
    exit(2);
}

@chmod($target, 0644);

printf(
    "analytics: wrote %s (%d bytes upstream, %d after patching)\n",
    $target,
    strlen($upstream),
    strlen($patched)
);
