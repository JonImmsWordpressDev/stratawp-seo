# Bundled third-party libraries

These are vendored rather than loaded from a CDN: the WordPress.org plugin
guidelines require all scripts to be served from the plugin itself, and a CDN
request would also send every admin user's IP to a third party.

Nothing here is modified. Each file is the vendor's published distribution
build, downloaded from the version-pinned jsDelivr URL below.

| File | Library | Version | License | Source |
| --- | --- | --- | --- | --- |
| `chart.umd.min.js` | Chart.js | 4.4.7 | MIT | https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js |
| `fullcalendar.global.min.js` | FullCalendar Standard Bundle | 6.1.11 | MIT | https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js |
| `fullcalendar.global.js` | FullCalendar Standard Bundle (unminified) | 6.1.11 | MIT | https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.js |

## On unminified sources

FullCalendar ships a readable build, included above as
`fullcalendar.global.js` so the minified file can be verified against it.

Chart.js v4 publishes **no unminified UMD bundle** — `dist/chart.umd.js` and
`dist/chart.umd.min.js` are the same minified artefact. The reviewable source
is the upstream repository, tagged for this exact version:
https://github.com/chartjs/Chart.js/tree/v4.4.7

## Updating

Replace the file from the same pinned URL with the version bumped, update the
table, and update the version string in the matching `wp_enqueue_script()`
call (`stratawp-seo.php` for Chart.js, `includes/class-calendar.php` for
FullCalendar) so cache-busting stays correct.
