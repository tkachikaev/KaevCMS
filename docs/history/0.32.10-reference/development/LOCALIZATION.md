# Локализация ядра, тем и модулей

KaevCMS использует штатный переводчик Laravel и JSON-файлы переводов. Коды локалей хранятся как строки длиной до 10 символов и не ограничены перечислением `ru`/`en`.

## Структура языкового пакета

```text
lang/<locale>/language.php
lang/<locale>.json
lang/<locale>/items.php    # optional game-item catalog
```

Пример `lang/de/language.php`:

```php
<?php

return [
    'code' => 'de',
    'name' => 'German',
    'native_name' => 'Deutsch',
    'direction' => 'ltr',
    'fallback' => 'en',
    'author' => 'Language pack author',
];
```

Поддерживаемый код:

```text
xx
xxx
xx-YY
```

Примеры: `de`, `uk`, `pt-BR`. Имя каталога и `code` должны совпадать после нормализации. `direction` принимает `ltr` или `rtl`.

`lang/<locale>.json` должен содержать переводы строк ядра:

```json
{
  "Home": "Startseite",
  "News": "Neuigkeiten"
}
```


Английский JSON используется как эталон для расчёта процента заполнения.

Необязательный `lang/<locale>/items.php` содержит названия игровых предметов по `item_id`. Он не влияет на процент заполнения системного перевода. Если файла или конкретного ID нет, KaevCMS проверяет настроенный резервный и основной языки. Формат описан в [`docs/GAME_ITEMS.md`](../GAME_ITEMS.md).

## Использование в PHP и Blade

```php
$message = __('News saved.');
```

```blade
<h1>{{ __('News') }}</h1>
```

Не добавляйте новые видимые строки напрямую в контроллеры и шаблоны. После добавления ключа обновите как минимум `lang/ru.json` и `lang/en.json`.

## Маршруты публичного сайта

Явные локализованные маршруты имеют префикс `localized.`:

```text
localized.home
localized.news.index
localized.news.show
localized.login
localized.account
```

В теме используйте помощники ядра:

```blade
<a href="{{ public_route('news.index') }}">{{ __('News') }}</a>
<a href="{{ news_url($newsItem) }}">{{ $newsItem->titleFor() }}</a>
```

`public_route()` сохраняет текущий языковой префикс. `news_url()` и `page_url()` используют локаль и slug фактически доступного перевода.

Публичный контроллер не показывает резервный перевод под адресом отсутствующей локали. Неверный slug текущего языка перенаправляется кодом `301`, отсутствующий перевод — кодом `302` на фактическую резервную локаль. Макет получает `canonicalUrl`, `alternateUrls` и `defaultAlternateUrl` для вывода `canonical`, `hreflang` и `x-default`.

## Переводимые модели

Новости хранят общие данные в `news`, а текст — в `news_translations`:

```text
news_id + locale
```

Используйте:

```php
$news->titleFor();
$news->excerptFor();
$news->bodyFor();
$news->slugFor();
$news->safeBodyHtml();
```

Названия игровых серверов хранятся в `game_server_translations` и читаются через `GameServer::nameFor()` или `GameServerSettings`.

## Темы

Корневой макет темы должен указывать язык и направление:

```blade
<html lang="{{ app()->getLocale() }}" dir="{{ locale_direction() }}">
```

Тема не должна самостоятельно читать таблицы переводов. Используйте `__()`, локализованные методы моделей и помощники ядра.

## Будущие модули

Модуль должен поставлять собственные переводы и регистрировать namespace Laravel, например:

```text
modules/Donations/lang/ru/messages.php
modules/Donations/lang/en/messages.php
```

Использование:

```php
__('donations::messages.payment_successful')
```

При отсутствии перевода должен применяться настроенный резервный язык. Модуль не должен менять список языков ядра или создавать отдельную систему выбора локали.

## Безопасность

- Не добавляйте загрузку языковых ZIP-пакетов без проверки путей, подписей и содержимого.
- `language.php` выполняется через `require`; пакет должен поступать из доверенного источника.
- Не разрешайте ввод Blade, PHP или HTML в системные переводы через админку.
- Коды локалей необходимо проверять через `LanguageManager::normalizeCode()`.
