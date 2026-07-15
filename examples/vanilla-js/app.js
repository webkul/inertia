// Example client: a minimal Inertia client in plain JavaScript, no framework.
//
// It demonstrates the whole protocol the webkul/inertia package speaks:
//   1. boot from the JSON script tag rendered into the HTML shell,
//   2. SPA visits via fetch() with the X-Inertia headers,
//   3. the 409 + X-Inertia-Location stale-asset handshake,
//   4. partial reloads (only / except),
//   5. back/forward navigation via the History API.

const APP_ID = 'app';

// 1. Boot: the initial page object lives in the JSON script tag.
let page = JSON.parse(
    document.querySelector(`script[data-page="${APP_ID}"]`).textContent
);

const el = document.getElementById(APP_ID);

// One render function per component name sent by PHP.
const pages = {
    Home(props) {
        return `
            <h1>${props.message}</h1>
            <a href="/orders" data-inertia>View orders</a>`;
    },

    Orders(props) {
        const rows = props.orders
            .map((order) => `<li>#${order.id} — ${order.total}</li>`)
            .join('');

        return `
            <h1>Orders (${props.stats.count})</h1>
            <ul>${rows}</ul>
            <p>Props generated at ${props.generatedAt}</p>
            <button id="refresh-orders">Refresh orders only</button>
            <a href="/" data-inertia>Back home</a>`;
    },
};

function render() {
    el.innerHTML = pages[page.component](page.props);
}

// 2. An Inertia visit: same URL space, but asking for JSON instead of HTML.
async function visit(url, { partial = null, replace = false } = {}) {
    const headers = {
        'X-Inertia': 'true',
        'X-Inertia-Version': page.version,
        Accept: 'application/json',
    };

    // 4. Partial reload: only refetch some props of the CURRENT component.
    //    Lazy (closure) props filtered out here never execute in PHP.
    if (partial) {
        headers['X-Inertia-Partial-Component'] = page.component;
        if (partial.only) {
            headers['X-Inertia-Partial-Data'] = partial.only.join(',');
        }
        if (partial.except) {
            headers['X-Inertia-Partial-Except'] = partial.except.join(',');
        }
    }

    const response = await fetch(url, { headers });

    // 3. Server assets changed since our bundle was loaded: do one hard
    //    reload at the location the server tells us.
    if (response.status === 409) {
        window.location.href =
            response.headers.get('X-Inertia-Location') || url;
        return;
    }

    const next = await response.json();

    // Partial responses only carry the requested props — merge them.
    page = partial
        ? { ...page, props: { ...page.props, ...next.props } }
        : next;

    history[replace ? 'replaceState' : 'pushState'](page, '', page.url);
    render();
}

// Intercept clicks on links marked with data-inertia.
document.addEventListener('click', (event) => {
    const link = event.target.closest('a[data-inertia]');

    if (link) {
        event.preventDefault();
        visit(link.getAttribute('href'));
        return;
    }

    if (event.target.id === 'refresh-orders') {
        // `stats` is excluded, so its closure never runs server-side;
        // watch `generatedAt` stay stale while `orders` refreshes.
        visit(page.url, { partial: { only: ['orders'] }, replace: true });
    }
});

// 5. Back/forward buttons restore the page object we stashed in history.
window.addEventListener('popstate', (event) => {
    if (event.state) {
        page = event.state;
        render();
    }
});

history.replaceState(page, '', page.url);
render();
