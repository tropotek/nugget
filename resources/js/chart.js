// The area charts: an asset's price and the portfolio's value. The server sends the points as
// [time, amount] pairs, with the amount a decimal string: it becomes a float here only to be drawn.
const charts = new Map();
let library;

const theme = () => (document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light');

// A price under a dollar keeps the places a cheap coin needs.
const dollars = (value) => '$' + value.toLocaleString('en-AU', {
    minimumFractionDigits: 2,
    maximumFractionDigits: Math.abs(value) < 1 ? 8 : 2,
});

// On the axis, a price in the thousands is shown without its cents.
const axisDollars = (value) => (Math.abs(value) < 1000 ? dollars(value) : '$' + Math.round(value).toLocaleString('en-AU'));

const draw = async (element) => {
    library ??= import('apexcharts');
    const { default: ApexCharts } = await library;

    if (!element.isConnected) {
        return;
    }

    const times = element.dataset.times === 'true';
    const colour = getComputedStyle(document.documentElement).getPropertyValue('--bs-primary').trim();

    const chart = new ApexCharts(element, {
        chart: {
            type: 'area',
            height: 320,
            background: 'transparent',
            toolbar: { show: false },
            zoom: { enabled: false },
            animations: { enabled: false },
        },
        theme: { mode: theme() },
        colors: [colour],
        series: [{
            name: element.dataset.label,
            data: JSON.parse(element.dataset.points).map(([at, amount]) => [at, Number(amount)]),
        }],
        dataLabels: { enabled: false },
        stroke: { width: 2, curve: 'straight' },
        fill: { type: 'gradient', gradient: { opacityFrom: 0.35, opacityTo: 0.05 } },
        // The times are the owner's clock times sent as UTC, so the axis is drawn in UTC.
        xaxis: { type: 'datetime', labels: { datetimeUTC: true }, tooltip: { enabled: false } },
        yaxis: { labels: { formatter: axisDollars } },
        tooltip: { x: { format: times ? 'd MMM, h:mmtt' : 'd MMM yyyy' }, y: { formatter: dollars } },
    });

    charts.set(element, chart);
    chart.render();
};

// Draw the charts on the page that are not drawn yet, and let go of any that htmx swapped out.
const refresh = () => {
    charts.forEach((chart, element) => {
        if (!element.isConnected) {
            chart.destroy();
            charts.delete(element);
        }
    });

    document.querySelectorAll('[data-chart]:not([data-drawn])').forEach((element) => {
        element.dataset.drawn = 'true';
        draw(element);
    });
};

refresh();
document.body.addEventListener('htmx:afterSwap', refresh);

// The light and dark switch in the top bar.
new MutationObserver(() => charts.forEach((chart) => chart.updateOptions({ theme: { mode: theme() } })))
    .observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });
