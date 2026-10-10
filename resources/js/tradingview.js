// The TradingView page: load TradingView's own script and build its chart widget in the page.
// The widget takes its theme when it is built, so it is built again when the light and dark
// switch is used.
// https://www.tradingview.com/widget-docs/widgets/charts/advanced-chart/
const container = document.querySelector('[data-tradingview]');

if (container) {
    const theme = () => (document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light');

    const build = () => {
        container.replaceChildren();

        new window.TradingView.widget({
            container_id: container.id,
            autosize: true,
            symbol: container.dataset.symbol,
            interval: '60',
            timeframe: '5D',
            timezone: container.dataset.timezone,
            theme: theme(),
            style: '1',
            locale: 'en',
            enable_publishing: false,
            withdateranges: true,
            hide_side_toolbar: false,
            allow_symbol_change: true,
            details: true,
            show_popup_button: true,
            studies: ['RSI@tv-basicstudies'],
            watchlist: JSON.parse(container.dataset.watchlist),
        });
    };

    const script = document.createElement('script');
    script.src = 'https://s3.tradingview.com/tv.js';
    script.onload = () => {
        build();

        new MutationObserver(build).observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });
    };
    script.onerror = () => {
        container.textContent = 'The TradingView chart could not be loaded.';
    };

    document.head.append(script);
}
