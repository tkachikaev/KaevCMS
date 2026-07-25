# Добавление событий в журнал

Документ предназначен для разработчиков ядра, адаптеров и модулей KaevCMS.

## Компоненты

```text
App\Services\AuditLogger
App\Models\AuditLog
database/migrations/*_create_audit_logs_table.php
```

Все события записываются через `AuditLogger`. Прямое создание строк `AuditLog::create()` в рабочем коде не рекомендуется: сервис централизованно определяет инициатора, IP-адрес, User-Agent и скрывает секретные данные.

## Успешное событие

```php
use App\Services\AuditLogger;

public function store(AuditLogger $auditLogger)
{
    // Выполнение операции...

    $auditLogger->success(
        category: 'admin',
        action: 'news.created',
        target: $news,
        details: [
            'publication_state' => $news->publicationState(),
        ],
    );
}
```

Когда `actor` не передан, сервис сначала ищет администратора в guard `admin`, затем пользователя в guard `web`.

## Ошибка

```php
$auditLogger->failed(
    category: 'mail',
    action: 'mail.send_failed',
    target: $recipient,
    details: [
        'exception_class' => $exception::class,
    ],
);
```

Не записывайте полный текст SMTP-ответа без проверки: он может содержать адреса, внутренние имена серверов или данные авторизации.

## Системное событие

```php
$auditLogger->system(
    category: 'system',
    action: 'cache.rebuilt',
    target: 'Кэш CMS',
    details: ['items' => $count],
);
```

## Явный инициатор

Модель администратора или пользователя:

```php
$auditLogger->success(
    category: 'user',
    action: 'promo_code.used',
    actor: $user,
    target: $promoCode,
);
```

Неавторизованная попытка входа:

```php
$auditLogger->failed(
    category: 'user',
    action: 'auth.login_failed',
    actor: $submittedLogin,
    target: 'Личный кабинет',
    actorType: 'user',
);
```

## Именование

Категория — короткая группа событий:

```text
admin
user
mail
system
module
payments
votes
```

Действие записывается в формате `объект.действие`:

```text
news.created
news.updated
settings.registration_updated
theme.activated
promo_code.used
donation.completed
```

Не переиспользуйте один код для разных смыслов. Технический код сохраняется постоянно, а русское отображаемое название можно расширить в `AuditLog::actionLabel()`.

## Объект действия

В `target` можно передать Eloquent-модель или строку:

```php
// Сохраняются тип, ID и человекочитаемое имя модели.
target: $news

// Сохраняется только снимок названия.
target: 'Основные настройки'
```

Снимки имени и объекта сохраняются намеренно: журнал останется понятным даже после удаления исходной записи.

## Изменённые значения

Рекомендуемая структура:

```php
$details = [
    'changes' => [
        'registration_enabled' => [
            'old' => false,
            'new' => true,
        ],
    ],
];
```

Не сохраняйте полный текст большой новости, письма или файла. Для таких полей достаточно признака:

```php
'body_changed' => true
```

## Защита секретов

`AuditLogger` рекурсивно скрывает ключи, содержащие обозначения паролей, токенов, secrets, cookies и ключей. Это страховка, а не разрешение передавать секреты в сервис.

Правильно:

```php
'details' => [
    'smtp_password_changed' => true,
]
```

Неправильно:

```php
'details' => [
    'smtp_password' => $request->input('smtp_password'),
]
```

Даже при автоматической маскировке секрет не должен покидать минимально необходимый участок кода.

Адреса, имена схем и учётные записи внешних игровых баз также считаются инфраструктурными данными. Для них записывайте только признаки `database_connection_configured` и `*_changed`, не фактические значения.

## События входа администратора

Специализированная таблица `admin_login_logs` хранит фактические проверки пароля. Не дублируйте в общий audit-журнал попытки с неизвестным email: это позволяет злоумышленнику раздувать две таблицы одновременно.

Запросы, остановленные route limiter по IP или внутренним limiter по email + IP, не должны создавать отдельное событие `throttled`. Для расследования достаточно фактических проверок пароля и счётчиков ограничителя.

## Отказоустойчивость

Ошибка записи журнала не должна ломать основную операцию. `AuditLogger` перехватывает собственные ошибки и отправляет краткое сообщение в технический Laravel-log.

Это важно при обновлении, когда новый код уже скопирован, а миграция `audit_logs` ещё не выполнена.

## Модули

Модуль получает сервис через контейнер Laravel и не создаёт собственную таблицу журнала:

```php
$auditLogger->success(
    category: 'promo_codes',
    action: 'promo_code.used',
    actor: $user,
    target: $promoCode,
    details: ['reward_id' => $reward->id],
);
```

Новая категория появится в панели автоматически после первой записи.

## Тесты

Минимальная проверка события:

```php
$this->assertDatabaseHas('audit_logs', [
    'category' => 'admin',
    'action' => 'news.created',
    'result' => 'success',
]);
```

Отдельно проверяйте, что секреты отсутствуют в JSON `details`.
