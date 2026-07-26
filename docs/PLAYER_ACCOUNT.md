# Player account / Личный кабинет игрока

## English

The player account uses a persistent Livewire shell with separate characters, game accounts, web inventory, and module pages.

The overview is intentionally compact. A first-game-account onboarding panel is shown only while the player has no game accounts; afterwards the page opens directly with account metrics and quick actions.

Account settings are not part of the game navigation. On desktop and mobile, one account menu opens from the top-right avatar. It contains the future coin balance, account settings, security and password, a link back to the website, and sign out. A future donation module may add its own separate section to the left game navigation.

When the language is changed from a module page, KaevCMS returns the player to the localized account overview. Module query parameters are intentionally discarded because module routes are not locale-prefixed.

The account area is split into two pages:

- **Account settings** contains the KaevCMS avatar and read-only username/email information;
- **Security and password** contains the password form, requires the current KaevCMS password, uses the same minimum rules as registration, and clearly states that game-account passwords are not changed.

Successful password changes rotate the remember token, regenerate the current session, write an audit record, and send the configured password-change email notification when mail delivery is ready.

Read the current guide: [ADMINISTRATION.md](en/ADMINISTRATION.md).

## Русский

Личный кабинет использует постоянную Livewire-оболочку и отдельные страницы персонажей, игровых аккаунтов, веб-инвентаря и модулей.

Страница обзора сделана компактной. Подсказка создания первого игрового аккаунта показывается только пока у игрока нет игровых аккаунтов; после создания сразу отображаются показатели и быстрые действия.

Настройки аккаунта не входят в игровую навигацию. На компьютере и мобильном устройстве единое меню аккаунта открывается через аватар справа сверху. В нём находятся будущий баланс монет, настройки аккаунта, безопасность и пароль, переход на сайт и выход. Будущий модуль пожертвований сможет добавить собственный отдельный раздел в левое игровое меню.

При смене языка со страницы модуля KaevCMS возвращает игрока на локализованный обзор личного кабинета. Параметры модуля намеренно не сохраняются, поскольку маршруты модулей не имеют языкового префикса.

Раздел аккаунта разделён на две страницы:

- **Настройки аккаунта** содержат аватар KaevCMS и неизменяемые данные логина и почты;
- **Безопасность и пароль** содержит форму смены пароля, требует текущий пароль KaevCMS, использует требования регистрации и явно поясняет, что игровые пароли не изменяются.

После успешной смены пароля обновляется remember token, пересоздаётся текущая сессия, записывается событие аудита и, если почта настроена, отправляется уведомление о смене пароля.

Актуальная инструкция: [ADMINISTRATION.md](ru/ADMINISTRATION.md).
