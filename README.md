# Rybbit tracking script, as patched by Gelhaus Solutions

This is the source of the visit-counting script served as
`/assets/js/analytics.js` on ennogelhaus.de and gplatform.org, published under
section 13 of the GNU Affero General Public License v3.

## Upstream

The script is the tracking script of [Rybbit](https://github.com/rybbit-io/rybbit),
copyright the Rybbit authors, licensed under the GNU Affero General Public
License v3.0. We run Rybbit ourselves; the copy here was fetched from our own
instance (`https://web-anal.egelhaus.de/api/script.js`) on 7 September 2026.
The header of `analytics.js` records the hash of the upstream file it was made
from.

## What we changed

Four edits, nothing else:

1. **The visitor id is never stored or read.** On every page load the upstream
   script writes a persistent random visitor id to the browser's
   `localStorage`. Storing that on a visitor's device needs consent under
   Section 25(1) TDDDG, and our sites ask for none. The id is now made in memory
   for the page view and never written to or read from the device. It only ever
   fed the feature-flag call, which is off for our sites; pageviews, visitors,
   sessions and referrers are counted as before, because Rybbit works out
   visitors and sessions on the server.
2. **A stored user id is never read**, in either of the two places the upstream
   script reads one. Our sites never identify a visitor.
3. **The same, on the tracker object** (`loadUserId`).
4. **The endpoint is taken from `data-host`.** The upstream script finds its
   API by cutting its own address at `/script.js`; served from our own origin as
   `analytics.js`, it is told the address instead.

`analytics-script.php` fetches the upstream script, applies that edit by the
shape of the code rather than by its minified names, refuses to write anything
if the shape is not found exactly once, and writes `analytics.js`. Its header
explains the reasoning in full.

## Files

- `analytics.js`: the script as served, patched.
- `analytics-script.php`: the tool that produces it from the upstream script.
- `LICENSE`: the GNU Affero General Public License v3.0, which covers both.

Published by Gelhaus Solutions, Eichenwald 3, 49624 Löningen, Germany.
