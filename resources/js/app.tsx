import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Blur focused <input type="number"> on wheel so scroll never mutates value.
// Default browser behavior treats wheel as +/- step — dangerous in POS/finance
// where stray scroll can silently change harga/qty/diskon. Passive listener,
// no preventDefault: page scroll continues normally; value is safe because the
// input is no longer focused when the browser would have applied the step.
document.addEventListener(
    'wheel',
    (e) => {
        const t = e.target;
        if (
            t instanceof HTMLInputElement &&
            t.type === 'number' &&
            document.activeElement === t
        ) {
            t.blur();
        }
    },
    { passive: true },
);

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.tsx`,
            import.meta.glob('./Pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    progress: {
        color: '#4B5563',
    },
});
