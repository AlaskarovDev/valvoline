import ApexCharts from 'apexcharts';

/* Formatlar: 1 234,56 ₼ · 35,5 % (Intl-dən asılı deyil — bəzi brauzerlərdə az lokalı yoxdur) */
const t = (key, fallback) => (window.i18n && window.i18n[key]) || fallback;
const THIN = ' ';
function group(value, digits) {
    const fixed = Math.abs(value).toFixed(digits);
    const [int, frac] = fixed.split('.');
    const grouped = int.replace(/\B(?=(\d{3})+(?!\d))/g, THIN);
    return (value < 0 && Number(fixed) !== 0 ? '−' : '') + grouped + (frac ? ',' + frac : '');
}

const fmt = {
    money: (v) => (v == null ? '—' : group(Number(v), 2) + ' ₼'),
    moneyShort: (v) => {
        if (v == null) return '—';
        const a = Math.abs(v);
        if (a >= 1e6) return group(v / 1e6, 1) + ' ' + t('million', 'mln');
        if (a >= 1e3) return group(v / 1e3, 0) + ' ' + t('thousand', 'min');
        return group(Number(v), 0);
    },
    number: (v, digits = 0) => (v == null ? '—' : group(Number(v), digits)),
    pct: (v) => (v == null ? '—' : group(v * 100, 1) + ' %'),
};
window.fmt = fmt;

const cssVar = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
const palette = () => ['--chart-1', '--chart-2', '--chart-3', '--chart-4', '--chart-5', '--chart-6'].map(cssVar);

const charts = new Map();

