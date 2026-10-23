# Administration design canon

Shared rules for typography, numbers, spacing, colours and states in the KaevCMS administration panel. Every new or redesigned screen must follow them. Existing screens are brought in line when they are touched; no separate mass rewrite is required.

The rules live in two places that must agree:

- this document (and the Russian version `docs/ru/DESIGN.md`);
- the CSS variables in `public/assets/admin/css/base.css` (light theme) and `public/assets/admin/css/appearance.css` (dark theme), plus the `App\Support\DisplayFormat` number formatter.

Component styles use only the `--admin-*` variables. Colours, font sizes and spacing written as literal values inside a component are not overridden by the dark theme, and the next screen will repeat them differently.

## Typeface

`--admin-font-sans`: Inter, then the system Segoe UI, Roboto, Helvetica Neue, Arial. The monospace stack `--admin-font-mono` is used only for code, paths, commands and identifiers.

## Type scale

| Role | Variable | Size / line height | Weight | Where |
|---|---|---|---|---|
| Page title | `--admin-text-page` | 26 / 32 | 700 | `h1`, one per page |
| Section title | `--admin-text-title` | 20 / 28 | 600 | `h2` |
| Card title | `--admin-text-title-sm` | 16 / 24 | 600 | `h3` |
| Body | `--admin-text-body` | 14 / 22 | 400 | paragraphs, fields, buttons |
| Secondary | `--admin-text-small` | 13 / 20 | 400 | hints, dense tables |
| Caption | `--admin-text-caption` | 12 / 16 | 500 | badges, metadata, chart axes |
| Metric | `--admin-text-metric` | 28 / 32 | 700 | dashboard figures |

- **12 px is the minimum size.** Smaller text is hard to read, especially in the dark theme.
- **Weights:** only 400, 500, 600 and 700 (`--admin-weight-*`). Intermediate weights (750, 800, 900) are not used.
- No new sizes are introduced. If no role fits, change the canon first.

## Headings

- One `h1` per page. Levels are not skipped: `h2` is followed by `h3`, not `h4`.
- A heading names the content rather than decorating it: "Game servers", not "Command centre".
- Sentence case: only the first word and proper names are capitalised ("Mail delivery", "Login servers", "YooMoney settings"). No full stop at the end.
- A heading sits closer to its own content than to the previous block: the space above it is larger than the space below it.
- A card title fits on one line at the card width. Longer explanations go into a secondary subtitle.

## Text

- Paragraph lines stay under about 72 characters (`max-width: 72ch`).
- Secondary text uses the `--admin-text-muted` colour instead of a size below the scale.
- No clipped abbreviations ("Pend.", "verif.", "acc"): write the word or rephrase.
- Do not mix languages: units follow the interface language ("18 ms" in English, "18 мс" in Russian).

### Capital letters

All-caps is allowed only for an overline above a figure or group: at most three words, `--admin-text-caption` size, weight 600, `0.04em` letter spacing. Headings, buttons, menu items and table cells are never set in capitals.

## Numbers, units, dates

Numbers in the interface go through the formatter, never through `number_format()` with hand-picked separators:

| In a template | Russian | English |
|---|---|---|
| `format_number(184500)` | 184 500 | 184,500 |
| `format_number(1.61, 1)` | 1,6 | 1.6 |
| `format_bytes($bytes)` | 1,2 МБ | 1.2 MB |
| `format_percent(14.2)` | 14,2 % | 14.2% |
| `format_money(184500, 'RUB')` | 184 500 ₽ | ₽184,500 |

In PHP code use `App\Support\DisplayFormat::number()`, `::bytes()`, `::percent()`, `::money()`.

