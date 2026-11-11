// Admin analytics charts (Chart.js). Loaded ONLY on admin pages that contain a chart, never on the storefront.
// Tree-shaken to the bar chart: no pie/line/radar code is shipped.
import { Chart, BarController, BarElement, CategoryScale, LinearScale, Tooltip } from 'chart.js';

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip);

const cssVar = (name, fallback) => getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
const compact = new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 });
const full = new Intl.NumberFormat('en-PK');

function draw(canvas) {
    let cfg;
    try {
        cfg = JSON.parse(canvas.dataset.chart);
    } catch {
        return;                                   // the data table below the chart still carries the numbers
    }
    const black = cssVar('--abiha-black', '#101010');
    const gold = cssVar('--abiha-gold', '#C99A35');
    const grid = cssVar('--abiha-border', '#EAEAEA');
    const text = cssVar('--grey-text', '#5f5f5f');
    const n = cfg.values.length;
    const font = { family: 'Inter, system-ui, sans-serif', size: 12 };
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    new Chart(canvas, {
        type: 'bar',
        data: {
            labels: cfg.labels,
            // The latest period is highlighted in brand gold, the rest are brand black.
            datasets: [{ data: cfg.values, backgroundColor: cfg.values.map((_, i) => (i === n - 1 ? gold : black)), borderRadius: 2, maxBarThickness: 40 }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: reduce ? false : { duration: 400 },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: black,
                    titleFont: font,
                    bodyFont: font,
                    displayColors: false,
                    callbacks: {
                        title: (items) => cfg.titles[items[0].dataIndex] ?? items[0].label,
                        label: (ctx) => (cfg.currency ? `${cfg.symbol} ` : '') + full.format(ctx.parsed.y),
                    },
                },
            },
            scales: {
                x: { grid: { display: false }, border: { color: grid }, ticks: { color: text, font, maxRotation: 0, autoSkip: true, maxTicksLimit: n > 16 ? 7 : 12 } },
                y: { beginAtZero: true, grid: { color: grid }, border: { display: false }, ticks: { color: text, font, maxTicksLimit: 5, callback: (v) => compact.format(v) } },
            },
        },
    });
}

document.querySelectorAll('canvas[data-chart]').forEach(draw);
