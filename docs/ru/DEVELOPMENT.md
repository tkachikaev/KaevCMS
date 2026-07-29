# Разработка и проверки качества

## Обязательные проверки

```powershell
.\deployment\windows\quality.ps1
.\deployment\windows\browser-quality.ps1
```

Офлайн-проверка запускает регрессии Windows Update, сетевой политики Composer, Web Installer, shared-hosting, Web Update, единого release builder, Composer validation, Pint, PHPStan, PHPUnit и route cache. Browser quality использует зависимости, установленные через `browser-setup.ps1`, и запускает Playwright.

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
    -PreviousFullArchive "C:\Releases\KaevCMS-0.42.4-full.zip" `
    -OutputDirectory "C:\Releases\0.44.8"
```

Сборщик проверяет, что предыдущий full плюс patch точно совпадает с новым деревом, cumulative начинается с baseline `0.42.4`, runtime-файлы не попадают в архивы, а SHA256 соответствует результату. GitHub Actions отдельно запускает PHP, официальный Windows quality, официальный Windows browser quality и release-contract jobs.

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
