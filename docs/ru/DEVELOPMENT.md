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
