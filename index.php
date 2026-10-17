<?php
// "This site has moved" page. Deployed into the web root of the OLD site
// (with every request rewritten to this file), it:
//   - tells visitors where the site went (config.json: siteName, oldUrl, newUrl),
//   - answers the svision version check (POST version.db) with a version that
//     never matches, so installed apps offer their UPGRADE and land here,
//   - serves a self-destroying service worker on the old worker's URL, so an
//     installed app that never asks for a version is cleaned up too,
//   - removes service workers, caches, cookies, storage and IndexedDB of the
//     old site from the visitor's browser and reports what it found.

$configFile = __DIR__ . '/config.json';
$config = is_file($configFile) ? json_decode((string)file_get_contents($configFile), true) : null;
if (!is_array($config)) {
  http_response_code(500);
  header('Content-Type: text/plain; charset=UTF-8');
  echo "config.json is missing or invalid — copy config.sample.json to config.json and edit it.\n";
  exit;
}
$config += [
  'siteName' => '',
  'oldUrl' => '',
  'newUrl' => '',
  'version' => '#moved',
  'redirectSeconds' => 0,
  'keepPath' => false,
  'clearSiteData' => true,
];
$newScheme = strtolower((string)parse_url((string)$config['newUrl'], PHP_URL_SCHEME));
if (!in_array($newScheme, ['http', 'https'], true)) {
  http_response_code(500);
  header('Content-Type: text/plain; charset=UTF-8');
  echo "config.json: newUrl must be an absolute http(s) URL.\n";
  exit;
}

$isHttps = (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
// the old site's front controller got the path as the query string
// (nginx/Apache rewrite "/<path>" → "index.php?<path>"), so the endpoint is the
// first query argument; basename() so an app served from a subdirectory matches too
$endpoint = explode('&', (string)($_SERVER['QUERY_STRING'] ?? ''), 2)[0];
$endpointName = basename($endpoint);

// ---------- service worker kill switch ----------
// Browsers re-fetch the registered worker script on every navigation into its
// scope (sending "Service-Worker: script"). Any byte change installs the new
// script, so the old svision worker is replaced by this one: it activates at
// once, deletes the caches, unregisters itself and reloads the open tabs —
// which then reach this page from the network.
if (($_SERVER['HTTP_SERVICE_WORKER'] ?? '') === 'script'
    || preg_match('/^(serviceWorker|service-worker|sw)(\.m?js)?$/i', $endpointName)) {
  header('Content-Type: application/javascript; charset=UTF-8');
  header('Cache-Control: no-cache, no-store');
  echo "// site moved (version " . json_encode((string)$config['version']) . "): remove this service worker\n";
  echo <<<'JS'
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    await self.clients.claim();
    const names = await caches.keys();
    await Promise.all(names.map((name) => caches.delete(name)));
    await self.registration.unregister();
    const windows = await self.clients.matchAll({ type: 'window' });
    windows.forEach((client) => client.navigate(client.url).catch(() => {}));
  })());
});

JS;
  exit;
}

// ---------- svision data endpoints ----------
// Same response shape as svision's DbPage: {source, data, fetchDataId}. The
// version never equals any real app version, so the app shows "UPGRADE !" and
// its upgradeApp() unregisters the worker and reloads into this page. Every
// other data request gets an error instead of stale-looking data.
if ($endpointName === 'version.db'
    || ($method === 'POST' && preg_match('/\.(db|data|post)$/', $endpointName))) {
  header('Content-Type: application/json; charset=UTF-8');
  header('Cache-Control: no-store');
  header('X-Robots-Tag: noindex');
  if (!isset($_SERVER['HTTP_FETCHDATAID']) && $method === 'POST') {
    $response = ['error' => ['message' => 'unknown fetchDataId']];
  } elseif ($endpointName === 'version.db') {
    $response = ['source' => 'server', 'data' => ['version' => (string)$config['version']]];
  } else {
    $response = ['error' => ['message' => 'moved', 'newUrl' => (string)$config['newUrl']]];
  }
  if (isset($_SERVER['HTTP_FETCHDATAID'])) {
    $response['fetchDataId'] = $_SERVER['HTTP_FETCHDATAID'];
  }
  echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

// ---------- Clear-Site-Data ----------
// Fetched by the page's script once it has reported what it found. The header
// makes the browser drop everything the origin stored — cookies including
// HttpOnly ones, storage, service workers and the HTTP cache. Browsers honour
// it over HTTPS only; "cookies" covers the whole registrable domain.
if ($endpointName === 'moved-cleanup') {
  header('Content-Type: application/json; charset=UTF-8');
  header('Cache-Control: no-store');
  header('X-Robots-Tag: noindex');
  $sent = $config['clearSiteData'] && $isHttps;
  if ($sent) {
    header('Clear-Site-Data: "cache", "cookies", "storage"');
  }
  echo json_encode(['clearSiteData' => $sent]);
  exit;
}

// ---------- robots.txt ----------
// crawlers must be allowed in, otherwise they never see the noindex below
if ($endpointName === 'robots.txt') {
  header('Content-Type: text/plain; charset=UTF-8');
  echo "User-agent: *\nAllow: /\n";
  exit;
}

// ---------- the page ----------
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');                 // never let this page outlive the move
header('X-Robots-Tag: noindex, follow');           // don't index the notice, do follow the new link

// expire every cookie the browser sent, HttpOnly ones included (the page's
// script can't see those) — for each path prefix of this URL and each domain
// level, since a cookie only goes away with the same path and domain it was set with
$host = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
$cookieDomains = [''];
if (preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $host) && !filter_var($host, FILTER_VALIDATE_IP)) {
  $labels = explode('.', $host);
  for ($i = 0; $i < count($labels) - 1; $i++) {
    $cookieDomains[] = implode('.', array_slice($labels, $i));
  }
}
$cookiePaths = ['/'];
$prefix = '';
$segments = array_filter(explode('/', (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH)), 'strlen');
foreach (array_slice($segments, 0, 5) as $segment) {
  if (preg_match('/[;,\s]/', $segment)) break;
  $prefix .= '/' . $segment;
  $cookiePaths[] = $prefix;
  $cookiePaths[] = $prefix . '/';
}
$serverCookies = [];
foreach (explode(';', (string)($_SERVER['HTTP_COOKIE'] ?? '')) as $pair) {
  $name = trim(explode('=', $pair, 2)[0]);
  // cookie-name is an RFC 6265 token — anything else is skipped (and can't inject headers)
  if ($name !== '' && preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name)) {
    $serverCookies[$name] = true;
  }
}
$serverCookies = array_slice(array_keys($serverCookies), 0, 50);
foreach ($serverCookies as $name) {
  foreach ($cookiePaths as $path) {
    foreach ($cookieDomains as $domain) {
      header('Set-Cookie: ' . $name . '=; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Max-Age=0; Path=' . $path
        . ($domain !== '' ? '; Domain=' . $domain : '') . ($isHttps ? '; Secure' : ''), false);
    }
  }
}

