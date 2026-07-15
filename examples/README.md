# Examples

Two self-contained client examples for `webkul/inertia`. Both talk to the same
kind of PHP entry point (see `index.php` in each folder) — the only thing that
changes is how the browser side consumes the Inertia page object.

| Folder | Client |
| --- | --- |
| [`react/`](react/) | Official `@inertiajs/react` adapter (React 18). |
| [`vanilla-js/`](vanilla-js/) | No framework — a ~70 line hand-rolled Inertia client showing exactly what the protocol does: reading the initial page from the JSON script tag, XHR visits with `X-Inertia` headers, the 409 stale-asset reload, partial reloads and back/forward handling. |

## Serve an example

From inside `react/` or `vanilla-js/` (after installing the package in a
project so `vendor/autoload.php` exists — adjust the path at the top of
`index.php` to your setup):

```bash
php -S localhost:8000 index.php
```

`index.php` acts as the front controller / router: it maps the URL path to a
component name and props, then hands off to `Inertia::render()`. The package
answers standard visits with the HTML shell and Inertia visits with bare JSON.
