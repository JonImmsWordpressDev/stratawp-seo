# Bundled admin fonts

Poppins (headings) and Open Sans (body) for the plugin's admin screens.

Self-hosted rather than loaded from `fonts.googleapis.com`: the WordPress.org
guidelines expect assets to be served from the plugin, and a remote webfont
request sends every admin user's IP address to Google on every page load.

Both families are licensed under the **SIL Open Font License 1.1**, which is
GPL-compatible.

| Family | Weights | Source |
| --- | --- | --- |
| Poppins | 400, 500, 600, 700 | https://fonts.google.com/specimen/Poppins |
| Open Sans | variable 300–800 | https://fonts.google.com/specimen/Open+Sans |

Latin and Latin-Extended subsets only. Open Sans is a variable font, so one
file per subset covers every weight the admin uses; Poppins ships static
instances per weight.

The `@font-face` rules live in `admin/css/fonts.css` and were generated from
Google's own `css2` response, so the `unicode-range` values match upstream
exactly. To update, re-request that CSS, re-download the woff2 files it points
at, and regenerate.
