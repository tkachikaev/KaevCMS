# Web Installer / Web Installer

## English

The installer checks PHP, extensions, layout, write access, database privileges, owner creation, and final security state. It creates the required news, page, settings, avatar, and game-asset upload directories when they are missing. Installation is allowed over HTTP with a visible warning; HTTPS remains recommended for public websites.

Read the current guide: [INSTALLATION.md](en/INSTALLATION.md).

## Русский

Установщик проверяет PHP, расширения, структуру, запись, права базы, создание владельца и итоговую безопасность. При отсутствии он создаёт рабочие каталоги загрузки новостей, страниц, настроек, аватаров и игровых ресурсов. Установка разрешена по HTTP с заметным предупреждением; для публичного сайта по-прежнему рекомендуется HTTPS. После успешной установки удалите публичную папку `/install`; файл `storage/app/installed.lock` дополнительно блокирует повторный запуск.

Актуальная инструкция: [INSTALLATION.md](ru/INSTALLATION.md).
