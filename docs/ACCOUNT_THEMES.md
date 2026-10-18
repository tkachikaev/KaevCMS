# Account themes / Шаблоны кабинета

## English

Account themes are activated independently from public themes.

Bundled account themes render the page's `title` section as the single `h1` in the shared topbar. Account pages and module views should define `@section('title', ...)` and use the optional `description` section for the subtitle below it. Keep actions and page data in the content without repeating the heading or description. Remove empty heading wrappers when there are no remaining actions. The topbar rerenders during Livewire navigation. At mobile widths the title uses a separate row below the menu/profile controls; long titles wrap. Desktop navigation uses a 260px sidebar and the content area grows up to 1480px. The wallet's **Top up balance** link appears only when Donations registers its visible player entry; it opens the existing calculator without enabling payments.

Read the current guide: [CONTENT_THEMES.md](en/CONTENT_THEMES.md).

Use `<x-account-page>` for the content stack and `<x-account-panel>` for primary panels (`flush` for lists with their own row padding, `layout="row"` for balance summaries). `<x-account-panel-header>` provides `summary` and `actions` slots, `<x-account-stat>` renders compact summary cards and `<x-account-empty>` renders an empty state. Actions belong inside the related panel. Main sections do not add a “Back to overview” row; nested pages keep a link to their parent inside the panel. Overview metrics retain their compact tiles. Shared spacing is controlled by `--account-panel-gap`, `--account-panel-padding` and `--account-panel-radius`; both themes use 24px on desktop and 18px spacing/padding with 20px radii on mobile. Module pages use these same components and preserve their own fields, filters and permissions.

Use `.account-tabs` for both navigation links and Livewire tab buttons; keep link `aria-current` and button `aria-selected` states. The player sidebar footer contains only the return-to-site link. Theme names and build versions belong in administration.

## Русский

Шаблоны личного кабинета активируются независимо от публичных шаблонов.

Встроенные темы выводят секцию `title` страницы как единственный `h1` в общей шапке. Страницы ЛК и модули задают `@section('title', ...)`, и необязательную секцию `description` для подзаголовка под ним. В содержимом остаются действия и данные без повторения заголовка и описания. Если после переноса не осталось кнопок, пустая обёртка заголовка удаляется. Шапка обновляется при Livewire-переходах. На телефоне название занимает отдельную строку под кнопками меню и профиля; длинные названия переносятся. На ПК ширина меню составляет 260px, область содержимого расширяется до 1480px. Кнопка **«Пополнить баланс»** в кошельке появляется только при доступном игрокам пункте Donations и ведёт в существующий калькулятор, не включая реальные платежи.

Актуальная инструкция: [CONTENT_THEMES.md](ru/CONTENT_THEMES.md).

Для содержимого используется `<x-account-page>`, для основных панелей — `<x-account-panel>` (`flush` для списков с собственными отступами строк, `layout="row"` для баланса). `<x-account-panel-header>` содержит слоты `summary` и `actions`, `<x-account-stat>` выводит единые карточки сводки, `<x-account-empty>` — пустое состояние. Действия находятся внутри соответствующей панели. В основных разделах нет отдельной строки «Вернуться к обзору»; на вложенных страницах ссылка к родительскому разделу остаётся внутри панели. Метрики обзора сохраняют отдельные плитки. Общие переменные `--account-panel-gap`, `--account-panel-padding` и `--account-panel-radius` задают 24px на ПК, а на телефоне — отступы 18px и скругление 20px. Модули используют те же компоненты, сохраняя свои поля, фильтры и права доступа.

Общий класс `.account-tabs` используется для ссылок и кнопок вкладок Livewire; активное состояние отмечается через `aria-current` у ссылок и `aria-selected` у кнопок. В подвале меню игрока остаётся только возврат на сайт. Название темы и версия сборки доступны в админке.
