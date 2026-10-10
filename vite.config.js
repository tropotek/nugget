import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const host = env.VITE_HOST || 'localhost';
    const port = parseInt(env.VITE_PORT || '5778', 10);

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.js'],
                refresh: true,
                fonts: [
                    bunny('Instrument Sans', {
                        weights: [400, 500, 600],
                    }),
                ],
            }),
            tailwindcss(),
        ],
        server: {
            host: '0.0.0.0',
            port,
            strictPort: true,
            origin: `http://${host}:${port}`,
            cors: {
                origin: env.APP_URL || `http://${host}:${port}`,
            },
            hmr: { host, port },
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
