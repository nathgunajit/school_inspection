# Browser support and coverage check

Modification of the Application V3, item 7: **Chrome, Edge and Firefox**.

## Supported versions

| Browser | Minimum | Notes |
|---|---|---|
| Google Chrome (desktop and Android) | 100 | Main target for officers' phones |
| Microsoft Edge | 100 | Same engine as Chrome |
| Mozilla Firefox (desktop and Android) | 100 | Checked separately, see below |
| Safari (iOS / macOS) | 15 | Best effort |

The same list is set in three places:
- the `browserslist` in `package.json`, which autoprefixer uses through `postcss.config.js` to add vendor prefixes
- `build.target` in `vite.config.js`, which sets the JavaScript syntax level

Camera, location and SHA-256 hashing need **HTTPS**, or `localhost`, in every browser.

## What was checked in the code (2026-11-18)

| Area | Chrome / Edge | Firefox | Handling |
|---|---|---|---|
| Layout (grid, flex, `aspect-ratio`, `inset`, sticky) | ✔ | ✔ | Standard CSS; all of it is supported from version 100. |
| `:has()` (confirm box, fund balance colour) | ✔ 105+ | ✔ 121+ | Fallback: JS adds `.is-checked` / `.is-neg`, so it looks the same in older versions. |
| Two-line tab labels (`line-clamp`) | ✔ | ✔ | `-webkit-line-clamp` plus the standard `line-clamp` and a `max-height` fallback. |
| Frosted top bar and tabs (`backdrop-filter`) | ✔ | ✔ 103+ | Prefixed by autoprefixer; there is a near-opaque background colour if it isn't supported. |
| Number fields | ✔ | ✔ | Browser spin buttons are hidden everywhere (`appearance: textfield`). Firefox's wide spinners covered the "L" suffix. The − / + buttons are used instead. |
| Date field | ✔ | ✔ | Native date picker; same 44 px height. |
| Dropdowns (enhanced select) | ✔ | ✔ | Custom component built on the native `<select>`, so it works with keyboard and touch. |
| In-app camera (`getUserMedia`, `facingMode`) | ✔ | ✔ | If permission is denied or there is no camera, a message is shown and the Gallery upload still works. |
| Gallery photos (`createImageBitmap`, canvas → JPEG) | ✔ | ✔ | EXIF rotation is respected by both. Unreadable formats such as HEIC in Firefox show "choose a JPG or PNG". |
| SHA-256 (`crypto.subtle`) | ✔ | ✔ | Only on HTTPS; otherwise the server computes the hash. |
| Lakh amounts (`toLocaleString('en-IN')`) | ✔ | ✔ | Indian digit grouping in both. |
| Session idle warning (`localStorage`, `storage` event) | ✔ | ✔ | Shared across tabs. In private mode it still works per tab. |
| Review: Expand all, photo viewer (Bootstrap 5.3) | ✔ | ✔ | Loaded as a module (`resources/js/review.js`). |
| PDF report (dompdf, DejaVu Sans) | ✔ | ✔ | Built on the server; opens in each browser's own PDF viewer. |
| Excel export | ✔ | ✔ | Server-side download. |

Automated feature tests (`php artisan test`) cover the server side for all browsers. They do not run a real browser.

## Manual pass: run once per browser before release

Use a phone (Chrome, Firefox) and a desktop (Chrome, Edge, Firefox). Test at 360 px, 768 px and 1280 px width. Tick each item:

| # | Check | Chrome | Edge | Firefox |
|---|---|---|---|---|
| 1 | Login: wrong password ×2, CAPTCHA appears, 3rd wrong locks for 20 min | ☐ | ☐ | ☐ |
| 2 | Registration: cascading District → Block → Cluster, photo + ID proof upload | ☐ | ☐ | ☐ |
| 3 | Header account menu opens; profile page photo change | ☐ | ☐ | ☐ |
| 4 | Find school: live search, badges ("Draft by another officer", "Inspected this month") | ☐ | ☐ | ☐ |
| 5 | Step tabs: all 8 visible, no sideways scrolling | ☐ | ☐ | ☐ |
| 6 | Steps 1–6: answers save, −/+ buttons, totals on step 2, subject suggestions on step 4, fund years on step 5 | ☐ | ☐ | ☐ |
| 7 | Step 7: camera photo (allow camera + location) and gallery photo | ☐ | ☐ | ☐ |
| 8 | Review: Expand all / Collapse all, photo viewer (prev/next, swipe), submit | ☐ | ☐ | ☐ |
| 9 | Report page, PDF download, Excel export | ☐ | ☐ | ☐ |
| 10 | Idle warning after 18 min, "Stay signed in", sign-out at 20 min | ☐ | ☐ | ☐ |

Record any problem in `ACTION.md`, with the browser, its version and the screen size.
