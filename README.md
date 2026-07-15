# webkulwp/inertia

Inertia.js server-side adapter for **PHP** — the equivalent of what
`inertia-laravel` provides on Laravel, for any plain-PHP application.

It is framework-agnostic: everything runs on native PHP. When WordPress is
loaded, its helpers (`wp_json_encode`, `status_header`, `sanitize_text_field`,
`wp_head` / `wp_footer` in the default shell, the `blog_charset` option, …)
are picked up automatically — no configuration needed.

It handles the full Inertia protocol:

- **First / standard visit** → full HTML document with the page object embedded
  in a JSON script tag (`script[data-page="<id>"][type="application/json"]`,
  the Inertia v3 convention).
- **Inertia visit** (XHR with `X-Inertia: true`) → bare JSON page object, no
  HTML, so the client swaps props without a page reload.
- **Stale assets** (`X-Inertia-Version` mismatch on GET) → `409` +
  `X-Inertia-Location`, telling the client to do one hard reload to pick up
  new bundles.
- **Partial reloads** → prop filtering via `X-Inertia-Partial-Data` /
  `X-Inertia-Partial-Except`, with closure props resolved lazily *after*
  filtering so skipped props cost no queries.

## Installation

```bash
composer require webkulwp/inertia
```

While the package lives inside a project as a path repository, add to the
project's `composer.json`:

```json
{
    "repositories": [
        { "type": "path", "url": "packages/inertia" }
    ],
    "require": {
        "webkulwp/inertia": "@dev"
    }
}
```

## Usage

### Plain PHP

```php
use Webkul\Inertia\Inertia;

$inertia = Inertia::instance()
    ->set_version( '1.0.0' )   // string or callable, e.g. a build hash
    ->set_app_id( 'app' );     // container / script id for the default shell

$inertia->render( 'Order', array(
    'orders'  => fn() => fetch_orders(), // lazy: skipped on partial reloads
    'filters' => $filters,
) );
```

### WordPress (custom HTML shell)

```php
use Webkul\Inertia\Inertia;

$inertia = Inertia::instance()
    ->set_version( MY_PLUGIN_SCRIPT_VERSION )
    ->set_root_view( array( My_Template::instance(), 'render_ui_template' ) );

if ( ! $inertia->is_inertia_request() ) {
    // Assets are only needed for the HTML shell, not for JSON visits.
    my_plugin_enqueue_app_assets();
}

$inertia->render( 'Order', $props );
```

`render()` always terminates the request.

### Configuration

| Method | Purpose |
| --- | --- |
| `set_version( string\|callable $version )` | Asset version used for the 409 stale-asset handshake. |
| `set_root_view( callable $renderer )` | Renderer for the HTML shell on standard visits. Receives `( string $page_json, array $page )` and must output the full document. |
| `set_app_id( string $id )` | Container / script id used by the built-in fallback shell (default `app`). Only relevant when no root view is set. |
| `set_charset( string $charset )` | Response charset (default `UTF-8`). Under WordPress the `blog_charset` option takes precedence. |

If no root view is configured, a minimal shell is rendered: a JSON script tag
plus an empty `<div id="<app id>">` — and, inside WordPress, `wp_head()` /
`wp_footer()` / `body_class()` are included automatically.

## Examples

Runnable end-to-end examples live in [`examples/`](examples/):

- [`examples/react/`](examples/react/) — client built with the official
  `@inertiajs/react` adapter (React 18), including SPA links and partial
  reloads via `router.reload({ only: [...] })`.
- [`examples/vanilla-js/`](examples/vanilla-js/) — no framework: a ~70 line
  hand-rolled client showing the raw protocol (boot from the JSON script tag,
  `fetch()` visits with `X-Inertia` headers, the 409 hard-reload handshake,
  partial reloads, back/forward handling).

Each folder is a standalone mini-app: `php -S localhost:8000 index.php`.
