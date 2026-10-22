(() => {
    'use strict';

    // Dashboard chart widgets (design canon: charts). One widget per metric; its lines share
    // a unit and a scale. Each widget keeps its own period and visible lines. Missing values
    // (null) break a line instead of dropping it to zero.
    // Markup carries no inline styles because the admin CSP forbids them; positions are set
    // through the CSSOM here.
    const SVG = 'http://www.w3.org/2000/svg';
    const WIDTH = 400;
    const HEIGHT = 140;
    const TOP = 10;
    const BOTTOM = 134;
    const STORAGE_KEY = 'kaevcms.dashboard.widgets';
    // Period → the server's series it reads (days, hours, five-minute steps) and how many
    // of its last points it shows.
    const PERIODS = {
        30: { scale: 'days', points: 30 },
        7: { scale: 'days', points: 7 },
        24: { scale: 'hours', points: 24 },
        '5h': { scale: 'minutes', points: 60 },
        '1h': { scale: 'minutes', points: 12 },
    };

    const svg = (name, attributes = {}) => {
        const element = document.createElementNS(SVG, name);
        Object.entries(attributes).forEach(([key, value]) => element.setAttribute(key, String(value)));
        return element;
    };

    const html = (name, className, text) => {
        const element = document.createElement(name);
        if (className) element.className = className;
        if (text !== undefined) element.textContent = text;
        return element;
    };

    const niceMax = (value) => {
        if (!(value > 0)) return 1;
        const magnitude = 10 ** Math.floor(Math.log10(value));
        return [1, 2, 2.5, 5, 10].map((step) => step * magnitude).find((step) => value <= step) ?? 10 * magnitude;
    };

    // Monotone cubic (Fritsch–Carlson): smooth, never above or below the actual values.
    const smoothPath = (points) => {
        if (points.length === 1) {
            const [{ x, y }] = points;
            return `M${x - 3},${y} L${x + 3},${y}`;
        }
        const slopes = points.slice(0, -1).map((point, index) => {
            const next = points[index + 1];
            return next.x === point.x ? 0 : (next.y - point.y) / (next.x - point.x);
        });
        const tangents = points.map((_, index) => {
            if (index === 0) return slopes[0];
            if (index === points.length - 1) return slopes[slopes.length - 1];
            return slopes[index - 1] * slopes[index] <= 0 ? 0 : (slopes[index - 1] + slopes[index]) / 2;
        });
        slopes.forEach((slope, index) => {
            if (slope === 0) {
                tangents[index] = 0;
                tangents[index + 1] = 0;
                return;
            }
            const a = tangents[index] / slope;
            const b = tangents[index + 1] / slope;
            const sum = a * a + b * b;
            if (sum > 9) {
                const scale = 3 / Math.sqrt(sum);
                tangents[index] = scale * a * slope;
                tangents[index + 1] = scale * b * slope;
            }
        });

        let path = `M${points[0].x},${points[0].y}`;
        for (let index = 0; index < points.length - 1; index += 1) {
            const from = points[index];
            const to = points[index + 1];
            const third = (to.x - from.x) / 3;
            path += ` C${from.x + third},${from.y + tangents[index] * third} ${to.x - third},${to.y - tangents[index + 1] * third} ${to.x},${to.y}`;
        }
        return path;
    };

    const segments = (points) => points.reduce((result, point) => {
        if (point === null) {
            if (result.at(-1).length) result.push([]);
            return result;
        }
        result.at(-1).push(point);
        return result;
    }, [[]]).filter((segment) => segment.length > 0);

    const readStorage = () => {
        try {
            return JSON.parse(window.localStorage.getItem(STORAGE_KEY) || '{}') || {};
        } catch {
            return {};
        }
    };

    const writeStorage = (key, value) => {
        try {
            const all = readStorage();
            all[key] = value;
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(all));
        } catch {
            // A private window may refuse storage; the widget still works.
        }
    };

    const initialize = () => {
        const cleanups = [];
        const locale = document.documentElement.lang || 'en';
        const numberFormat = new Intl.NumberFormat(locale, { maximumFractionDigits: 1 });

        document.querySelectorAll('[data-dash-widget]').forEach((card) => {
            const source = card.querySelector('[data-dash-widget-data]');
            const legend = card.querySelector('[data-dash-legend]');
            const target = card.querySelector('[data-dash-chart]');
            if (!source || !(legend instanceof HTMLElement) || !(target instanceof HTMLElement)) return;

            let widget;
            try {
                widget = JSON.parse(source.textContent || '{}');
            } catch {
                return;
            }
            if (!Array.isArray(widget.series) || widget.series.length === 0) return;

            const format = (value) => {
                if (value === null || value === undefined) return widget.i18n.no_data;
                if (widget.format === 'money') {
                    const whole = Math.abs(value - Math.round(value)) < 0.005;
                    return new Intl.NumberFormat(locale, {
                        style: 'currency',
                        currency: widget.currency,
                        currencyDisplay: 'narrowSymbol',
                        minimumFractionDigits: whole ? 0 : 2,
                        maximumFractionDigits: whole ? 0 : 2,
                    }).format(value);
                }
                return numberFormat.format(value);
            };

            // The first line is the accent line, the others are muted dashed lines.
            const seriesClass = (line) => `dash-series-${Math.min(3, widget.series.findIndex((candidate) => candidate.key === line.key))}`;

            const saved = readStorage()[widget.key] || {};
            const state = {
                period: Object.hasOwn(PERIODS, saved.period) ? saved.period : '30',
                hidden: new Set(Array.isArray(saved.hidden) ? saved.hidden.filter((key) => widget.series.some((series) => series.key === key)) : widget.series.filter((series) => !series.visible).map((series) => series.key)),
            };
            if (state.hidden.size >= widget.series.length) state.hidden.clear();
            const persist = () => writeStorage(widget.key, { period: state.period, hidden: [...state.hidden] });

            const slice = () => {
                const { scale, points } = PERIODS[state.period];
                const labels = widget.labels[scale];
                const start = Math.max(0, labels.length - points);
                return {
                    labels: labels.slice(start),
                    series: widget.series.map((series) => ({ ...series, values: series[scale].slice(start) })),
                };
            };

            const summarize = (series) => {
                const present = series.values.filter((value) => value !== null);
                if (present.length === 0) return null;
                if (series.summary === 'max') return Math.max(...present);
                if (series.summary === 'avg') return Math.round(present.reduce((sum, value) => sum + value, 0) / present.length);
                return present.reduce((sum, value) => sum + value, 0);
            };

            const picker = card.querySelector('select[data-dash-period]');
            const render = () => {
                if (picker) picker.value = state.period;

                const { labels, series } = slice();
                const visible = series.filter((line) => !state.hidden.has(line.key));

                // A toggle per line: its line sample, name and total for the selected period.
                legend.replaceChildren(...series.map((line) => {
                    const toggle = html('button', `dash-metric ${seriesClass(line)}`);
                    toggle.type = 'button';
                    toggle.setAttribute('aria-pressed', state.hidden.has(line.key) ? 'false' : 'true');
                    toggle.disabled = series.length === 1;
                    toggle.addEventListener('click', () => {
                        if (state.hidden.has(line.key)) {
                            state.hidden.delete(line.key);
                        } else if (series.length - state.hidden.size > 1) {
                            state.hidden.add(line.key);
                        } else {
                            return; // At least one line stays visible.
                        }
                        persist();
                        render();
                    });
                    const name = html('span', 'dash-metric-name');
                    name.append(html('i', 'dash-metric-swatch'), document.createTextNode(line.label));
                    toggle.append(name, html('strong', 'dash-metric-value admin-num', format(summarize(line))));
                    return toggle;
                }));

                if (!visible.some((line) => line.values.some((value) => value !== null))) {
                    target.replaceChildren(html('p', 'dash-empty', widget.i18n.empty));
                    return;
                }

                const max = niceMax(Math.max(0, ...visible.flatMap((line) => line.values.filter((value) => value !== null))) * 1.05);
                const xFor = (index) => (labels.length === 1 ? WIDTH / 2 : (index / (labels.length - 1)) * WIDTH);
                const yFor = (value) => BOTTOM - (value / max) * (BOTTOM - TOP);
                const geometry = visible.map((line) => line.values.map((value, index) => (value === null ? null : { x: xFor(index), y: yFor(value) })));

                const plot = html('div', 'dash-widget-plot');
                const canvas = svg('svg', {
                    viewBox: `0 0 ${WIDTH} ${HEIGHT}`,
                    preserveAspectRatio: 'none',
                    role: 'img',
                    'aria-label': `${widget.title}: ${visible.map((line) => `${line.label} ${format(summarize(line))}`).join(', ')}`,
                });
                const defs = svg('defs');
                canvas.append(defs);
                [TOP, (TOP + BOTTOM) / 2].forEach((y) => canvas.append(svg('line', { class: 'dash-chart-grid', x1: 0, x2: WIDTH, y1: y, y2: y })));
                canvas.append(svg('line', { class: 'dash-chart-axis', x1: 0, x2: WIDTH, y1: BOTTOM + 0.5, y2: BOTTOM + 0.5 }));

                visible.forEach((line, index) => {
                    const lineClass = seriesClass(line);
                    // Only the accent line gets the faint fill; a second fill would muddy it.
                    const filled = lineClass === 'dash-series-0';
                    const gradientId = `dash-gradient-${widget.key}-${line.key}`;
                    if (filled) {
                        const gradient = svg('linearGradient', { id: gradientId, x1: 0, x2: 0, y1: 0, y2: 1 });
                        gradient.append(
                            svg('stop', { offset: '0%', class: `dash-gradient-start ${lineClass}` }),
                            svg('stop', { offset: '100%', class: `dash-gradient-end ${lineClass}` }),
                        );
                        defs.append(gradient);
                    }
                    segments(geometry[index]).forEach((segment) => {
                        const path = smoothPath(segment);
                        if (filled && segment.length > 1) {
                            canvas.append(svg('path', { class: 'dash-chart-area', d: `${path} L${segment.at(-1).x},${BOTTOM} L${segment[0].x},${BOTTOM} Z`, fill: `url(#${gradientId})` }));
                        }
                        canvas.append(svg('path', { class: `dash-chart-line ${lineClass}`, d: path }));
                    });
                });
                plot.append(canvas);

                const topLabel = html('span', 'dash-widget-max admin-num', format(max));
                topLabel.setAttribute('aria-hidden', 'true');
                plot.append(topLabel);

                const markers = visible.map((line) => {
                    const dot = html('span', `dash-chart-dot ${seriesClass(line)}`);
                    dot.hidden = true;
                    plot.append(dot);
                    return dot;
                });
                const tooltip = html('div', 'dash-chart-tooltip');
                tooltip.hidden = true;
                plot.append(tooltip);

                const hide = () => {
                    tooltip.hidden = true;
                    markers.forEach((dot) => { dot.hidden = true; });
                };
                const show = (event) => {
                    const box = plot.getBoundingClientRect();
                    if (box.width === 0) return;
                    const ratio = Math.min(1, Math.max(0, (event.clientX - box.left) / box.width));
                    const index = Math.round(ratio * (labels.length - 1));
                    let highest = HEIGHT;
                    markers.forEach((dot, line) => {
                        const point = geometry[line][index];
                        dot.hidden = point === null;
                        if (point) {
                            dot.style.left = `${(point.x / WIDTH) * 100}%`;
                            dot.style.top = `${(point.y / HEIGHT) * 100}%`;
                            highest = Math.min(highest, point.y);
                        }
                    });

                    tooltip.replaceChildren(html('strong', '', labels[index].long));
                    visible.forEach((line) => {
                        const row = html('div', `dash-chart-tooltip-row ${seriesClass(line)}`);
                        const name = html('span');
                        name.append(html('i'), document.createTextNode(line.label));
                        row.append(name, html('b', '', format(line.values[index])));
                        tooltip.append(row);
                    });
                    tooltip.hidden = false;

                    const x = (xFor(index) / WIDTH) * box.width;
                    const y = (highest / HEIGHT) * box.height;
                    const width = tooltip.offsetWidth;
                    const height = tooltip.offsetHeight;
                    tooltip.style.left = `${Math.min(box.width - width, Math.max(0, x - width / 2))}px`;
                    tooltip.style.top = `${y - height - 10 >= 0 ? y - height - 10 : Math.min(box.height - height, y + 10)}px`;
                };
                plot.addEventListener('pointermove', show);
                plot.addEventListener('pointerdown', show);
                plot.addEventListener('pointerleave', hide);

                const axis = html('div', 'dash-chart-axis-labels');
                axis.setAttribute('aria-hidden', 'true');
                axis.append(
                    html('span', '', labels[0].short),
                    html('span', '', labels[Math.floor((labels.length - 1) / 2)].short),
                    html('span', '', PERIODS[state.period].scale === 'days' ? widget.i18n.today : widget.i18n.now),
                );

                target.replaceChildren(plot, axis);
            };

            const onChange = () => {
                if (!picker || !Object.hasOwn(PERIODS, picker.value)) return;
                state.period = picker.value;
                persist();
                render();
            };
            picker?.addEventListener('change', onChange);
            cleanups.push(() => picker?.removeEventListener('change', onChange));
            render();
        });

        return () => cleanups.forEach((cleanup) => cleanup());
    };

    if (window.KaevCMSAdmin?.registerPage) {
        window.KaevCMSAdmin.registerPage('dashboard-charts', initialize);
    } else {
        initialize();
    }
})();
