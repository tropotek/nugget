import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const host = env.VITE_HOST || 'localhost';
    const port = parseInt(env.VITE_PORT || '5778', 10);

    return {
        plugins: [
            laravel({
                input: ['resources/scss/app.scss', 'resources/scss/icons.scss', 'resources/js/app.js'],
                refresh: true,
                fonts: [
                    // The Minton skin's text font, self-hosted (see config/default/_variables-custom.scss).
                    bunny('Roboto', {
                        weights: [300, 400, 500, 700],
                        optimizedFallbacks: false,
                    }),
                ],
            }),
        ],
        css: {
            preprocessorOptions: {
                scss: {
                    // Bootstrap 5.3 and the Minton skin are written in the old Sass style (@import, global
                    // built-ins). Dart Sass flags every use; these are not ours to fix.
                    silenceDeprecations: ['import', 'global-builtin', 'color-functions', 'if-function'],
                },
            },
        },
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
