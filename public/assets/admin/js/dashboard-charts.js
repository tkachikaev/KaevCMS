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

            // The first line is the accent line. The others are muted dashed lines, or, in a
            // palette widget (online per game server), solid lines in the chart colours.
            const seriesClass = (line) => {
                const index = widget.series.findIndex((candidate) => candidate.key === line.key);
                if (widget.palette && index > 0) return `dash-series-p dash-tone-${((index - 1) % 4) + 1}`;
                return `dash-series-${Math.min(3, index)}`;
            };

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

            // With many lines (several game servers) a "Servers" menu with checkboxes chooses the
            // visible lines; the legend then lists only those.
            const lineInputs = [];
            if (widget.picker) {
                const lines = html('details', 'dash-lines');
                lines.append(html('summary', '', widget.i18n.lines));
                const list = html('div', 'dash-lines-list');
                widget.series.forEach((line) => {
                    const item = html('label', `dash-lines-item ${seriesClass(line)}`);
                    const input = document.createElement('input');
                    input.type = 'checkbox';
                    input.value = line.key;
                    input.addEventListener('change', () => {
                        if (input.checked) {
                            state.hidden.delete(line.key);
                        } else if (widget.series.length - state.hidden.size > 1) {
                            state.hidden.add(line.key);
                        } else {
                            input.checked = true; // At least one line stays visible.
                            return;
                        }
                        persist();
                        render();
                    });
                    item.append(input, html('i', 'dash-metric-swatch'), document.createTextNode(line.label));
                    list.append(item);
                    lineInputs.push(input);
                });
                lines.append(list);
                card.querySelector('[data-dash-controls]')?.prepend(lines);

                const closeOutside = (event) => {
                    if (lines.open && !(event.target instanceof Node && lines.contains(event.target))) lines.open = false;
                };
                const closeOnEscape = (event) => {
                    if (event.key === 'Escape' && lines.open) {
                        lines.open = false;
                        lines.querySelector('summary')?.focus();
                    }
                };
                document.addEventListener('pointerdown', closeOutside);
                document.addEventListener('keydown', closeOnEscape);
                cleanups.push(() => {
                    document.removeEventListener('pointerdown', closeOutside);
                    document.removeEventListener('keydown', closeOnEscape);
                });
            }

            const picker = card.querySelector('select[data-dash-period]');
            // Document-level listeners of the current drawing, removed before the next one.
            const renderCleanups = [];
            cleanups.push(() => renderCleanups.splice(0).forEach((cleanup) => cleanup()));
            const render = () => {
                renderCleanups.splice(0).forEach((cleanup) => cleanup());
                if (picker) picker.value = state.period;
                lineInputs.forEach((input) => { input.checked = !state.hidden.has(input.value); });

                const { labels, series } = slice();
                const visible = series.filter((line) => !state.hidden.has(line.key));

                // A toggle per line: its line sample, name and total for the selected period.
                legend.replaceChildren(...(widget.picker ? visible : series).map((line) => {
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
                const guide = html('span', 'dash-chart-guide');
                guide.hidden = true;
                plot.prepend(guide);
                const tooltip = html('div', 'dash-chart-tooltip');
                tooltip.hidden = true;
                plot.append(tooltip);

                // The details sit at the top of the chart beside a vertical guide, so they never
                // cover the pointer or the points they describe. A click pins them to a point;
                // another click (or Escape) releases them.
                let shownIndex = null;
                let side = 'right';
                let pinned = false;
                const hide = () => {
                    shownIndex = null;
                    tooltip.hidden = true;
                    tooltip.classList.remove('is-placed');
                    guide.hidden = true;
                    markers.forEach((dot) => { dot.hidden = true; });
                };
                const setPinned = (value) => {
                    pinned = value;
                    plot.classList.toggle('is-pinned', pinned);
                    tooltip.classList.toggle('is-pinned', pinned);
                };
                const place = (index, box) => {
                    if (index !== shownIndex) {
                        markers.forEach((dot, line) => {
                            const point = geometry[line][index];
                            dot.hidden = point === null;
                            if (point) {
                                dot.style.left = `${(point.x / WIDTH) * 100}%`;
                                dot.style.top = `${(point.y / HEIGHT) * 100}%`;
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
                        shownIndex = index;
                    }
                    const x = (xFor(index) / WIDTH) * box.width;
                    guide.style.left = `${x}px`;
                    guide.hidden = false;
                    tooltip.hidden = false;

                    // Stay on the current side while there is room; switch only when the other
                    // side has room. In a chart too narrow for either side the details stay
                    // put (clamped to the chart) instead of flipping on every move.
                    const gap = 12;
                    const width = tooltip.offsetWidth;
                    const fitsRight = x + gap + width <= box.width;
                    const fitsLeft = x - gap - width >= 0;
                    if (side === 'right' && !fitsRight && fitsLeft) side = 'left';
                    else if (side === 'left' && !fitsLeft && fitsRight) side = 'right';
                    const left = side === 'right' ? x + gap : x - gap - width;
                    tooltip.style.left = `${Math.min(box.width - width, Math.max(0, left))}px`;
                    // The first placement is instant; later ones glide (see .is-placed).
                    window.requestAnimationFrame(() => tooltip.classList.add('is-placed'));
                };
                // Height of a line, in pixels, at a horizontal position: straight between its two
                // neighbouring points (null where the line has a gap).
                const lineYAt = (points, ratio, box) => {
                    const position = ratio * (labels.length - 1);
                    const left = points[Math.floor(position)];
                    const right = points[Math.ceil(position)];
                    if (!left && !right) return null;
                    if (!left || !right) return ((left || right).y / HEIGHT) * box.height;
                    const y = left.y + (right.y - left.y) * (position - Math.floor(position));
                    return (y / HEIGHT) * box.height;
                };
                // The point under the pointer, or null when the pointer is away from every line.
                // Details appear within `reach` of a line; once shown they stay within a wider
                // reach, so a small wobble around a jagged line does not make them blink.
                const pointedIndex = (event) => {
                    const box = plot.getBoundingClientRect();
                    if (box.width === 0) return null;
                    const ratio = Math.min(1, Math.max(0, (event.clientX - box.left) / box.width));
                    const touch = event.pointerType === 'touch';
                    const reach = shownIndex === null ? (touch ? 28 : 16) : (touch ? 40 : 32);
                    const pointerY = event.clientY - box.top;
                    const nearLine = geometry.some((points) => {
                        const y = lineYAt(points, ratio, box);
                        return y !== null && Math.abs(y - pointerY) <= reach;
                    });
                    return nearLine ? Math.round(ratio * (labels.length - 1)) : null;
                };
                const follow = (event) => {
                    if (pinned) return;
                    const index = pointedIndex(event);
                    plot.classList.toggle('is-pointing', index !== null);
                    if (index === null) {
                        hide();
                        return;
                    }
                    place(index, plot.getBoundingClientRect());
                };
                plot.addEventListener('pointermove', follow);
                plot.addEventListener('pointerdown', follow);
                plot.addEventListener('pointerleave', () => {
                    plot.classList.remove('is-pointing');
                    if (!pinned) hide();
                });
                plot.addEventListener('click', (event) => {
                    if (pinned) {
                        setPinned(false);
                        follow(event);
                    } else if (shownIndex !== null) {
                        setPinned(true);
                    }
                });
                const release = (event) => {
                    if (!pinned) return;
                    if (event.type === 'keydown' ? event.key === 'Escape' : !plot.contains(event.target)) {
                        setPinned(false);
                        hide();
                    }
                };
                document.addEventListener('keydown', release);
                document.addEventListener('pointerdown', release);
                renderCleanups.push(() => {
                    document.removeEventListener('keydown', release);
                    document.removeEventListener('pointerdown', release);
                });

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
