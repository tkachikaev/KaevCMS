# Разработка и проверки качества

## Обязательные проверки

```powershell
.\deployment\windows\quality.ps1
.\deployment\windows\browser-quality.ps1
```

Офлайн-проверка запускает регрессии Windows Update, сетевой политики Composer, Web Installer, shared-hosting, Web Update, единого release builder, Composer validation, Pint, PHPStan, PHPUnit и route cache. Browser quality использует зависимости, установленные через `browser-setup.ps1`, и запускает Playwright.

Windows quality поддерживает Windows PowerShell 5.1 и PowerShell 7; CI проверяет оба варианта. Пустое значение переменной окружения в общих deployment-функциях означает её удаление, включая восстановление сетевой политики Composer после проверки.

Правила `.gitattributes` сохраняют LF для `composer.lock`, CSS, JavaScript, shell-скриптов и PHPStan-конфигурации; PowerShell-скрипты используют CRLF. Это часть воспроизводимой сборки: контрольные суммы сравнивают фактические байты, поэтому не меняйте `composer.lock` или ожидаемый SHA256 только из-за преобразования переводов строк в локальной копии Git.

Нельзя ослаблять регрессионный тест ради зелёного результата. Для каждого исправления добавляйте тест, особенно для установки, обновлений, аутентификации, границ баз данных и сбоев внешнего GameServer.

## Выпуск версии

`release.json` — основной контракт релиза. `VERSION` остаётся совместимым зеркалом и обязан совпадать с ним. Контракт задаёт текущую и предыдущую версии, пути apply-скриптов, отпечатки зависимостей, нижнюю границу восстановления и базу кумулятивного пакета. `deployment/release-files.json` перечисляет обязательные файлы после распаковки, а `deployment/windows/update-contract.json` задаёт runtime-каталоги, защищённые значения окружения и порядок этапов обновления.

- Меняйте историю релиза только через проверяемые контракты, затем обновляйте зеркало `VERSION`, `README.md` и `CHANGELOG.md`.
- Не зашивайте текущую или предыдущую версию в текстовые фрагменты регрессионных тестов.
- Выпускайте full, patch, Web Update и SHA256.
- Проверяйте целостность ZIP и переносимые разделители путей.
- Подтверждайте, что предыдущий релиз плюс патч совпадает с полным новым релизом.
- Явно фиксируйте изменения миграций и Composer/npm lock-файлов.

Собирайте все официальные артефакты одной командой:

```powershell
.\deployment\windows\build-release.ps1 `
    -PreviousFullArchive "C:\Releases\KaevCMS-0.44.8-full.zip" `
    -OutputDirectory "C:\Releases\0.44.14"
```

Сборщик проверяет, что предыдущий full плюс patch точно совпадает с новым деревом, cumulative начинается с baseline `0.42.4`, runtime-файлы не попадают в архивы, а SHA256 соответствует результату. GitHub Actions отдельно запускает PHP, официальный Windows quality, официальный Windows browser quality и release-contract jobs.

## Composer-зависимости: только с согласия владельца

**Категорически запрещено обновлять Laravel, Livewire, Composer-зависимости или изменять `composer.json` и `composer.lock` без предварительного явного согласования с владельцем KaevCMS.** Это касается разработки новых функций, исправления ошибок, аудита безопасности и подготовки релизов. Каждый пакет релиза доставляет `composer.lock` на все сайты, а сайты на старых версиях проходят смену зависимостей только через пакет-мост.

Утверждённое состояние записано в `deployment/dependencies/approved-dependencies.json`: SHA-256 обоих файлов, версии ключевых пакетов, кто и почему согласовал. `php deployment/dependencies/dependency-guard.php` сравнивает с ним рабочую копию. Windows quality, все задания GitHub Actions, `build-release.ps1`, `build-release.php` и `build-web-update-package.ps1` запускают эту проверку и завершаются ошибкой при любом несогласованном изменении. Обычный `composer install` по утверждённому `composer.lock` не блокируется: проверка не подключена к Composer.

Как оформить изменение, согласованное с владельцем:

1. Выполните изменение (`composer update <пакет>`) в отдельной ветке.
2. Владелец проверяет его и сам запускает в интерактивном терминале:

   ```powershell
   php deployment/dependencies/dependency-guard.php --approve --reason="Laravel 13.39: исправление безопасности"
   ```

   Команда покажет изменённые файлы и старые и новые версии Laravel и Livewire, затем попросит ввести фразу подтверждения, привязанную именно к этим файлам. Без интерактивного терминала (CI, скрипты, автоматические помощники) согласование отклоняется.