$t = [
  'title' => '{site} has moved',
  'moved' => 'This site has moved to a new address.',
  'go' => 'Go to the new address',
  'bookmarks' => 'Please update your bookmarks.',
  'reinstall' => 'Installed the app on your phone or computer? Uninstall it and install it again from the new address — the installed app still points to the old one.',
  'countdown' => 'Redirecting in {n} s…',
  'stay' => 'Stay here',
  'cleanupTitle' => 'Cleaning up after the old site',
  'noscript' => 'Turn on JavaScript to remove the old site’s offline app from this browser. Cookies have already been deleted.',
  'scene' => 'A removal truck carries boxes from the old house to the new one',
  'labelApp' => 'Installed app',
  'labelSw' => 'Service worker',
  'labelCaches' => 'Offline cache',
  'labelCookies' => 'Cookies',
  'labelStorage' => 'Local storage',
  'labelIdb' => 'IndexedDB',
  'labelSiteData' => 'Clear-Site-Data',
  'working' => 'checking…',
  'none' => 'nothing found',
  'unsupported' => 'not supported by this browser',
  'failed' => 'failed: {error}',
  'appInstalled' => 'you opened an installed app — please uninstall it and install it again from the new address',
  'swRemoved' => 'removed: {n}',
  'cachesDeleted' => 'deleted: {n}',
  'cookiesDeleted' => 'deleted: {names}',
  'cookiesLeft' => 'could not delete: {names}',
  'storageCleared' => 'items deleted: {n}',
  'idbDeleted' => 'databases deleted: {n}',
  'idbUnlisted' => 'this browser can’t list databases',
  'siteDataSent' => 'the browser was asked to wipe all data of this site',
  'siteDataHttps' => 'works over HTTPS only',
  'summaryClean' => 'Nothing of the old site is left in this browser.',
  'summaryWarn' => 'The cleanup finished with warnings — see above.',
];

