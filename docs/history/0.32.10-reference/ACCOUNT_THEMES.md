# Шаблоны личного кабинета

Шаблоны личного кабинета независимы от публичных тем сайта и административной панели.

## Встроенные шаблоны

В поставку входят:

- `luxury` — безопасный полный fallback-шаблон L2 Obsidian Luxury;
- `kaev-aurelia` — светлый Kaev Aurelia Account 1.3.0, визуально согласованный с публичной темой Kaev Aurelia.

Обновление не переключает активный шаблон кабинета автоматически. Выбор выполняется отдельно в разделе **Темы → Кабинет**.

## Каталоги

Каждый пакет использует один slug в двух каталогах:

```text
account-themes/<slug>/
public/account-themes/<slug>/
```

Blade-представления находятся вне `resources/views`, поэтому обновление ядра не должно смешивать штатный код CMS с пользовательским дизайном.

## Минимальная структура

```text
account-themes/example/
├── theme.json
└── views/
    ├── dashboard.blade.php
    └── layouts/
        └── app.blade.php

public/account-themes/example/
└── assets/
    ├── css/
    │   └── app.css
    └── js/
        └── navigation.js
```

Необязательное изображение предпросмотра указывается в `theme.json` и хранится в публичном каталоге темы.

## Манифест

```json
{
  "name": "Example Account Theme",
  "slug": "example",
  "version": "1.0.0",
  "author": "Author",
  "cms_min": "0.19.0",
  "cms_max": "0.99.0",
  "description": "Описание шаблона",
  "preview": "assets/images/preview.webp"
}
```

`cms_max` и `preview` необязательны. Имя каталога обязано совпадать с `slug`.

## Доступные представления

Встроенный `luxury` содержит полный набор:

```text
views/layouts/app.blade.php
views/partials/navigation.blade.php
views/dashboard.blade.php
views/characters/index.blade.php
views/game-accounts/index.blade.php
views/game-accounts/create.blade.php
views/game-accounts/show.blade.php
views/livewire/character-directory.blade.php
views/livewire/game-account-password-form.blade.php
views/components/character-row.blade.php
```

Пользовательский пакет может переопределить только часть файлов. Отсутствующие представления автоматически берутся из `luxury`.

## Подключение ресурсов

В layout используются функции:

```blade
<link rel="stylesheet" href="{{ account_theme_asset('assets/css/app.css') }}" data-navigate-track>
<script src="{{ account_theme_asset('assets/js/navigation.js') }}" defer data-navigate-track data-navigate-once></script>
```

Для переходов внутри кабинета необходимо сохранять `wire:navigate`, а для постоянной оболочки — области `@persist('account-sidebar')` и `@persist('account-topbar')`.

В Kaev Aurelia Account новые верхнеуровневые панели содержимого следует оборачивать классом `account-surface`. Он задаёт общий фон, рамку, тень и адаптивные скругления, чтобы новые разделы не выбивались из визуальной системы кабинета.

## Граница ответственности

Шаблон отвечает только за HTML, CSS и клиентское поведение интерфейса. Он не должен:

- выполнять SQL-запросы;
- изменять игровые аккаунты или персонажей;
- самостоятельно проверять владельца аккаунта;
- начислять монеты или предметы;
- хранить пароли;
- доверять ID, полученным из браузера.

Контроллеры и Livewire-компоненты ядра передают подготовленные данные через namespace `account-theme::*`.

## Установка и активация

1. Скопировать Blade-пакет в `account-themes/<slug>`.
2. Скопировать публичные ресурсы в `public/account-themes/<slug>`.
3. Открыть **Темы → Кабинет**.
4. Проверить статус совместимости.
5. Нажать **Активировать**.

Если активный пакет повреждён, удалён или несовместим с текущей версией CMS, используется безопасный встроенный шаблон `luxury`.

Загрузка ZIP через административную панель пока отсутствует: перед её добавлением требуется отдельная безопасная распаковка с защитой от обхода путей и исполняемых файлов.
