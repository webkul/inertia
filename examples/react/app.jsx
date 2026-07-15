// Example client: @inertiajs/react against a webkulwp/inertia backend.
//
// Build:  npm install && npm run build   (outputs dist/app.js)

import { createRoot } from 'react-dom/client';
import { createInertiaApp, Link, router } from '@inertiajs/react';

// One React component per component name sent by PHP. In a real app these
// live in their own files and can be code-split via dynamic import().
const Home = ({ message }) => (
    <main>
        <h1>{message}</h1>
        <Link href="/orders">View orders</Link>
    </main>
);

const Orders = ({ orders }) => (
    <main>
        <h1>Orders</h1>
        <ul>
            {orders.map((order) => (
                <li key={order.id}>
                    #{order.id} — {order.total}
                </li>
            ))}
        </ul>
        {/* Partial reload: refetches ONLY the `orders` prop. On the PHP
            side, other lazy props are skipped entirely. */}
        <button onClick={() => router.reload({ only: ['orders'] })}>
            Refresh orders
        </button>
        <Link href="/">Back home</Link>
    </main>
);

const pages = { Home, Orders };

createInertiaApp({
    id: 'app',

    // The default shell (and this example's custom shell) embeds the page
    // object in a JSON script tag rather than a data-page attribute on the
    // mount element, so hand it to the adapter explicitly.
    page: JSON.parse(
        document.querySelector('script[data-page="app"]').textContent
    ),

    resolve: (name) => pages[name],

    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
});
