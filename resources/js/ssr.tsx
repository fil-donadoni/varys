import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { renderToString } from 'react-dom/server';
import { StrictMode } from 'react';

const appName = import.meta.env.VITE_APP_NAME || 'Varys';

createInertiaApp({
    page: undefined!,
    render: renderToString,
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx')),
    setup({ App, props }) {
        return (
            <StrictMode>
                <App {...props} />
            </StrictMode>
        );
    },
});
