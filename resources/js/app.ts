import './bootstrap';
import '../css/app.css';
import { initializeTheme } from './theme';

import { createInertiaApp } from '@inertiajs/vue3';
import { createApp, h } from 'vue';

initializeTheme();

createInertiaApp({
    title: (title) => (title ? `${title} - VolumeVault` : 'VolumeVault'),
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
        return pages[`./Pages/${name}.vue`];
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) }).use(plugin).mount(el);
    },
    progress: {
        color: '#38bdf8',
    },
});