function buildOptions(spec, width = 1000) {
    const narrow = width < 560;
    const isDark = document.documentElement.classList.contains('dark');
    const text = cssVar('--muted');
    const grid = cssVar('--border');
    const format = fmt[spec.format || 'money'] || fmt.money;
    const axisFormat = spec.format === 'pct' ? fmt.pct : spec.format === 'number' ? fmt.number : fmt.moneyShort;
    const colors = spec.colors ? spec.colors.map((c) => (c.startsWith('--') ? cssVar(c) : c)) : palette();

    const options = {
        chart: {
            type: spec.type || 'bar',
            height: spec.height || 300,
            fontFamily: 'Inter, ui-sans-serif, system-ui',
            foreColor: text,
            toolbar: { show: false },
            zoom: { enabled: false },
            animations: { enabled: !window.matchMedia('(prefers-reduced-motion: reduce)').matches, speed: 300 },
            background: 'transparent',
            stacked: !!spec.stacked,
            events: spec.links
                ? {
                      dataPointSelection: (e, ctx, cfg) => {
                          const url = spec.links[cfg.dataPointIndex];
                          if (url) window.location.href = url;
                      },
                  }
                : {},
        },
        theme: { mode: isDark ? 'dark' : 'light' },
        colors,
        series: spec.series,
        grid: { borderColor: grid, strokeDashArray: 3, padding: { left: 4, right: 8 } },
        dataLabels: { enabled: false },
        legend: { position: 'top', horizontalAlign: 'left', fontSize: '12px', markers: { size: 6 }, itemMargin: { horizontal: 10 } },
        stroke: { width: spec.type === 'line' || spec.type === 'area' ? 2.5 : 0, curve: 'smooth' },
        tooltip: { theme: isDark ? 'dark' : 'light', y: { formatter: (v) => format(v) } },
        plotOptions: {
            bar: { borderRadius: 3, columnWidth: spec.columnWidth || '60%', horizontal: !!spec.horizontal, barHeight: '70%' },
            pie: { donut: { size: '70%', labels: { show: true, name: { fontSize: '12px' }, total: { show: true, label: spec.totalLabel || t('total', 'Cəmi'), fontSize: '12px', formatter: (w) => fmt.moneyShort(w.globals.seriesTotals.reduce((a, b) => a + b, 0)) + ' ₼' }, value: { fontSize: '17px', fontWeight: 700, formatter: (v) => fmt.moneyShort(Number(v)) + ' ₼' } } } },
        },
        states: { hover: { filter: { type: 'darken', value: 0.9 } } },
    };

    if (spec.type === 'donut' || spec.type === 'pie') {
        options.labels = spec.labels;
        options.legend.position = 'bottom';
        options.stroke = { width: 2, colors: [cssVar('--surface')] };
    } else if (spec.type === 'scatter') {
        options.xaxis = { type: 'numeric', title: { text: spec.xTitle || '' }, labels: { formatter: (v) => fmt.moneyShort(v) } };
        options.yaxis = { title: { text: spec.yTitle || '' }, labels: { formatter: (v) => fmt.pct(v) } };
        options.markers = { size: 6, strokeWidth: 0, fillOpacity: 0.75 };
        options.tooltip = {
            custom: ({ seriesIndex, dataPointIndex, w }) => {
                const p = w.config.series[seriesIndex].data[dataPointIndex];
                return `<div style="padding:8px 10px;font-size:12px"><b>${p.name || ''}</b><br>Satış: ${fmt.money(p.x)}<br>Marja: ${fmt.pct(p.y)}</div>`;
            },
        };
    } else {
        options.xaxis = {
            categories: spec.categories,
            labels: { rotate: -45, rotateAlways: false, hideOverlappingLabels: true, trim: !spec.horizontal, maxHeight: 90, formatter: spec.horizontal ? (v) => axisFormat(Number(v)) : undefined },
            axisBorder: { color: grid },
            axisTicks: { color: grid },
        };
        const yaxis = { labels: { formatter: (v) => (spec.horizontal ? v : axisFormat(v)), maxWidth: spec.horizontal ? 220 : 90 } };
        if (spec.dualAxis) {
            const first = spec.series.slice(0, -1).map((s) => s.name);
            const last = spec.series[spec.series.length - 1].name;
            options.yaxis = [
                { ...yaxis, seriesName: first },
                { opposite: true, seriesName: [last], labels: { formatter: (v) => (spec.secondFormat === 'pct' ? fmt.pct(v) : spec.secondFormat === 'money' ? fmt.money(v) : axisFormat(v)) } },
            ];
            options.tooltip.y = { formatter: (v, { seriesIndex }) => (seriesIndex === spec.series.length - 1 && spec.secondFormat === 'pct' ? fmt.pct(v) : format(v)) };
        } else {
            options.yaxis = yaxis;
        }
        if (spec.type === 'area') {
            options.fill = { type: 'gradient', gradient: { opacityFrom: 0.35, opacityTo: 0.02 } };
        }
        if (spec.strokeWidths) {
            options.stroke = { width: spec.strokeWidths, curve: 'smooth' };
        }
        if (spec.markers) {
            options.markers = { size: 3, strokeWidth: 0 };
        }
        if (spec.distributed) {
            options.plotOptions.bar.distributed = true;
            options.legend = { show: false };
        }
        if (spec.barLabels) {
            options.plotOptions.bar.dataLabels = { position: 'top' };
            options.dataLabels = {
                enabled: true,
                textAnchor: 'start',
                offsetX: 8,
                formatter: (v, { dataPointIndex }) => spec.barLabels[dataPointIndex],
                style: { fontSize: '11px', fontWeight: 600, colors: [cssVar('--text')] },
                background: { enabled: false },
                dropShadow: { enabled: false },
            };
            options.grid.padding = { ...options.grid.padding, right: 16 };
            const top = Math.max(...spec.series[0].data);
            if (top > 0) options.xaxis.max = top * 1.1; // marja yazısı üçün yer
            options.tooltip.y = { formatter: (v, { dataPointIndex }) => `${format(v)} · ${t('margin', 'marja')} ${spec.barLabels[dataPointIndex]}` };
        }
    }

    if (narrow) applyNarrow(options, spec, width);

    return options;
}

