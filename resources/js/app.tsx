import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { toast } from 'sonner';

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

// Global flash → toast handler. Backend abort(422, "msg") sekarang dikonversi
// jadi back()->with('error', "msg") oleh withExceptions handler — di-share ke
// flash.error lewat HandleInertiaRequests middleware. Listener ini nge-toast
// pesannya rapi (Sonner), TIDAK render halaman error mentah.
//
// flash.success juga di-toast — duplikat dgn client-side toast.success(...)
// existing dianggap acceptable (form sukses jarang ngirim dua-duanya).
// Dedupe per pesan dalam 1 turn: kalau pesan sama berturut-turut, skip.
let _lastFlash = { error: '', success: '' };

function handleFlash(props: Record<string, unknown>) {
    const flash = (props.flash as { success?: string | null; error?: string | null } | undefined);
    if (! flash) return;

    if (flash.error && flash.error !== _lastFlash.error) {
        toast.error(flash.error);
        _lastFlash.error = flash.error;
    } else if (! flash.error) {
        _lastFlash.error = '';
    }

    if (flash.success && flash.success !== _lastFlash.success) {
        toast.success(flash.success);
        _lastFlash.success = flash.success;
    } else if (! flash.success) {
        _lastFlash.success = '';
    }
}

router.on('navigate', (event) => {
    handleFlash(event.detail.page.props);
});

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