3. Закоммитьте `approved-dependencies.json` вместе с `composer.json` и `composer.lock`, а к следующему релизу обновите `composer_lock.current_sha256` в `release.json`.

Чтобы отменить несогласованное изменение: `git checkout -- composer.json composer.lock`.

## Структура фронтенд-ассетов

Административный layout подключает CSS строго в таком порядке:

```text
public/assets/admin/css/base.css
public/assets/admin/css/layout.css
public/assets/admin/css/content.css
public/assets/admin/css/infrastructure.css
public/assets/admin/css/components.css
public/assets/admin/css/extensions.css
public/assets/admin/css/catalogs.css
```

Порядок нельзя менять: последующие файлы намеренно уточняют базовые компоненты. Не возвращайте монолитный `app.css` и не добавляйте исправления страниц бесконтрольными переопределениями в конец. Новые правила помещайте в наиболее подходящий файл и добавляйте browser-регрессию при изменении раскладки.

Обе встроенные темы личного кабинета используют единый версионируемый runtime:

```text
public/assets/account/js/navigation.js
```

Отдельные копии JavaScript внутри тем больше не поставляются. У темы остаются собственные CSS и Blade-шаблоны, но постоянная Livewire-навигация, окна аватара и результатов операций, мобильная боковая панель, управление паролями и очистка обработчиков относятся к общему runtime.

## Сборка редактора контента

Редактор новостей и страниц имеет читаемый исходник `resources/js/admin/content-editor.js` и поставляемый автономный bundle `public/assets/admin/js/news-editor.js`. Зависимости Tiptap и esbuild зафиксированы в `package-lock.json`; CDN не используется.

После изменения исходника выполните:

```powershell
npm ci --include=dev
npm run build:editor
```

В релиз обязательно включаются исходник, bundle и `public/assets/admin/js/news-editor.LICENSE.txt`. Не разрешайте произвольные `style` или `class`: редактор, `SafeHtmlSanitizer` и CSS обеих публичных тем образуют единый контракт безопасных `data-*` токенов.


## Переходы Aurelia между сайтом и ЛК

Сайт и личный кабинет используют разные макеты и самостоятельные глобальные CSS. Ссылки Aurelia, ведущие с сайта в ЛК (шапка, мобильное меню, hero и footer), намеренно используют обычную навигацию документа. Не добавлять к ним `wire:navigate`: Livewire заменяет body до окончания загрузки новых stylesheet, из-за чего браузер может кратко показать неоформленные SVG с чёрной заливкой. Обычная навигация ждёт render-blocking CSS и не переносит глобальные стили одного макета в другой. Переходы внутри сайта и внутри ЛК остаются Livewire.

При открытии документа Aurelia используется однократное мягкое появление body от opacity .88 до 1 за 160 мс, без движения элементов и без предварительного скрытия страницы. В ЛК оно включено атрибутом `data-account-document-entry` и общим runtime. При `prefers-reduced-motion: reduce` этот эффект отключён. Native cross-document View Transitions не используются: браузерные проверки выявили зависание в сочетании разных тем.

Инициализация публичной Aurelia учитывает, что DOMContentLoaded и начальный livewire:navigated относятся к одной странице. Повторное уведомление не запускает появление контента заново и не пересоздаёт частицы/мониторинг. После настоящего livewire:navigating runtime освобождает ресурсы и допускает инициализацию нового контента.

Регрессии: `aurelia-navigation.spec.mjs` проверяет начальную инициализацию и реальные замены контента; `player-character-directory.spec.mjs` проверяет переходы сайт ↔ ЛК с задержкой CSS 350 мс, отсутствие больших залитых SVG на кадрах, desktop/mobile и reduced-motion. На мобильном возврат на сайт выполняется через открытое боковое меню.
Проверено 15.10.2026 без повышения версии 0.48.6: 21 PHP-тест тем/навигации/кошелька (1198 assertions), shared-runtime contract и синтаксис JS прошли; полный browser-quality.ps1 — 72 теста успешно. Повторный mysql-concurrency.php на отдельной одноразовой MariaDB также прошёл, тестовая БД удалена.