// link target: the new URL, optionally with the visited path and query appended;
// the path is taken relative to the directory this page is installed in, so the
// old subdirectory is not carried over — the new one, if any, is part of newUrl
$target = (string)$config['newUrl'];
if ($config['keepPath']) {
  $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
  $baseDir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
  $afterBase = substr($requestUri, strlen($baseDir), 1);
  if ($baseDir !== '' && strncmp($requestUri, $baseDir, strlen($baseDir)) === 0
      && ($afterBase === '' || $afterBase === false || $afterBase === '/' || $afterBase === '?')) {
    $requestUri = substr($requestUri, strlen($baseDir));
  }
  $requestUri = preg_replace('#^/index\.php#', '', $requestUri);
  if ($requestUri !== '' && $requestUri !== '/') {
    $target = rtrim($target, '/') . '/' . ltrim($requestUri, '/');
  }
}
$siteName = (string)$config['siteName'] !== '' ? (string)$config['siteName'] : $host;
$oldUrl = (string)$config['oldUrl'] !== '' ? (string)$config['oldUrl'] : $host;
$shortUrl = fn($url) => rtrim(preg_replace('#^https?://#i', '', $url), '/');
$h = fn($text) => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$pageConfig = [
  'target' => $target,
  'redirectSeconds' => max(0, (int)$config['redirectSeconds']),
  'clearSiteData' => (bool)$config['clearSiteData'],
  'cleanupUrl' => (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php') . '?moved-cleanup',
  'serverCookies' => $serverCookies,
  'texts' => $t,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, follow">
<title><?= $h(str_replace('{site}', $siteName, $t['title'])) ?></title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ctext y='.9em' font-size='90'%3E%F0%9F%9A%9A%3C/text%3E%3C/svg%3E">
<style>
  :root {
    --sky-top: #1b2a4a;
    --sky-bottom: #3e5c8f;
    --accent: #ffc93c;
    --ok: #7ee2a8;
    --warn: #ffc93c;
    --error: #ff8a7a;
  }
  * { margin: 0; padding: 0; box-sizing: border-box; }
  html { height: 100%; background: var(--sky-bottom); }
  body {
    min-height: 100%;
    font-family: "Segoe UI", system-ui, -apple-system, sans-serif;
    background: linear-gradient(180deg, var(--sky-top) 0%, var(--sky-bottom) 100%);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    overflow-x: hidden; /* vertical scroll stays available for the cleanup report on small screens */
    color: #fff;
    padding: 1rem 0;
  }
  main {
    width: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
  }
  .scene {
    display: flex;
    justify-content: center;
    width: 100%;
  }
  /* scene width limited by window width, 900px, and available height
     (aspect ratio 1200:620; ~390px reserved for the texts, button, notice and report) */
  svg {
    display: block;
    width: min(92vw, 900px, max(280px, calc((100vh - 390px) * 1200 / 620)));
    height: auto;
    aspect-ratio: 1200 / 620;
  }

  h1 {
    margin-top: 1rem;
    font-size: clamp(1.4rem, 4vw, 2.6rem);
    letter-spacing: 0.2em;
    text-indent: 0.2em;
    font-weight: 800;
    text-transform: uppercase;
    text-align: center;
    padding: 0 0.5rem;
  }
  p.sub {
    margin-top: 0.5rem;
    font-size: clamp(0.9rem, 2.2vw, 1.15rem);
    opacity: 0.85;
    letter-spacing: 0.05em;
    text-align: center;
    padding: 0 1rem;
  }
  p.address {
    margin-top: 0.8rem;
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    align-items: center;
    gap: 0.3rem 0.7rem;
    padding: 0 1rem;
    font-size: clamp(0.9rem, 2.2vw, 1.1rem);
    text-align: center;
    overflow-wrap: anywhere;
  }
  .address .old { opacity: 0.55; text-decoration: line-through; }
  .address .arrow { color: var(--accent); font-weight: 700; }
  .address a { color: var(--accent); font-weight: 700; text-decoration: none; }
  .address a:hover { text-decoration: underline; }

  .actions {
    margin-top: 1.1rem;
    display: flex;
    justify-content: center;
    padding: 0 1rem;
  }
  .go-btn {
    font-weight: 700;
    letter-spacing: 0.06em;
    color: #1b2a4a;
    background: var(--accent);
    border-radius: 999px;
    padding: 0.7rem 1.6rem;
    text-decoration: none;
    text-align: center;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.35);
  }
  .go-btn:hover { transform: translateY(-2px); box-shadow: 0 7px 18px rgba(0, 0, 0, 0.45); }
  .go-btn:active { transform: translateY(0); }
  p.hint, p.countdown {
    margin-top: 0.6rem;
    font-size: 0.85rem;
    opacity: 0.7;
    text-align: center;
    padding: 0 1rem;
  }
  p.countdown { opacity: 0.9; }
  p.notice {
    margin-top: 0.8rem;
    width: min(92vw, 560px);
    padding: 0.55rem 0.9rem;
    border-left: 3px solid var(--accent);
    border-radius: 6px;
    background: rgba(10, 18, 36, 0.35);
    font-size: 0.85rem;
    line-height: 1.4;
    text-align: center;
  }
  p.countdown button {
    font: inherit;
    color: var(--accent);
    background: none;
    border: none;
    text-decoration: underline;
    cursor: pointer;
    margin-left: 0.5rem;
  }
  [hidden] { display: none !important; }

  /* cleanup report */
  .cleanup {
    margin-top: 1.2rem;
    width: min(92vw, 560px);
    background: rgba(10, 18, 36, 0.35);
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 12px;
    padding: 0.8rem 1rem;
    font-size: 0.82rem;
  }
  .cleanup h2 {
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    opacity: 0.7;
    margin-bottom: 0.4rem;
  }
  .cleanup ul { list-style: none; }
  .cleanup li {
    display: flex;
    gap: 0.6rem;
    padding: 0.18rem 0;
    line-height: 1.35;
  }
  .cleanup li .mark { flex: 0 0 1.1em; text-align: center; font-weight: 700; }
  .cleanup li .label { flex: 0 0 9.5em; opacity: 0.8; }
  .cleanup li .status { flex: 1 1 auto; overflow-wrap: anywhere; opacity: 0.9; }
  .cleanup li[data-state="working"] .mark { opacity: 0.5; }
  .cleanup li[data-state="ok"] .mark { color: var(--ok); }
  .cleanup li[data-state="none"] .mark,
  .cleanup li[data-state="none"] .status { opacity: 0.5; }
  .cleanup li[data-state="warn"] .mark,
  .cleanup li[data-state="warn"] .status { color: var(--warn); }
  .cleanup li[data-state="error"] .mark,
  .cleanup li[data-state="error"] .status { color: var(--error); }
  .cleanup .summary { margin-top: 0.5rem; opacity: 0.85; }
  @media (max-width: 480px) {
    .cleanup li { flex-wrap: wrap; }
    .cleanup li .label { flex-basis: calc(100% - 1.7em); }
    .cleanup li .status { flex-basis: 100%; padding-left: 1.7em; }
  }

  /* ---------- SVG animations ---------- */
  /* transform-box only on the animated elements — a global `svg *` rule would
     also re-anchor static transform attributes */
  .cloud, .truck, .truck-body, .wheel, .cargo, .puff, .smoke circle, .sign {
    transform-box: fill-box;
  }

  .cloud { animation: drift linear infinite; }
  .cloud--1 { animation-duration: 60s; }
  .cloud--2 { animation-duration: 85s; animation-delay: -40s; }
  @keyframes drift {
    from { transform: translateX(-350px); }
    to   { transform: translateX(1300px); }
  }

  .stars circle { animation: twinkle 3s ease-in-out infinite; }
  .stars circle:nth-child(2n) { animation-delay: 0.8s; }
  .stars circle:nth-child(3n) { animation-delay: 1.6s; }
  @keyframes twinkle {
    0%, 100% { opacity: 0.25; }
    50% { opacity: 1; }
  }

  /* ===== one removal = 16 s =====
     truck: arrives at the old house (x 70) → loading → drives to the new
     house (x 905) → unloading → leaves to the right */
  .truck { animation: truck-move 16s infinite; }
  @keyframes truck-move {
    0%        { transform: translateX(-280px); animation-timing-function: ease-out; }
    9%, 30%   { transform: translateX(70px);   animation-timing-function: ease-in-out; }
    62%, 84%  { transform: translateX(905px);  animation-timing-function: ease-in; }
    100%      { transform: translateX(1300px); }
  }
  /* wheels roll with the truck: same keyframes and easing, angle = distance / r
     (r = 16 px → 3.581°/px; 350 px, 835 px and 395 px) */
  .wheel {
    transform-origin: 50% 50%;
    animation: wheel-roll 16s infinite;
  }
  @keyframes wheel-roll {
    0%        { transform: rotate(0deg);    animation-timing-function: ease-out; }
    9%, 30%   { transform: rotate(1253deg); animation-timing-function: ease-in-out; }
    62%, 84%  { transform: rotate(4243deg); animation-timing-function: ease-in; }
    100%      { transform: rotate(5658deg); }
  }
  /* idling engine */
  .truck-body { animation: idle 0.45s ease-in-out infinite alternate; }
  @keyframes idle {
    from { transform: translateY(0); }
    to   { transform: translateY(-1.5px); }
  }
  .puff { transform-origin: 50% 50%; animation: exhaust 1.2s ease-out infinite; opacity: 0; }
  .puff--2 { animation-delay: 0.6s; }
  @keyframes exhaust {
    0%   { opacity: 0.5; transform: translate(0, 0) scale(0.5); }
    100% { opacity: 0;   transform: translate(-26px, -10px) scale(1.6); }
  }

  /* cargo: each item drops onto the bed at the old house (load order a, b, d, c)
     and flies into the new house (unload order c, d, b, a) */
  .cargo { animation: 16s infinite; }
  .cargo--a { animation-name: cargo-a; }
  .cargo--b { animation-name: cargo-b; }
  .cargo--c { animation-name: cargo-c; }
  .cargo--d { animation-name: cargo-d; }
  @keyframes cargo-a {
    0%, 11%   { transform: translate(0, -90px); opacity: 0; animation-timing-function: ease-in; }
    12%       { opacity: 1; }
    13.5%     { transform: translate(0, 0); animation-timing-function: ease-out; }
    14.2%     { transform: translate(0, -7px); animation-timing-function: ease-in; }
    15%, 76%  { transform: translate(0, 0); opacity: 1; animation-timing-function: ease-in; }
    79%, 100% { transform: translate(18px, -60px); opacity: 0; }
  }
  @keyframes cargo-b {
    0%, 15%   { transform: translate(0, -90px); opacity: 0; animation-timing-function: ease-in; }
    16%       { opacity: 1; }
    17.5%     { transform: translate(0, 0); animation-timing-function: ease-out; }
    18.2%     { transform: translate(0, -7px); animation-timing-function: ease-in; }
    19%, 72%  { transform: translate(0, 0); opacity: 1; animation-timing-function: ease-in; }
    75%, 100% { transform: translate(18px, -60px); opacity: 0; }
  }
  @keyframes cargo-d {
    0%, 19%   { transform: translate(0, -90px); opacity: 0; animation-timing-function: ease-in; }
    20%       { opacity: 1; }
    21.5%     { transform: translate(0, 0); animation-timing-function: ease-out; }
    22.2%     { transform: translate(0, -7px); animation-timing-function: ease-in; }
    23%, 68%  { transform: translate(0, 0); opacity: 1; animation-timing-function: ease-in; }
    71%, 100% { transform: translate(18px, -60px); opacity: 0; }
  }
  @keyframes cargo-c {
    0%, 23%   { transform: translate(0, -90px); opacity: 0; animation-timing-function: ease-in; }
    24%       { opacity: 1; }
    25.5%     { transform: translate(0, 0); animation-timing-function: ease-out; }
    26.2%     { transform: translate(0, -7px); animation-timing-function: ease-in; }
    27%, 64%  { transform: translate(0, 0); opacity: 1; animation-timing-function: ease-in; }
    67%, 100% { transform: translate(18px, -60px); opacity: 0; }
  }

  /* the last light in the old house flickers out now and then */
  .old-win { animation: flicker 9s steps(1) infinite; }
  @keyframes flicker {
    0%, 38%  { fill: #8a7a4e; }
    40%      { fill: #1c2846; }
    41%      { fill: #8a7a4e; }
    43%, 100%{ fill: #1c2846; }
  }

  /* windows of the new house light up one after another */
  .win { animation: light-up 8s ease-in-out infinite; }
  .win:nth-child(2n) { animation-delay: 2s; }
  .win:nth-child(3n) { animation-delay: 4s; }
  @keyframes light-up {
    0%, 100% { fill: #ffd97a; }
    50% { fill: #ffeab0; }
  }

  /* chimney smoke of the new house */
  .smoke circle { transform-origin: 50% 50%; animation: smoke-rise 4.5s ease-out infinite; opacity: 0; }
  .smoke circle:nth-child(2) { animation-delay: 1.5s; }
  .smoke circle:nth-child(3) { animation-delay: 3s; }
  @keyframes smoke-rise {
    0%   { opacity: 0;   transform: translate(0, 0) scale(0.5); }
    15%  { opacity: 0.4; }
    100% { opacity: 0;   transform: translate(22px, -80px) scale(2); }
  }

  /* the "moved" sign in the old garden wobbles around its stake */
  .sign { transform-origin: 50% 100%; animation: wobble 3.2s ease-in-out infinite; }
  @keyframes wobble {
    0%, 100% { transform: rotate(-2deg); }
    50% { transform: rotate(2deg); }
  }
  /* the arrow towards the new house glows */
  .signpost-new { animation: glow 2s ease-in-out infinite; }
  @keyframes glow {
    0%, 100% { opacity: 0.8; }
    50% { opacity: 1; }
  }

  @media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { animation: none !important; }
    .truck { transform: translateX(905px); }   /* parked at the new house */
  }
</style>
</head>
<body>
<main>
<div class="scene">
<svg viewBox="0 0 1200 620" role="img" aria-label="<?= $h($t['scene']) ?>">
  <defs>
    <radialGradient id="moon-glow">
      <stop offset="0.45" stop-color="#fff6d5" stop-opacity="0.55"/>
      <stop offset="1" stop-color="#fff6d5" stop-opacity="0"/>
    </radialGradient>
    <linearGradient id="beam" x1="0" x2="1" y1="0" y2="0">
      <stop offset="0" stop-color="#ffe9a8" stop-opacity="0.55"/>
      <stop offset="1" stop-color="#ffe9a8" stop-opacity="0"/>
    </linearGradient>
    <radialGradient id="door-light">
      <stop offset="0" stop-color="#ffd97a" stop-opacity="0.45"/>
      <stop offset="1" stop-color="#ffd97a" stop-opacity="0"/>
    </radialGradient>
    <g id="box">
      <rect width="40" height="36" rx="2" fill="#c8935a"/>
      <rect x="28" width="12" height="36" rx="2" fill="#a97543"/>
      <rect x="14" width="7" height="36" fill="#e6c08a"/>
    </g>
  </defs>

  <!-- stars -->
  <g class="stars" fill="#fff">
    <circle cx="60" cy="50" r="1.8"/>
    <circle cx="140" cy="120" r="1.4"/>
    <circle cx="230" cy="40" r="2"/>
    <circle cx="320" cy="170" r="1.3"/>
    <circle cx="410" cy="80" r="1.8"/>
    <circle cx="500" cy="30" r="1.4"/>
    <circle cx="560" cy="140" r="1.6"/>
    <circle cx="650" cy="60" r="2"/>
    <circle cx="730" cy="190" r="1.3"/>
    <circle cx="800" cy="35" r="1.7"/>
    <circle cx="880" cy="130" r="1.4"/>
    <circle cx="950" cy="210" r="1.2"/>
    <circle cx="1150" cy="40" r="1.8"/>
    <circle cx="1170" cy="170" r="1.4"/>
    <circle cx="30" cy="230" r="1.3"/>
    <circle cx="460" cy="230" r="1.2"/>
  </g>

  <!-- moon -->
  <circle cx="1050" cy="95" r="80" fill="url(#moon-glow)"/>
  <circle cx="1050" cy="95" r="34" fill="#fdf3c8"/>
  <circle cx="1040" cy="85" r="7" fill="#efe2b0"/>
  <circle cx="1061" cy="106" r="5" fill="#efe2b0"/>
  <circle cx="1058" cy="80" r="3" fill="#efe2b0"/>

  <!-- clouds -->
  <g class="cloud cloud--1" fill="#fff" opacity="0.12">
    <ellipse cx="160" cy="140" rx="70" ry="18"/>
    <ellipse cx="200" cy="126" rx="42" ry="18"/>
  </g>
  <g class="cloud cloud--2" fill="#fff" opacity="0.09">
    <ellipse cx="120" cy="250" rx="90" ry="20"/>
    <ellipse cx="160" cy="234" rx="50" ry="20"/>
  </g>

  <!-- distant hills -->
  <path d="M0 440 Q 150 385 300 420 T 600 410 T 900 400 T 1200 420 V 620 H 0 Z" fill="#22335a"/>

  <!-- ground, pavement and road -->
  <rect x="0" y="465" width="1200" height="155" fill="#1a2744"/>
  <rect x="0" y="494" width="1200" height="8" fill="#3a4670"/>
  <rect x="0" y="502" width="1200" height="78" fill="#2a3352"/>
  <line x1="0" y1="541" x2="1200" y2="541" stroke="#ffc93c" stroke-width="4" stroke-dasharray="34 26" opacity="0.5"/>

  <!-- trees -->
  <g fill="#1d3a4c">
    <rect x="416" y="410" width="8" height="58" fill="#3b2f3a"/>
    <circle cx="420" cy="400" r="30"/>
    <circle cx="404" cy="420" r="20"/>
    <circle cx="438" cy="418" r="22"/>
    <rect x="796" y="420" width="7" height="48" fill="#3b2f3a"/>
    <circle cx="800" cy="410" r="24"/>
    <circle cx="786" cy="428" r="16"/>
    <circle cx="814" cy="426" r="17"/>
  </g>

  <!-- the old house (left): dark, empty -->
  <rect x="200" y="288" width="22" height="46" fill="#3a3550"/>
  <rect x="60" y="350" width="190" height="118" fill="#2c3a5e"/>
  <polygon points="45,352 155,275 265,352" fill="#3b2f4a"/>
  <rect class="old-win" x="80" y="372" width="40" height="34" fill="#1c2846"/>
  <rect x="190" y="372" width="40" height="34" fill="#1c2846"/>
  <g stroke="#3d4d75" stroke-width="3">
    <line x1="100" y1="372" x2="100" y2="406"/>
    <line x1="80" y1="389" x2="120" y2="389"/>
    <line x1="210" y1="372" x2="210" y2="406"/>
    <line x1="190" y1="389" x2="230" y2="389"/>
  </g>
  <rect x="138" y="400" width="34" height="68" fill="#1c2846"/>
  <circle cx="165" cy="436" r="2.5" fill="#3d4d75"/>

  <!-- "moved" sign in the old garden -->
  <g class="sign">
    <rect x="293" y="420" width="6" height="50" fill="#6b5a45"/>
    <rect x="266" y="396" width="60" height="32" rx="3" fill="#ffc93c"/>
    <path d="M280 412 H306 M298 404 L307 412 L298 420" stroke="#1b2a4a" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
  </g>

  <!-- signpost halfway -->
  <rect x="596" y="356" width="8" height="112" fill="#6b5a45"/>
  <polygon points="596,370 522,370 506,384 522,398 596,398" fill="#4b5b82"/>
  <polygon class="signpost-new" points="604,404 680,404 696,418 680,432 604,432" fill="#ffc93c"/>

  <!-- the new house (right): lit, smoke from the chimney -->
  <g class="smoke" fill="#cfd8ea">
    <circle cx="1103" cy="242" r="9"/>
    <circle cx="1103" cy="242" r="9"/>
    <circle cx="1103" cy="242" r="9"/>
  </g>
  <rect x="1090" y="248" width="26" height="62" fill="#7a3f36"/>
  <rect x="900" y="320" width="240" height="148" fill="#9c5a46"/>
  <polygon points="885,322 1020,240 1155,322" fill="#5b2f3a"/>
  <ellipse cx="1020" cy="472" rx="70" ry="14" fill="url(#door-light)"/>
  <g>
    <rect class="win" x="925" y="345" width="44" height="36" fill="#ffd97a"/>
    <rect class="win" x="1071" y="345" width="44" height="36" fill="#ffd97a"/>
    <rect class="win" x="925" y="405" width="44" height="36" fill="#ffd97a"/>
    <rect class="win" x="1071" y="405" width="44" height="36" fill="#ffd97a"/>
    <circle class="win" cx="1020" cy="292" r="14" fill="#ffd97a"/>
  </g>
  <g stroke="#7a4434" stroke-width="3">
    <line x1="947" y1="345" x2="947" y2="381"/>
    <line x1="925" y1="363" x2="969" y2="363"/>
    <line x1="1093" y1="345" x2="1093" y2="381"/>
    <line x1="1071" y1="363" x2="1115" y2="363"/>
    <line x1="947" y1="405" x2="947" y2="441"/>
    <line x1="925" y1="423" x2="969" y2="423"/>
    <line x1="1093" y1="405" x2="1093" y2="441"/>
    <line x1="1071" y1="423" x2="1115" y2="423"/>
  </g>
  <rect x="1000" y="398" width="40" height="70" fill="#ffe6a0"/>
  <rect x="994" y="392" width="52" height="6" fill="#5b2f3a"/>

  <!-- removal truck -->
  <g transform="translate(0, 460)">
    <g class="truck">
      <polygon points="227,58 340,36 340,92" fill="url(#beam)"/>
      <circle class="puff" cx="-12" cy="80" r="6" fill="#cfd8ea"/>
      <circle class="puff puff--2" cx="-12" cy="80" r="6" fill="#cfd8ea"/>
      <g class="truck-body">
        <!-- cargo: a, b, d on the bed, c stacked on a -->
        <use class="cargo cargo--a" href="#box" x="6" y="22"/>
        <use class="cargo cargo--b" href="#box" x="50" y="28"/>
        <g class="cargo cargo--d">
          <rect x="98" y="16" width="52" height="42" rx="2" fill="#b98652"/>
          <rect x="138" y="16" width="12" height="42" rx="2" fill="#9a6a3b"/>
          <rect x="98" y="33" width="52" height="7" fill="#e6c08a"/>
        </g>
        <g class="cargo cargo--c">
          <rect x="16" y="-10" width="5" height="32" fill="#5b4a3a"/>
          <rect x="22" y="-6" width="5" height="28" fill="#5b4a3a"/>
          <ellipse cx="22" cy="-14" rx="20" ry="13" fill="#3f8a5a"/>
          <ellipse cx="12" cy="-22" rx="11" ry="9" fill="#4fa36a"/>
          <ellipse cx="32" cy="-20" rx="10" ry="8" fill="#4fa36a"/>
        </g>
        <!-- bed and side boards -->
        <rect x="0" y="58" width="160" height="14" fill="#4a6fa8"/>
        <rect x="0" y="58" width="160" height="3" fill="#6f93c9"/>
        <rect x="-6" y="72" width="234" height="9" fill="#2b2f45"/>
        <!-- cab -->
        <path d="M160 72 V36 Q160 28 168 28 H196 Q204 28 208 36 L214 50 H222 Q228 50 228 56 V72 Z" fill="#ffc93c"/>
        <path d="M170 36 H196 L203 52 H170 Z" fill="#9fd3ff" opacity="0.85"/>
        <rect x="224" y="56" width="5" height="8" rx="1" fill="#fff6c0"/>
        <rect x="160" y="64" width="70" height="4" fill="#d9a42c"/>
      </g>
      <g class="wheel">
        <circle cx="38" cy="86" r="16" fill="#1b1e2c"/>
        <circle cx="38" cy="86" r="8" fill="#8a93b0"/>
        <path d="M30 86 H46 M38 78 V94" stroke="#1b1e2c" stroke-width="3"/>
      </g>
      <g class="wheel">
        <circle cx="194" cy="86" r="16" fill="#1b1e2c"/>
        <circle cx="194" cy="86" r="8" fill="#8a93b0"/>
        <path d="M186 86 H202 M194 78 V94" stroke="#1b1e2c" stroke-width="3"/>
      </g>
    </g>
  </g>
</svg>
</div>

<h1><?= $h($siteName) ?></h1>
<p class="sub"><?= $h($t['moved']) ?></p>
<p class="address">
  <span class="old"><?= $h($shortUrl($oldUrl)) ?></span>
  <span class="arrow" aria-hidden="true">&rarr;</span>
  <a href="<?= $h($target) ?>"><?= $h($shortUrl((string)$config['newUrl'])) ?></a>
</p>
<div class="actions"><a class="go-btn" href="<?= $h($target) ?>"><?= $h($t['go']) ?></a></div>
<p class="hint"><?= $h($t['bookmarks']) ?></p>
<p class="notice"><?= $h($t['reinstall']) ?></p>
<p class="countdown" id="countdown" hidden><span></span><button type="button"><?= $h($t['stay']) ?></button></p>

<section class="cleanup" aria-live="polite">
  <h2><?= $h($t['cleanupTitle']) ?></h2>
  <ul id="cleanup-list"></ul>
  <p class="summary" id="cleanup-summary" hidden></p>
  <noscript><p><?= $h($t['noscript']) ?></p></noscript>
</section>
</main>

<script>
  window.movedConfig = <?= json_encode($pageConfig, $jsonFlags) ?>;
</script>
<script>
(async () => {
  const config = window.movedConfig;
  const t = config.texts;
  const list = document.getElementById('cleanup-list');
  const fmt = (text, params) => text.replace(/\{(\w+)\}/g, (match, name) => (name in params ? params[name] : match));
  const marks = { working: '…', ok: '✓', none: '–', warn: '!', error: '✗' };
  // a storage API that never settles (seen in locked-down browsers) must not stall the rest
  const within = (promise, ms = 5000) => Promise.race([promise, new Promise((resolve, reject) => setTimeout(() => reject(new Error('timeout')), ms))]);
  let warnings = 0;

  // one report line: label + status, updated in place
  const row = (label) => {
    const item = document.createElement('li');
    item.innerHTML = '<span class="mark"></span><span class="label"></span><span class="status"></span>';
    item.querySelector('.label').textContent = label;
    list.appendChild(item);
    const set = (state, text) => {
      item.dataset.state = state;
      item.querySelector('.mark').textContent = marks[state];
      item.querySelector('.status').textContent = text;
      if (state === 'warn' || state === 'error') warnings++;
    };
    set('working', t.working);
    return { set, fail: (error) => set('error', fmt(t.failed, { error: (error && error.message) || String(error) })) };
  };

  // installed app (PWA): its icon outlives everything below, only the user can remove it
  const standalone = ['standalone', 'fullscreen', 'minimal-ui'].some((mode) => window.matchMedia && window.matchMedia('(display-mode: ' + mode + ')').matches)
    || window.navigator.standalone === true;
  if (standalone) {
    row(t.labelApp).set('warn', t.appInstalled);
  }

  // service workers
  const sw = row(t.labelSw);
  if (!('serviceWorker' in navigator)) {
    sw.set('none', t.unsupported);
  } else {
    try {
      const registrations = await within(navigator.serviceWorker.getRegistrations());
      const removed = (await within(Promise.all(registrations.map((registration) => registration.unregister())))).filter(Boolean).length;
      sw.set(registrations.length ? 'ok' : 'none', registrations.length ? fmt(t.swRemoved, { n: removed }) : t.none);
    } catch (error) {
      sw.fail(error);
    }
  }

  // Cache Storage — the offline copy of the old app, it survives the worker
  const cache = row(t.labelCaches);
  if (!('caches' in window)) {
    cache.set('none', t.unsupported);
  } else {
    try {
      const names = await within(caches.keys());
      await within(Promise.all(names.map((name) => caches.delete(name))));
      cache.set(names.length ? 'ok' : 'none', names.length ? fmt(t.cachesDeleted, { n: names.length }) : t.none);
    } catch (error) {
      cache.fail(error);
    }
  }

  // cookies — the server already expired those it received (HttpOnly too);
  // this catches the rest visible to scripts, for every path prefix and domain level
  const cookie = row(t.labelCookies);
  try {
    const cookieNames = () => document.cookie.split(';').map((pair) => pair.split('=')[0].trim()).filter(Boolean);
    const found = new Set([...config.serverCookies, ...cookieNames()]);
    const domains = [''];
    const host = location.hostname;
    if (/[a-z]/i.test(host) && host.includes('.')) {
      const labels = host.split('.');
      for (let i = 0; i < labels.length - 1; i++) domains.push(labels.slice(i).join('.'));
    }
    const paths = ['/'];
    let prefix = '';
    location.pathname.split('/').filter(Boolean).slice(0, 5).forEach((segment) => {
      prefix += '/' + segment;
      paths.push(prefix, prefix + '/');
    });
    const secure = location.protocol === 'https:' ? '; secure' : '';
    cookieNames().forEach((name) => paths.forEach((path) => domains.forEach((domain) => {
      document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; max-age=0; path=' + path + (domain ? '; domain=' + domain : '') + secure;
    })));
    const left = cookieNames();
    if (left.length) {
      cookie.set('warn', fmt(t.cookiesLeft, { names: left.join(', ') }));
    } else {
      cookie.set(found.size ? 'ok' : 'none', found.size ? fmt(t.cookiesDeleted, { names: [...found].join(', ') }) : t.none);
    }
  } catch (error) {
    cookie.fail(error);
  }

  // localStorage + sessionStorage (svision keeps settings, hall of fame and saves there)
  const storage = row(t.labelStorage);
  try {
    const count = localStorage.length + sessionStorage.length;
    localStorage.clear();
    sessionStorage.clear();
    storage.set(count ? 'ok' : 'none', count ? fmt(t.storageCleared, { n: count }) : t.none);
  } catch (error) {
    storage.fail(error);
  }

  // IndexedDB
  const idb = row(t.labelIdb);
  if (!('indexedDB' in window)) {
    idb.set('none', t.unsupported);
  } else if (typeof indexedDB.databases !== 'function') {
    idb.set('none', t.idbUnlisted);
  } else {
    try {
      const databases = (await within(indexedDB.databases())).filter((database) => database.name);
      await within(Promise.all(databases.map((database) => new Promise((resolve) => {
        const request = indexedDB.deleteDatabase(database.name);
        request.onsuccess = request.onerror = request.onblocked = resolve;
      }))));
      idb.set(databases.length ? 'ok' : 'none', databases.length ? fmt(t.idbDeleted, { n: databases.length }) : t.none);
    } catch (error) {
      idb.fail(error);
    }
  }

  // Clear-Site-Data — the browser's own wipe of everything above, plus what
  // scripts can't reach (HttpOnly cookies on other paths, the HTTP cache)
  if (config.clearSiteData) {
    const siteData = row(t.labelSiteData);
    if (!window.isSecureContext) {
      siteData.set('none', t.siteDataHttps);
    } else {
      try {
        const response = await within(fetch(config.cleanupUrl, { cache: 'no-store', credentials: 'same-origin' }));
        const result = await response.json();
        siteData.set(result.clearSiteData ? 'ok' : 'none', result.clearSiteData ? t.siteDataSent : t.siteDataHttps);
      } catch (error) {
        siteData.fail(error);
      }
    }
  }

  const summary = document.getElementById('cleanup-summary');
  summary.textContent = warnings ? t.summaryWarn : t.summaryClean;
  summary.hidden = false;

  // optional automatic redirect, only after the cleanup
  if (config.redirectSeconds > 0) {
    const countdown = document.getElementById('countdown');
    const label = countdown.querySelector('span');
    let left = config.redirectSeconds;
    label.textContent = fmt(t.countdown, { n: left });
    countdown.hidden = false;
    const timer = setInterval(() => {
      left--;
      if (left <= 0) {
        clearInterval(timer);
        location.replace(config.target);
        return;
      }
      label.textContent = fmt(t.countdown, { n: left });
    }, 1000);
    countdown.querySelector('button').addEventListener('click', () => {
      clearInterval(timer);
      countdown.hidden = true;
    });
  }
})();
</script>
</body>
</html>