- A **no-break space** separates thousands (Russian) and the number from its unit, so "184 500 ₽" never breaks across lines.
- At most one decimal place; insignificant zeros are dropped: "8 MB", not "8.00 MB". Money is the exception: whole amounts without decimals, otherwise two ("₽99.50").
- **Currency:** Russian puts the sign after the number with a no-break space ("184 500 ₽"). Amounts in different currencies are never added together.
- **Durations:** "12 d 4 h", "5 min", "18 ms".
- **Dates:** "22 November 2026", in tables "22.11.2026 14:05", 24-hour time. Recent events: "5 min ago", "yesterday at 14:05".
- **Ranges** use an en dash without spaces ("10–20"); with spaces when a part contains spaces or version dots ("0.42.4 – 0.50.16").
- Figures that are compared or aligned (tables, metrics, counters) get the `admin-num` class (equal-width digits). Numeric table columns are right-aligned.
- Missing data is shown as "—", not "0", when zero would be untrue.

## Punctuation

- Russian text uses «guillemets» with „low-high“ quotes inside; English text uses “curly quotes”.
- Russian dashes are em dashes with spaces and a no-break space before them; hyphens join compounds ("логин-сервер"). English uses an em dash without spaces or an en dash with spaces, consistently within a screen.
- The ellipsis is the single character "…".
- Button labels are verbs ("Save", "Check now"); field labels are nouns ("Email address"). Buttons, labels and headings have no full stop.

## Spacing and grid

All spacing is a multiple of 4 px (`--admin-space-1` … `--admin-space-12`: 4, 8, 12, 16, 20, 24, 32, 40, 48).

- Card padding is 20 px (`--admin-space-5`), 16 px on phones.
- The gap between cards is 16 or 20 px and the same within one grid.
- Radii: fields and buttons `--admin-radius-control` (8 px), cards `--admin-radius-card` (12 px), nested panels `--admin-radius-inner` (10 px), pill badges `--admin-radius-pill`.

## Colours and states

- Use only semantic variables: `--admin-success*`, `--admin-warning*`, `--admin-danger*`, `--admin-info*`, `--admin-primary*`. Each has a dark-theme counterpart.
- A status is never conveyed by colour alone: a word ("Running", "Unavailable") or a labelled icon is always present.
- Green means fine, amber needs attention, red is broken or losing data, blue is information, links, focus and charts. Animation (a pulsing dot) only for "happening right now".
- The main button is monochrome: near-black in the light theme and near-white in the dark one (`--admin-button-primary`, `--admin-button-primary-hover`, `--admin-button-primary-text`). Buttons are never filled with blue.
- A selected tab or page is a raised neutral chip (`--admin-selected-bg`, `--admin-selected-border`, `--admin-selected-text`), not a blue fill.

## Cards and badges

- A card covers one topic: a title (`h3`), optionally an "All …" link on the right, then content. No more than one level of nested cards.
- A badge is a short word or number in caption size ("Connected", "+12 today"), at most three words.

## Charts

- No third-party libraries: SVG with colours from the variables, correct in both themes.
- Series lines use the chart palette `--admin-chart-1` … `--admin-chart-4` (blue, green, amber, violet), never status colours: red on a chart reads as an error.
- Series with different units (online and registrations) are each scaled to their own maximum; exact values are in the hover tooltip.
- Axis labels and legends use the caption size; grid lines are faint (`--admin-border`).
- Every chart has a text alternative: a summary or table next to it and an `aria-label` stating the takeaway.
- An empty chart is not drawn: show why there is no data yet and when it will appear.

## States

Every data block defines four states:

- **data** — the normal view;
- **empty** — what will appear here and what to do about it ("No players yet. They appear after the first registration.");
- **partial** — show what is available and mark that it is incomplete and why;
- **error** — what happened and what to do, without stack traces.

Dangerous actions (maintenance mode, deletion, resets) are never placed next to statistics and always ask for confirmation.

## Responsive layout

- From 320 px wide without horizontal page scrolling.
- Grids collapse to one column on phones, ordered by importance.
- Tables on narrow screens scroll inside their card, not the whole page.

## Pre-commit checklist

- Sizes, weights, spacing and colours come from the `--admin-*` variables.
- The screen is checked in the light and dark themes and at phone width.
- Numbers are printed with `format_number` / `format_bytes` / `format_percent` / `format_money`.
- Russian and English texts match in meaning; Russian punctuation rules are followed.
- Empty and error states exist.
