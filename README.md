# Redirection

A single-file **"This site has moved"** page — a sibling of *underconstruction*
and *working*. It is deployed into the web root of the **old** site in place of
the original application. Visitors see an animated SVG removal scene, the old
and the new address and a button to the new one, while the page cleans up
everything the old site left in their browser.

Below the button the page tells visitors who **installed the old site as an
app** (PWA — home screen / desktop icon) to **uninstall it and install it again
from the new address**: the installed app is bound to the old address, and no
cleanup on the page can move it or remove its icon.

The page is in English only. The whole site lives in `index.php` — HTML, CSS, the animation and the cleanup
script together, no dependencies.

## The scene

- A yellow removal truck drives up to the old, dark house, where boxes and a
  potted plant drop onto its bed one by one.
- It drives past a signpost pointing to the new address and unloads at the new,
  brightly lit house, then leaves to the right. One removal takes 16 s.
- Wheels roll in sync with the truck, the engine idles and puffs exhaust, the
  chimney of the new house smokes, the last light of the old house flickers
  out, stars twinkle and clouds drift.
- Everything respects `prefers-reduced-motion` (the animation stops and the
  truck stays parked at the new house).

## Configuration

Copy the sample and edit it:

```bash
cp config.sample.json config.json
```

| Key | Default | Meaning |
|---|---|---|
| `siteName` | host name | Name of the site, shown as the heading |
| `oldUrl` | host name | The old address (shown crossed out) |
| `newUrl` | — (required) | The new address, an absolute `http(s)` URL |
| `version` | `"#moved"` | Version returned to the apps' version check — must differ from every real version |
| `redirectSeconds` | `0` | Redirect to the new address this many seconds after the cleanup; `0` = off |
| `keepPath` | `false` | Append the visited path and query to the new address (`/about` → `newUrl/about`). The path is taken relative to the directory the page is installed in (`/old/about` → `newUrl/about` when installed in `/old/`); a subdirectory on the new site, if needed, goes into `newUrl` |
| `clearSiteData` | `true` | Send the `Clear-Site-Data` header (see below) |

`config.json` is git-ignored.

## What it does with the old site's leftovers

Every old URL is rewritten to `index.php` (the same rewrite the svision nginx
configs already use), so the page also answers the requests of installed apps:

| Request | Answer |
|---|---|
| `POST version.db` (svision version check) | `{"data": {"version": "#moved"}, "fetchDataId": …}` — the app sees a new version, shows **UPGRADE**, and its `upgradeApp()` unregisters the worker and reloads into this page |
| other `POST *.db` / `*.data` / `*.post` | `{"error": {"message": "moved", "newUrl": …}}` |
| the service worker script (`serviceWorker`, `sw.js`, or any request with `Service-Worker: script`) | a **kill-switch worker**: browsers re-check the worker script on every visit, so it replaces the old one, activates at once, deletes all caches, unregisters itself and reloads the open tabs — an installed app cleans itself up even if the user never presses UPGRADE |
| `robots.txt` | allow all, so crawlers can see the `noindex` |
| anything else | the page |

The page itself then:

1. **expires every cookie** the browser sent — server-side, so HttpOnly cookies
   (e.g. the PHP session) go too — for each path prefix of the URL and each
   domain level,
2. **unregisters all service workers** of the origin,
3. **deletes Cache Storage** (the offline copy of the old app),
4. **deletes the remaining cookies** visible to scripts,
5. **clears `localStorage` and `sessionStorage`** (svision keeps settings, the
   hall of fame and saved games there),
6. **deletes IndexedDB databases** (where the browser can list them),
7. **asks for `Clear-Site-Data: "cache", "cookies", "storage"`** — the browser's
   own wipe of everything above plus what scripts can't reach. Browsers honour
   it over HTTPS only, and `"cookies"` applies to the whole registrable domain
   (`old.example.com` also clears cookies of `example.com` and its other
   subdomains) — turn `clearSiteData` off if that domain hosts other sites,
8. **warns when the page runs as an installed app** (PWA): the icon on the home
   screen / desktop can only be removed by the user — they have to uninstall
   the app and install it again from the new address.

Each step is reported on the page. Without JavaScript only step 1 runs.

## Search engines

The page answers **HTTP 200** with `noindex, follow` (header and meta tag): the
notice itself isn't indexed, but crawlers follow the link to the new address.
The proper signal for search engines is a permanent redirect, which a page
that has to run in the browser can't give — so keep this page for a transition
period (until installed apps have had time to come by) and then replace it with
a `301` in the server config, e.g. for nginx:

```nginx
location / { return 301 https://new.example.com$request_uri; }
```

## Deployment

Replace the old site's web root contents with this repository and create
`config.json`. Remove the old application completely — files left behind would
still be served as they are.

- **nginx** (svision sites): the existing server block works unchanged — it
  rewrites `/(.*)` to `/index.php?$1` and already denies `*.json`.
- **Apache**: `.htaccess` does the same rewrite and denies `config.json`
  (needs `mod_rewrite` and `AllowOverride All`).

The page is sent with `Cache-Control: no-store`, so it doesn't outlive the move
in browser caches. No build step, no writable files.

## Files

| File | Description |
|---|---|
| `index.php` | The whole site — endpoints, page, CSS, the animated SVG scene and the cleanup script |
| `config.sample.json` | Sample for `config.json` — copy it on deployment |
| `config.json` | The configuration (git-ignored, created from the sample) |
| `.htaccess` | Apache rewrite to `index.php` and protection of `config.json` |

## License

[GNU GPL v3](LICENSE)
