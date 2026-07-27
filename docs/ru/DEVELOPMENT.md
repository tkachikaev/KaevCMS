# Разработка и проверки качества

## Обязательные проверки

```powershell
.\deployment\windows\quality.ps1
.\deployment\windows\browser-quality.ps1
```

Офлайн-проверка запускает регрессии Windows Update, тесты сетевой политики Composer, Web Installer, shared-hosting, Web Update, Composer validation, Pint, PHPStan, PHPUnit и проверку route cache. Browser quality устанавливает зафиксированные npm-зависимости и запускает Playwright.

Нельзя ослаблять регрессионный тест ради зелёного результата. Для каждого исправления добавляйте тест, особенно для установки, обновлений, аутентификации, границ баз данных и сбоев внешнего GameServer.

## Выпуск версии

- Повышайте `VERSION` для каждого переданного изменения.
- Обновляйте `README.md` и `CHANGELOG.md`.
- Выпускайте full, patch, Web Update и SHA256.
- Проверяйте целостность ZIP и переносимые разделители путей.
- Подтверждайте, что предыдущий релиз плюс патч совпадает с полным новым релизом.
- Явно фиксируйте изменения миграций и Composer/npm lock-файлов.

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