/* Telefon eni: qısa etiketlər, kiçik şrift, oxlara az yer — qrafik sahəsi böyüsün */
function applyNarrow(options, spec, width) {
    const small = { fontSize: '10px' };
    options.legend = { ...options.legend, fontSize: '11px', itemMargin: { horizontal: 6, vertical: 2 } };
    if (spec.type === 'donut' || spec.type === 'pie' || spec.type === 'scatter') return;
    if (spec.horizontal) {
        const yl = Array.isArray(options.yaxis) ? options.yaxis[0] : options.yaxis;
        yl.labels = { ...yl.labels, maxWidth: Math.round(width * 0.36), style: small };
        options.xaxis.tickAmount = 3;
        options.xaxis.labels = { ...options.xaxis.labels, style: small };
        if (options.dataLabels?.enabled) options.dataLabels.style.fontSize = '10px';
        return;
    }
    const many = (spec.categories || []).length > 8;
    options.xaxis.labels = {
        ...options.xaxis.labels,
        rotate: -90,
        rotateAlways: many,
        style: { fontSize: '9px' },
        formatter: many ? (v) => (typeof v === 'string' ? v.replace(/^(\S+) \d{2}(\d{2})$/, '$1 $2') : v) : options.xaxis.labels.formatter,
    };
    const axes = Array.isArray(options.yaxis) ? options.yaxis : [options.yaxis];
    axes.forEach((a) => (a.labels = { ...a.labels, maxWidth: 56, style: small }));
    options.grid.padding = { left: 0, right: 4 };
    if (options.plotOptions?.bar) options.plotOptions.bar.columnWidth = '72%';
}

function renderChart(el) {
    const spec = JSON.parse(el.dataset.chart);
    if (charts.has(el)) charts.get(el).destroy();
    const chart = new ApexCharts(el, buildOptions(spec, el.clientWidth || window.innerWidth));
    chart.render();
    charts.set(el, chart);
}

function renderAll(root = document) {
    root.querySelectorAll('[data-chart]').forEach(renderChart);
}

/* Tema: localStorage + sistem seçimi */
function applyTheme(theme) {
    const dark = theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.classList.toggle('dark', dark);
}

window.toggleTheme = () => {
    const next = document.documentElement.classList.contains('dark') ? 'light' : 'dark';
    try {
        localStorage.setItem('theme', next);
    } catch (e) {}
    applyTheme(next);
    renderAll();
};

document.addEventListener('DOMContentLoaded', () => renderAll());
document.addEventListener('livewire:navigated', () => renderAll());

/* Ekran dönəndə / eni dəyişəndə (telefon ↔ geniş) qrafikləri yenidən qur */
let lastNarrow = window.innerWidth < 560;
let resizeTimer;
window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
        const narrow = window.innerWidth < 560;
        if (narrow !== lastNarrow) {
            lastNarrow = narrow;
            renderAll();
        }
    }, 250);
});

/* Detal popup: <a data-modal href="…"> → məzmun serverdən (X-Modal başlığı ilə) yüklənib <dialog>-da göstərilir */
document.addEventListener('click', async (event) => {
    const link = event.target.closest('a[data-modal]');
    const dialog = document.getElementById('detail-modal');
    if (!link || !dialog || event.ctrlKey || event.metaKey || event.shiftKey) return;
    event.preventDefault();
    const body = dialog.querySelector('[data-modal-body]');
    dialog.querySelector('[data-modal-open-page]').href = link.href;
    body.innerHTML = `<div class="p-10 text-center text-sm text-muted">${t('loading', 'Yüklənir…')}</div>`;
    if (!dialog.open) dialog.showModal();
    try {
        const response = await fetch(link.href, { headers: { 'X-Modal': '1', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        if (!response.ok) throw new Error(response.status);
        body.innerHTML = await response.text();
    } catch (e) {
        body.innerHTML = `<div class="p-10 text-center text-sm neg">${t('loadError', 'Error')}</div>`;
    }
});
document.addEventListener('click', (event) => {
    const dialog = document.getElementById('detail-modal');
    if (!dialog || !dialog.open) return;
    if (event.target.closest('[data-modal-close]') || event.target === dialog) dialog.close();
});
