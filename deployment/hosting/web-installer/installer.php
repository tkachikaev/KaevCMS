<?php

declare(strict_types=1);

use App\Auth\AdminRole;
use App\Models\Admin;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

const KAEVCMS_INSTALL_SESSION = 'kaevcms_web_installer';

final class InstallerValidationException extends RuntimeException
{
    // Marks validation errors that may be shown safely by the installer.
}

final class InstallerDatabaseConnectionException extends RuntimeException
{
    // Distinguishes database connection failures from validation errors.
}

final class InstallerDatabasePrivilegeException extends RuntimeException
{
    // Distinguishes insufficient database privileges during installation.
}

final class InstallerExistingInstallationException extends RuntimeException
{
    // Prevents a fresh installer run from silently reusing an existing owner account.
}

final class InstallerBusyException extends RuntimeException
{
    // Marks an installation attempt blocked by the installer lock.
}

final class InstallerOperationException extends RuntimeException
{
    // Marks an expected failure of an installation operation.
}

if (! defined('KAEVCMS_INSTALLER_FUNCTIONS_ONLY')) {
    runWebInstaller();
}

function runWebInstaller(): void
{
    if (! defined('KAEVCMS_INSTALL_ENTRY')) {
        http_response_code(404);
        echo 'Not Found';

        return;
    }

    $root = defined('KAEVCMS_PROJECT_ROOT')
        ? rtrim((string) constant('KAEVCMS_PROJECT_ROOT'), '/\\')
        : dirname(__DIR__, 3);
    $publicRoot = installerPublicRoot($root);
    $lockPath = $root.'/storage/app/installed.lock';
    $installingPath = $root.'/storage/app/installing.lock';
    $envPath = $root.'/.env';
    $envExamplePath = $root.'/.env.example';
    $version = trim((string) @file_get_contents($root.'/VERSION'));

    sendInstallerSecurityHeaders();
    startInstallerSession();

    $_SESSION[KAEVCMS_INSTALL_SESSION] ??= [];
    $state = &$_SESSION[KAEVCMS_INSTALL_SESSION];
    if (! isset($state['initialized'])) {
        session_regenerate_id(true);
        $state['initialized'] = true;
    }
    $state['csrf'] ??= bin2hex(random_bytes(24));
    $state['install_token'] ??= bin2hex(random_bytes(24));
    $state['language'] = in_array((string) ($_GET['lang'] ?? $state['language'] ?? 'ru'), ['ru', 'en'], true)
        ? (string) ($_GET['lang'] ?? $state['language'] ?? 'ru')
        : 'ru';

    $language = $state['language'];
    $text = installerTranslations($language);

    if (is_file($installingPath) && ! installationLockOwnedByToken($installingPath, (string) $state['install_token'])) {
        renderPage(
            $text['installed_title'],
            '<div class="alert alert-error">'.e($text['resume_denied']).'</div>',
            $language,
            $version,
            423,
        );

        return;
    }

    $step = (string) ($_GET['step'] ?? 'welcome');
    $allowedSteps = ['welcome', 'requirements', 'database', 'administrator', 'complete'];
    if (! in_array($step, $allowedSteps, true)) {
        $step = 'welcome';
    }

    $notice = consumeInstallerFlash($state, 'flash_notice');
    $error = consumeInstallerFlash($state, 'flash_error');
    if ($notice === null && is_file($installingPath)) {
        $notice = $text['resume_notice'];
    }

    $isCompletionView = $step === 'complete' && isset($state['complete']);
    if (! $isCompletionView && installerIsLocked($lockPath, $installingPath, $envPath)) {
        renderPage($text['installed_title'], installedBody($text, postInstallSecurityChecks($root, $publicRoot, $envPath, $lockPath, $text)), $language, $version, 200);

        return;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $action = (string) ($_POST['action'] ?? '');

        try {
            verifyCsrf((string) ($_POST['_token'] ?? ''), (string) $state['csrf']);

            if ($action === 'start' || $action === 'recheck') {
                redirectTo('requirements', $language);
            }

            if ($action === 'test_database' || $action === 'continue_database') {
                enforceInstallerRateLimit($state, 'database', 10, 60, $text['rate_limit']);
                $database = validateDatabaseInput($_POST, $text, $state['database']['password'] ?? null);
                $site = validateSiteInput($_POST, $text);
                $state['database'] = $database;
                $state['site'] = $site;
                testDatabaseConnection($database, true);

                if ($action === 'test_database') {
                    $state['flash_notice'] = $text['database_success'];
                    redirectTo('database', $language);
                }

                redirectTo('administrator', $language);
            }

            if ($action === 'install') {
                enforceInstallerRateLimit($state, 'install', 5, 300, $text['rate_limit']);
                $requirements = requirementChecks($root, $publicRoot, $text);
                if (hasFailedRequirements($requirements)) {
                    throw new InstallerValidationException($text['requirements_failed']);
                }

                if (! isset($state['database'], $state['site'])) {
                    throw new InstallerValidationException($text['database_missing']);
                }

                $state['administrator_draft'] = [
                    'name' => installerSlice(trim((string) ($_POST['admin_name'] ?? '')), 0, 100),
                    'email' => installerSlice(strtolower(trim((string) ($_POST['admin_email'] ?? ''))), 0, 255),
                ];
                $administrator = validateAdministratorInput($_POST, $text);
                testDatabaseConnection($state['database'], true);
                $siteUrl = $state['site']['url'];
                $installedOwnerEmail = performInstallation(
                    root: $root,
                    publicRoot: $publicRoot,
                    envExamplePath: $envExamplePath,
                    envPath: $envPath,
                    lockPath: $lockPath,
                    installingPath: $installingPath,
                    database: $state['database'],
                    site: $state['site'],
                    administrator: $administrator,
                    language: $language,
                    version: $version,
                    installToken: (string) $state['install_token'],
                );

                session_regenerate_id(true);
                $_SESSION[KAEVCMS_INSTALL_SESSION] = [
                    'initialized' => true,
                    'csrf' => bin2hex(random_bytes(24)),
                    'install_token' => bin2hex(random_bytes(24)),
                    'language' => $language,
                    'complete' => [
                        'email' => $installedOwnerEmail,
                        'admin_url' => rtrim($siteUrl, '/').'/admin',
                        'site_url' => rtrim($siteUrl, '/').'/',
                        'security_checks' => postInstallSecurityChecks($root, $publicRoot, $envPath, $lockPath, $text),
                    ],
                ];
                redirectTo('complete', $language);
            }

            throw new InstallerValidationException($text['invalid_action']);
        } catch (Throwable $exception) {
            $reference = strtoupper(bin2hex(random_bytes(4)));
            writeInstallerLog($root, $exception, $state['database']['password'] ?? null, $reference);
            $state['flash_error'] = publicInstallerError($exception, $text, $reference);
            redirectTo(installerStepForAction($action, $step), $language);
        }
    }

    if ($step === 'complete' && ! isset($state['complete'])) {
        redirectTo('welcome', $language);
    }

    $body = match ($step) {
        'welcome' => welcomeBody($text, $state),
        'requirements' => requirementsBody($text, requirementChecks($root, $publicRoot, $text), $state),
        'database' => databaseBody($text, $state),
        'administrator' => administratorBody($text, $state),
        'complete' => completeBody($text, $state),
        default => welcomeBody($text, $state),
    };

    if ($notice !== null) {
        $body = '<div class="alert alert-success">'.e($notice).'</div>'.$body;
    }
    if ($error !== null) {
        $body = '<div class="alert alert-error">'.e($error).'</div>'.$body;
    }

    renderPage($text['title'], $body, $language, $version);
}

/** @return array<string, string> */
function installerTranslations(string $language): array
{
    $ru = [
        'title' => 'Установка KaevCMS',
        'installed_title' => 'KaevCMS уже установлена',
        'installed_text' => 'Установщик заблокирован, потому что найдена действующая установка.',
        'open_site' => 'Открыть сайт',
        'welcome_title' => 'Добро пожаловать в KaevCMS',
        'welcome_text' => 'Этот мастер подготовит CMS для обычного PHP/MySQL-хостинга. Composer и консоль во время установки не требуются, если папка vendor уже находится в архиве.',
        'begin' => 'Начать установку',
        'requirements_title' => 'Проверка хостинга',
        'requirements_text' => 'Критические проверки должны быть зелёными. После исправления можно запустить проверку повторно.',
        'component' => 'Компонент',
        'status' => 'Статус',
        'details' => 'Подробности',
        'ok' => 'Готово',
        'failed' => 'Ошибка',
        'warning' => 'Предупреждение',
        'recheck' => 'Проверить снова',
        'next' => 'Далее',
        'requirements_failed' => 'Требования хостинга ещё не выполнены.',
        'safe_web_root' => 'Безопасная публичная папка',
        'safe_web_root_standard' => 'Домен направлен на public — стандартный безопасный режим.',
        'safe_web_root_split' => 'Ядро и публичная папка разделены — безопасный shared-hosting режим.',
        'safe_web_root_unsafe' => 'Домен направлен на корень проекта. Используйте Document Root public/ или подготовленный split-пакет.',
        'detected_public_root' => 'Определённая публичная папка',
        'private_core' => 'Закрытое ядро',
        'private_core_safe' => 'Ядро находится вне публичной папки.',
        'private_core_unsafe' => 'Ядро находится внутри публичной папки и может быть раскрыто через веб-сервер.',
        'https_check' => 'HTTPS',
        'https_ready' => 'Запрос установщика защищён HTTPS.',
        'https_missing' => 'HTTPS не обнаружен. Установка доступна по HTTP, но для публичного сайта рекомендуется подключить SSL-сертификат.',
        'database_title' => 'Сайт и база данных',
        'database_text' => 'Данные выдаёт панель управления хостингом. Пароль базы не выводится на страницу и не записывается в журналы.',
        'site_name' => 'Название сайта',
        'site_url' => 'Адрес сайта',
        'db_host' => 'Сервер MySQL',
        'db_port' => 'Порт',
        'db_name' => 'Имя базы данных',
        'db_user' => 'Пользователь базы',
        'db_password' => 'Пароль базы',
        'test_connection' => 'Проверить подключение',
        'database_success' => 'Подключение к базе данных успешно проверено.',
        'database_missing' => 'Сначала проверьте подключение к базе данных.',
        'administrator_title' => 'Владелец CMS',
        'administrator_text' => 'Эта учётная запись получит полный доступ к административной панели.',
        'admin_name' => 'Имя владельца',
        'admin_email' => 'Email',
        'admin_password' => 'Пароль',
        'admin_password_confirmation' => 'Повторите пароль',
        'install' => 'Установить KaevCMS',
        'complete_title' => 'KaevCMS успешно установлена',
        'complete_text' => 'База подготовлена, владелец создан, повторный запуск установщика заблокирован.',
        'admin_panel' => 'Административная панель',
        'owner' => 'Владелец',
        'finish_note' => 'Следующий шаг — войти в админку, подключить LoginServer/GameServer и настроить почту.',
        'security_review_title' => 'Проверка безопасности установки',
        'security_review_text' => 'Критические ошибки необходимо исправить сразу. Предупреждения не блокируют сайт, но показывают рекомендуемые настройки хостинга.',
        'security_review_ok' => 'Защищено',
        'security_review_warning' => 'Проверьте',
        'security_review_error' => 'Ошибка',
        'security_core_location' => 'Размещение ядра',
        'security_env_location' => 'Размещение .env',
        'security_debug' => 'Режим отладки',
        'security_https' => 'HTTPS и защищённые cookie',
        'security_storage' => 'Каталог storage',
        'security_cache' => 'Каталог bootstrap/cache',
        'security_uploads' => 'Каталог uploads',
        'security_env_permissions' => 'Права файла .env',
        'security_lock' => 'Блокировка установщика',
        'security_outside_public' => 'Файл находится вне публичной папки.',
        'security_inside_public' => 'Файл находится внутри публичной папки.',
        'security_debug_disabled' => 'APP_DEBUG выключен.',
        'security_debug_enabled' => 'APP_DEBUG включён или не определён.',
        'security_https_enabled' => 'Сайт настроен на HTTPS и защищённые cookie.',
        'security_https_disabled' => 'Сайт установлен без HTTPS или защищённых cookie.',
        'security_writable' => 'Каталог доступен на запись.',
        'security_not_writable' => 'Каталог недоступен на запись.',
        'security_permission_preferred' => 'Права ограничены рекомендуемым значением.',
        'security_permission_broad' => 'Права шире рекомендуемых; ограничьте доступ через файловый менеджер хостинга.',
        'security_permission_unknown' => 'Хостинг не позволяет надёжно определить POSIX-права.',
        'security_lock_present' => 'Повторный запуск Web Installer заблокирован.',
        'security_lock_missing' => 'Файл installed.lock отсутствует.',
        'php_version' => 'PHP 8.3 или новее',
        'vendor' => 'Composer-зависимости',
        'extension' => 'Расширение PHP: :name',
        'writable' => 'Доступ на запись: :path',
        'env_template' => 'Шаблон .env.example',
        'version_file' => 'Файл VERSION',
        'back' => 'Назад',
        'resume_notice' => 'Обнаружена незавершённая установка. Мастер безопасно продолжит её с текущего шага.',
        'https_warning' => 'Соединение не защищено HTTPS. Данные установки передаются по сети без шифрования. Для публичного сайта рекомендуется настроить HTTPS.',
        'resume_denied' => 'Найдена незавершённая установка, принадлежащая другой сессии браузера. Продолжите в исходном браузере или удалите storage/app/installing.lock через файловый менеджер, если процесс точно остановлен.',
        'rate_limit' => 'Слишком много попыток за короткое время. Подождите и повторите действие.',
        'password_saved_hint' => 'Проверенный пароль уже сохранён только в серверной сессии. Оставьте поле пустым, чтобы использовать его повторно.',
        'invalid_action' => 'Неизвестное действие установщика.',
        'site_name_invalid' => 'Название сайта должно содержать от 1 до 100 символов.',
        'database_connection_failed' => 'Не удалось подключиться к MySQL. Проверьте адрес, порт, имя базы, пользователя и пароль.',
        'database_privileges_failed' => 'Подключение установлено, но пользователю MySQL не хватает прав на создание и изменение таблиц.',
        'database_existing_installation' => 'В выбранной базе уже существует владелец KaevCMS. Используйте пустую базу или восстановите пароль существующего владельца — введённые сейчас данные не были применены.',
        'installer_busy' => 'Установка уже выполняется в другом окне. Подождите несколько секунд и повторите попытку.',
        'unexpected_error' => 'Установка не завершена. Подробности записаны в закрытый журнал. Код ошибки: :reference',
        'password_confirmation_failed' => 'Пароли владельца не совпадают.',
        'password_requirements' => 'Пароль должен содержать минимум 12 символов, строчную и заглавную буквы, а также цифру.',
        'field_invalid' => 'Поле «:field» заполнено неверно.',
    ];

    $en = [
        'title' => 'Install KaevCMS',
        'installed_title' => 'KaevCMS is already installed',
        'installed_text' => 'The installer is locked because an existing installation was detected.',
        'open_site' => 'Open website',
        'welcome_title' => 'Welcome to KaevCMS',
        'welcome_text' => 'This wizard prepares the CMS for standard PHP/MySQL hosting. Composer and shell access are not required during installation when vendor is included in the archive.',
        'begin' => 'Start installation',
        'requirements_title' => 'Hosting requirements',
        'requirements_text' => 'All critical checks must be green. Re-run the check after fixing a problem.',
        'component' => 'Component',
        'status' => 'Status',
        'details' => 'Details',
        'ok' => 'Ready',
        'failed' => 'Failed',
        'warning' => 'Warning',
        'recheck' => 'Check again',
        'next' => 'Next',
        'requirements_failed' => 'Hosting requirements are not satisfied yet.',
        'safe_web_root' => 'Safe public directory',
        'safe_web_root_standard' => 'The domain points to public — standard secure mode.',
        'safe_web_root_split' => 'The core and public directory are separated — secure shared-hosting mode.',
        'safe_web_root_unsafe' => 'The domain points to the project root. Use Document Root public/ or the generated split package.',
        'detected_public_root' => 'Detected public directory',
        'private_core' => 'Private application core',
        'private_core_safe' => 'The application core is outside the public directory.',
        'private_core_unsafe' => 'The application core is inside the public directory and may be exposed by the web server.',
        'https_check' => 'HTTPS',
        'https_ready' => 'The installer request is protected by HTTPS.',
        'https_missing' => 'HTTPS was not detected. Installation remains available over HTTP, but an SSL certificate is recommended for a public website.',
        'database_title' => 'Website and database',
        'database_text' => 'These values are provided by your hosting control panel. The database password is never rendered or written to logs.',
        'site_name' => 'Website name',
        'site_url' => 'Website URL',
        'db_host' => 'MySQL server',
        'db_port' => 'Port',
        'db_name' => 'Database name',
        'db_user' => 'Database user',
        'db_password' => 'Database password',
        'test_connection' => 'Test connection',
        'database_success' => 'Database connection was verified successfully.',
        'database_missing' => 'Verify the database connection first.',
        'administrator_title' => 'CMS owner',
        'administrator_text' => 'This account receives full access to the administration panel.',
        'admin_name' => 'Owner name',
        'admin_email' => 'Email',
        'admin_password' => 'Password',
        'admin_password_confirmation' => 'Repeat password',
        'install' => 'Install KaevCMS',
        'complete_title' => 'KaevCMS installed successfully',
        'complete_text' => 'The database is ready, the owner was created, and the installer is now locked.',
        'admin_panel' => 'Administration panel',
        'owner' => 'Owner',
        'finish_note' => 'Next, sign in to the administration panel, connect LoginServer/GameServer, and configure email.',
        'security_review_title' => 'Installation security review',
        'security_review_text' => 'Fix critical errors immediately. Warnings do not block the website, but identify recommended hosting settings.',
        'security_review_ok' => 'Protected',
        'security_review_warning' => 'Review',
        'security_review_error' => 'Failed',
        'security_core_location' => 'Core location',
        'security_env_location' => '.env location',
        'security_debug' => 'Debug mode',
        'security_https' => 'HTTPS and secure cookies',
        'security_storage' => 'storage directory',
        'security_cache' => 'bootstrap/cache directory',
        'security_uploads' => 'uploads directory',
        'security_env_permissions' => '.env permissions',
        'security_lock' => 'Installer lock',
        'security_outside_public' => 'The file is outside the public directory.',
        'security_inside_public' => 'The file is inside the public directory.',
        'security_debug_disabled' => 'APP_DEBUG is disabled.',
        'security_debug_enabled' => 'APP_DEBUG is enabled or undefined.',
        'security_https_enabled' => 'The website uses HTTPS and secure cookies.',
        'security_https_disabled' => 'The website was installed without HTTPS or secure cookies.',
        'security_writable' => 'The directory is writable.',
        'security_not_writable' => 'The directory is not writable.',
        'security_permission_preferred' => 'Permissions are restricted to the recommended level.',
        'security_permission_broad' => 'Permissions are broader than recommended; restrict them in the hosting file manager.',
        'security_permission_unknown' => 'The hosting environment does not expose reliable POSIX permissions.',
        'security_lock_present' => 'Web Installer cannot be started again.',
        'security_lock_missing' => 'installed.lock is missing.',
        'php_version' => 'PHP 8.3 or newer',
        'vendor' => 'Composer dependencies',
        'extension' => 'PHP extension: :name',
        'writable' => 'Writable path: :path',
        'env_template' => '.env.example template',
        'version_file' => 'VERSION file',
        'back' => 'Back',
        'resume_notice' => 'An incomplete installation was detected. The wizard will safely resume it from the current step.',
        'https_warning' => 'This connection is not protected by HTTPS. Installation data is transmitted without encryption. Configure HTTPS for a public website.',
        'resume_denied' => 'An incomplete installation belongs to another browser session. Continue in the original browser or remove storage/app/installing.lock through the file manager only after confirming that no installation process is running.',
        'rate_limit' => 'Too many attempts were made in a short period. Wait and try again.',
        'password_saved_hint' => 'The verified password is stored only in the server-side session. Leave this field empty to reuse it.',
        'invalid_action' => 'Unknown installer action.',
        'site_name_invalid' => 'The website name must contain between 1 and 100 characters.',
        'database_connection_failed' => 'Could not connect to MySQL. Check the host, port, database name, username, and password.',
        'database_privileges_failed' => 'The connection works, but the MySQL user cannot create and modify tables.',
        'database_existing_installation' => 'The selected database already contains a KaevCMS owner. Use an empty database or recover the existing owner password; the credentials entered here were not applied.',
        'installer_busy' => 'Installation is already running in another window. Wait a few seconds and try again.',
        'unexpected_error' => 'Installation was not completed. Details were written to the protected log. Error code: :reference',
        'password_confirmation_failed' => 'The owner passwords do not match.',
        'password_requirements' => 'The password must contain at least 12 characters, upper and lower case letters, and a number.',
        'field_invalid' => 'The “:field” field is invalid.',
    ];

    return $language === 'en' ? $en : $ru;
}

function installerPublicRoot(string $root): string
{
    if (defined('KAEVCMS_PUBLIC_PATH')) {
        $configured = rtrim((string) constant('KAEVCMS_PUBLIC_PATH'), '/\\');
        if ($configured !== '') {
            return $configured;
        }
    }

    return $root.'/public';
}

/** @return array{ok:bool,mode:string,details:string} */
function installerDeploymentSafety(?string $script = null, ?bool $sharedHosting = null): array
{
    $sharedHosting ??= defined('KAEVCMS_SHARED_HOSTING') && constant('KAEVCMS_SHARED_HOSTING') === true;
    if ($sharedHosting) {
        return ['ok' => true, 'mode' => 'split', 'details' => 'split core/public layout'];
    }

    $paths = $script !== null
        ? [$script]
        : [
            (string) ($_SERVER['SCRIPT_NAME'] ?? '/install/index.php'),
            (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: ''),
        ];
    foreach ($paths as $path) {
        $normalized = str_replace('\\', '/', $path);
        if (preg_match('#(?:^|/)public/install(?:/index\.php)?/?$#', ltrim($normalized, '/')) === 1) {
            return [
                'ok' => false,
                'mode' => 'unsafe',
                'details' => 'domain points to the project root; use Document Root public/ or the shared-hosting split package',
            ];
        }
    }

    return ['ok' => true, 'mode' => 'standard', 'details' => 'public directory is the web root'];
}

/** @return list<array{label:string,ok:bool,warning?:bool,details:string}> */
function requirementChecks(string $root, ?string $publicRoot = null, array $text = []): array
{
    $publicRoot ??= installerPublicRoot($root);
    $deploymentSafety = installerDeploymentSafety();
    $checks = [[
        'label' => 'PHP 8.3+',
        'ok' => version_compare(PHP_VERSION, '8.3.0', '>='),
        'details' => PHP_VERSION,
    ]];

    $safetyKey = match ($deploymentSafety['mode']) {
        'split' => 'safe_web_root_split',
        'unsafe' => 'safe_web_root_unsafe',
        default => 'safe_web_root_standard',
    };
    $checks[] = [
        'label' => (string) ($text['safe_web_root'] ?? 'Safe public directory'),
        'ok' => $deploymentSafety['ok'],
        'details' => (string) ($text[$safetyKey] ?? $deploymentSafety['details']),
    ];
    $checks[] = [
        'label' => (string) ($text['detected_public_root'] ?? 'Detected public directory'),
        'ok' => true,
        'details' => installerDisplayPath($publicRoot),
    ];

    $privateCore = ! installerPathInside($root, $publicRoot);
    $checks[] = [
        'label' => (string) ($text['private_core'] ?? 'Private application core'),
        'ok' => $privateCore,
        'details' => (string) ($text[$privateCore ? 'private_core_safe' : 'private_core_unsafe'] ?? installerDisplayPath($root)),
    ];

    $https = isHttpsRequest();
    $checks[] = [
        'label' => (string) ($text['https_check'] ?? 'HTTPS'),
        'ok' => true,
        'warning' => ! $https,
        'details' => (string) ($text[$https ? 'https_ready' : 'https_missing'] ?? ($https ? 'HTTPS' : 'HTTP')),
    ];

    foreach (['pdo', 'pdo_mysql', 'mbstring', 'fileinfo', 'dom', 'openssl', 'tokenizer', 'ctype', 'json', 'session'] as $extension) {
        $checks[] = [
            'label' => 'PHP: '.$extension,
            'ok' => extension_loaded($extension),
            'details' => extension_loaded($extension) ? 'loaded' : 'missing',
        ];
    }

    $checks[] = [
        'label' => 'vendor/autoload.php',
        'ok' => is_file($root.'/vendor/autoload.php'),
        'details' => is_file($root.'/vendor/autoload.php') ? 'available' : 'missing',
    ];
    $checks[] = [
        'label' => '.env.example',
        'ok' => is_file($root.'/.env.example'),
        'details' => is_file($root.'/.env.example') ? 'available' : 'missing',
    ];
    $checks[] = [
        'label' => 'VERSION',
        'ok' => is_file($root.'/VERSION'),
        'details' => is_file($root.'/VERSION') ? trim((string) file_get_contents($root.'/VERSION')) : 'missing',
    ];

    foreach (['storage', 'storage/app', 'storage/framework', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $relative) {
        $path = $root.'/'.$relative;
        $writable = ensureWritableDirectory($path);
        $checks[] = [
            'label' => $relative,
            'ok' => $writable,
            'warning' => $writable && installerWorldWritable($path),
            'details' => installerWritableDetails($path, $writable),
        ];
    }

    foreach ([
        'public/uploads' => $publicRoot.'/uploads',
        'public/uploads/news' => $publicRoot.'/uploads/news',
        'public/uploads/pages' => $publicRoot.'/uploads/pages',
        'public/uploads/settings' => $publicRoot.'/uploads/settings',
        'public/uploads/account-avatars' => $publicRoot.'/uploads/account-avatars',
        'public/uploads/game-assets' => $publicRoot.'/uploads/game-assets',
    ] as $label => $uploadsPath) {
        $uploadsWritable = ensureWritableDirectory($uploadsPath);
        $checks[] = [
            'label' => $label,
            'ok' => $uploadsWritable,
            'warning' => $uploadsWritable && installerWorldWritable($uploadsPath),
            'details' => installerWritableDetails($uploadsPath, $uploadsWritable),
        ];
    }

    $envTarget = is_file($root.'/.env') ? $root.'/.env' : $root;
    $envWritable = is_file($root.'/.env') ? is_writable($root.'/.env') : is_writable($root);
    $checks[] = [
        'label' => '.env',
        'ok' => $envWritable,
        'warning' => is_file($root.'/.env') && installerSensitivePermissionsAreBroad($root.'/.env'),
        'details' => installerWritableDetails($envTarget, $envWritable),
    ];

    return $checks;
}

function installerDisplayPath(string $path): string
{
    return str_replace('\\', '/', $path);
}

function installerPathInside(string $path, string $directory): bool
{
    $normalizedPath = rtrim(installerDisplayPath($path), '/');
    $normalizedDirectory = rtrim(installerDisplayPath($directory), '/');
    if (PHP_OS_FAMILY === 'Windows') {
        $normalizedPath = strtolower($normalizedPath);
        $normalizedDirectory = strtolower($normalizedDirectory);
    }

    return $normalizedPath === $normalizedDirectory
        || str_starts_with($normalizedPath.'/', $normalizedDirectory.'/');
}

function installerPermissionMode(string $path): ?int
{
    if (PHP_OS_FAMILY === 'Windows' || ! file_exists($path)) {
        return null;
    }

    $permissions = @fileperms($path);

    return is_int($permissions) ? ($permissions & 0777) : null;
}

function installerPermissionLabel(string $path): string
{
    $mode = installerPermissionMode($path);

    return $mode === null ? 'permissions unavailable' : sprintf('%04o', $mode);
}

function installerWorldWritable(string $path): bool
{
    $mode = installerPermissionMode($path);

    return $mode !== null && ($mode & 0002) !== 0;
}

function installerSensitivePermissionsAreBroad(string $path): bool
{
    $mode = installerPermissionMode($path);

    return $mode !== null && (($mode & 0007) !== 0 || ($mode & 0020) !== 0);
}

function installerWritableDetails(string $path, bool $writable): string
{
    return ($writable ? 'writable' : 'not writable').' · '.installerPermissionLabel($path);
}

function ensureWritableDirectory(string $path): bool
{
    if (! is_dir($path) && ! @mkdir($path, 0775, true) && ! is_dir($path)) {
        return false;
    }

    if (! is_writable($path)) {
        return false;
    }

    $probe = $path.'/.kaevcms-write-'.bin2hex(random_bytes(6));
    $written = @file_put_contents($probe, 'ok', LOCK_EX);
    if ($written === false) {
        return false;
    }

    @unlink($probe);

    return true;
}

/** @param list<array{label:string,ok:bool,details:string}> $checks */
function hasFailedRequirements(array $checks): bool
{
    foreach ($checks as $check) {
        if (! $check['ok']) {
            return true;
        }
    }

    return false;
}

/** @return array{host:string,port:int,database:string,username:string,password:string} */
function validateDatabaseInput(array $input, array $text, ?string $storedPassword = null): array
{
    $host = trim((string) ($input['db_host'] ?? '127.0.0.1'));
    $port = filter_var($input['db_port'] ?? 3306, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    $database = trim((string) ($input['db_database'] ?? ''));
    $username = trim((string) ($input['db_username'] ?? ''));
    $submittedPassword = (string) ($input['db_password'] ?? '');
    $password = $submittedPassword === '' && $storedPassword !== null ? $storedPassword : $submittedPassword;

    if ($host === '' || $port === false || $database === '' || $username === '') {
        throw new InstallerValidationException($text['database_missing']);
    }
    if (strlen($host) > 255 || preg_match('/\A[A-Za-z0-9._:\-\[\]]+\z/', $host) !== 1) {
        throw new InstallerValidationException(str_replace(':field', $text['db_host'], $text['field_invalid']));
    }
    if (strlen($database) > 64 || preg_match('/\A[A-Za-z0-9_$.-]+\z/', $database) !== 1) {
        throw new InstallerValidationException(str_replace(':field', $text['db_name'], $text['field_invalid']));
    }
    if (strlen($username) > 128 || str_contains($username, "\0")) {
        throw new InstallerValidationException(str_replace(':field', $text['db_user'], $text['field_invalid']));
    }

    return compact('host', 'port', 'database', 'username', 'password');
}

/** @return array{url:string,name:string} */
function validateSiteInput(array $input, array $text): array
{
    $name = trim((string) ($input['site_name'] ?? 'KaevCMS'));
    if ($name === '' || installerLength($name) > 100) {
        throw new InstallerValidationException($text['site_name_invalid']);
    }

    return [
        'url' => normalizeAppUrl((string) ($input['app_url'] ?? detectedAppUrl())),
        'name' => $name,
    ];
}

/** @param array{host:string,port:int,database:string,username:string,password:string} $database */
function testDatabaseConnection(array $database, bool $verifyPrivileges = true): void
{
    $pdo = openDatabaseConnection($database);
    $pdo->query('SELECT 1')->fetchColumn();
    assertNoExistingAdministrators(existingAdministratorCount($pdo));

    if ($verifyPrivileges) {
        verifyDatabasePrivileges($pdo);
    }
}

function existingAdministratorCount(PDO $pdo): int
{
    $statement = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'admins'");
    if ((int) $statement->fetchColumn() < 1) {
        return 0;
    }

    return (int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
}

function assertNoExistingAdministrators(int $count): void
{
    if ($count > 0) {
        throw new InstallerExistingInstallationException('Existing KaevCMS administrators were found.');
    }
}

/** @param array{host:string,port:int,database:string,username:string,password:string} $database */
function openDatabaseConnection(array $database): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $database['host'], $database['port'], $database['database']);

    try {
        return new PDO($dsn, $database['username'], $database['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $exception) {
        throw new InstallerDatabaseConnectionException('MySQL connection failed.', 0, $exception);
    }
}

function verifyDatabasePrivileges(PDO $pdo): void
{
    $table = '__kaevcms_install_probe_'.bin2hex(random_bytes(6));
    $quoted = '`'.$table.'`';
    $created = false;

    try {
        $pdo->exec("CREATE TABLE {$quoted} (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `value` VARCHAR(32) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $created = true;
        $pdo->exec("INSERT INTO {$quoted} (`value`) VALUES ('probe')");
        $pdo->exec("ALTER TABLE {$quoted} ADD COLUMN `checked_at` TIMESTAMP NULL");
        $pdo->exec("UPDATE {$quoted} SET `checked_at` = CURRENT_TIMESTAMP WHERE `id` = 1");
        $pdo->exec("DELETE FROM {$quoted} WHERE `id` = 1");
        $pdo->exec("DROP TABLE {$quoted}");
        $created = false;
    } catch (PDOException $exception) {
        throw new InstallerDatabasePrivilegeException('MySQL privilege probe failed.', 0, $exception);
    } finally {
        if ($created) {
            try {
                $pdo->exec("DROP TABLE IF EXISTS {$quoted}");
            } catch (Throwable) {
                // The original privilege error is more useful than cleanup failure.
            }
        }
    }
}

/** @return array{name:string,email:string,password:string} */
function validateAdministratorInput(array $input, array $text): array
{
    $name = trim((string) ($input['admin_name'] ?? ''));
    $email = strtolower(trim((string) ($input['admin_email'] ?? '')));
    $password = (string) ($input['admin_password'] ?? '');
    $confirmation = (string) ($input['admin_password_confirmation'] ?? '');

    if ($name === '' || installerLength($name) > 100) {
        throw new InstallerValidationException(str_replace(':field', $text['admin_name'], $text['field_invalid']));
    }
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || installerLength($email) > 255) {
        throw new InstallerValidationException(str_replace(':field', $text['admin_email'], $text['field_invalid']));
    }
    if ($password !== $confirmation) {
        throw new InstallerValidationException($text['password_confirmation_failed']);
    }
    if (strlen($password) < 12 || ! preg_match('/[a-z]/', $password) || ! preg_match('/[A-Z]/', $password) || ! preg_match('/\d/', $password)) {
        throw new InstallerValidationException($text['password_requirements']);
    }

    return compact('name', 'email', 'password');
}

/**
 * @param  array{host:string,port:int,database:string,username:string,password:string}  $database
 * @param  array{url:string,name:string}  $site
 * @param  array{name:string,email:string,password:string}  $administrator
 */
function performInstallation(string $root, string $publicRoot, string $envExamplePath, string $envPath, string $lockPath, string $installingPath, array $database, array $site, array $administrator, string $language, string $version, string $installToken): string
{
    if (! is_file($envExamplePath)) {
        throw new InstallerOperationException('.env.example is missing.');
    }

    $lockHandle = acquireInstallationLock($installingPath, $installToken);
    $completed = false;

    try {
        $values = [
            'APP_NAME' => $site['name'],
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => $site['url'],
            'APP_LOCALE' => $language,
            'APP_FALLBACK_LOCALE' => $language === 'ru' ? 'en' : 'ru',
            'APP_FORCE_HTTPS' => str_starts_with($site['url'], 'https://') ? 'true' : 'false',
            'SITE_NAME' => $site['name'],
            'SITE_ADMIN_EMAIL' => $administrator['email'],
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $database['host'],
            'DB_PORT' => (string) $database['port'],
            'DB_DATABASE' => $database['database'],
            'DB_USERNAME' => $database['username'],
            'DB_PASSWORD' => $database['password'],
            'SESSION_SECURE_COOKIE' => str_starts_with($site['url'], 'https://') ? 'true' : 'false',
            'MAIL_FROM_ADDRESS' => $administrator['email'],
        ];

        $env = buildEnvironmentContent($envExamplePath, $envPath, $values);
        writeFileAtomically($envPath, $env, 0600);

        foreach (['storage/app', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $directory) {
            if (! ensureWritableDirectory($root.'/'.$directory)) {
                throw new InstallerOperationException('Required directory is not writable: '.$directory);
            }
        }
        foreach (['uploads/account-avatars', 'uploads/game-assets/items/common', 'uploads/game-assets/items/servers', 'uploads/game-assets/characters/common', 'uploads/game-assets/characters/servers'] as $directory) {
            if (! ensureWritableDirectory($publicRoot.'/'.$directory)) {
                throw new InstallerOperationException('Required public directory is not writable: '.$directory);
            }
        }

        require_once $root.'/vendor/autoload.php';
        $app = require $root.'/bootstrap/app.php';
        $app->usePublicPath($publicRoot);
        $kernel = $app->make(ConsoleKernel::class);
        $kernel->bootstrap();

        callArtisanOrFail('migrate', ['--seed' => true, '--force' => true, '--no-interaction' => true]);

        callArtisanOrFail('kaevcms:release-version', ['--mark' => $version]);
        callArtisanOrFail('optimize:clear');

        $owner = DB::transaction(function () use ($administrator, $language): Admin {
            assertNoExistingAdministrators(Admin::query()->lockForUpdate()->get(['id'])->count());

            $created = Admin::query()->create([
                'name' => $administrator['name'],
                'email' => $administrator['email'],
                'password' => Hash::make($administrator['password']),
                'is_active' => true,
                'role' => AdminRole::Owner,
                'locale' => $language,
            ]);
            $created->refresh();

            if (! $created->is_active
                || $created->role !== AdminRole::Owner
                || strtolower($created->email) !== $administrator['email']
                || ! Hash::check($administrator['password'], $created->password)) {
                throw new InstallerOperationException('Owner credentials could not be verified after creation.');
            }

            return $created;
        });

        $lock = json_encode([
            'version' => $version,
            'installed_at' => gmdate(DATE_ATOM),
            'administrator' => $owner->email,
            'source' => 'web-installer',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        writeFileAtomically($lockPath, $lock, 0600);

        $completed = true;

        return (string) $owner->email;
    } finally {
        releaseInstallationLock($lockHandle);
        if ($completed) {
            @unlink($installingPath);
        }
    }
}

/** @param array<string, mixed> $arguments */
function callArtisanOrFail(string $command, array $arguments = []): void
{
    $exitCode = Artisan::call($command, $arguments);
    if ($exitCode !== 0) {
        throw new InstallerOperationException('Artisan command failed: '.$command.'. '.trim(Artisan::output()));
    }
}

/** @return resource */
function acquireInstallationLock(string $path, string $installToken)
{
    $directory = dirname($path);
    if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
        throw new InstallerOperationException('Unable to create installer state directory.');
    }

    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        throw new InstallerOperationException('Unable to open installer state file.');
    }
    if (! flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        throw new InstallerBusyException('Another installation process holds the lock.');
    }

    $payload = json_encode([
        'token_hash' => hash('sha256', $installToken),
        'updated_at' => gmdate(DATE_ATOM),
        'process_id' => getmypid(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    ftruncate($handle, 0);
    rewind($handle);
    if (fwrite($handle, $payload) === false || ! fflush($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
        throw new InstallerOperationException('Unable to update installer state file.');
    }

    return $handle;
}

/** @param resource $handle */
function releaseInstallationLock($handle): void
{
    flock($handle, LOCK_UN);
    fclose($handle);
}

function writeFileAtomically(string $path, string $contents, int $permissions = 0644): void
{
    $directory = dirname($path);
    if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
        throw new InstallerOperationException('Unable to create directory for '.$path.'.');
    }

    $temporary = $path.'.tmp-'.bin2hex(random_bytes(6));
    if (@file_put_contents($temporary, $contents, LOCK_EX) === false) {
        throw new InstallerOperationException('Unable to write temporary file for '.$path.'.');
    }
    @chmod($temporary, $permissions);

    $backup = null;
    if (PHP_OS_FAMILY === 'Windows' && is_file($path)) {
        $backup = $path.'.backup-'.bin2hex(random_bytes(6));
        if (! @rename($path, $backup)) {
            @unlink($temporary);
            throw new InstallerOperationException('Unable to stage the existing '.$path.'.');
        }
    }

    if (! @rename($temporary, $path)) {
        @unlink($temporary);
        if (is_string($backup) && is_file($backup)) {
            @rename($backup, $path);
        }
        throw new InstallerOperationException('Unable to activate '.$path.'.');
    }
    if (is_string($backup)) {
        @unlink($backup);
    }
    @chmod($path, $permissions);
}

/** @param array<string, string> $values */
function buildEnvironmentContent(string $envExamplePath, string $envPath, array $values): string
{
    $currentEnv = is_file($envPath) ? (string) file_get_contents($envPath) : '';
    $currentValues = is_file($envPath) ? parseSimpleEnv($envPath) : [];
    $appKey = trim((string) ($currentValues['APP_KEY'] ?? ''));
    if ($appKey === '') {
        $appKey = 'base64:'.base64_encode(random_bytes(32));
    }

    $env = $currentEnv !== '' ? $currentEnv : (string) file_get_contents($envExamplePath);
    $values = ['APP_KEY' => $appKey] + $values;
    foreach ($values as $key => $value) {
        $env = setEnvValue($env, $key, $value);
    }

    return $env;
}

function setEnvValue(string $content, string $key, string $value): string
{
    $encoded = envEncode($value);
    $line = $key.'='.$encoded;
    $pattern = '/^'.preg_quote($key, '/').'\s*=.*$/m';

    if (preg_match($pattern, $content) === 1) {
        return (string) preg_replace($pattern, $line, $content, 1);
    }

    return rtrim($content)."\n".$line."\n";
}

function envEncode(string $value): string
{
    $escaped = strtr($value, [
        '\\' => '\\\\',
        '"' => '\\"',
        '$' => '\\$',
        "\n" => '\\n',
        "\r" => '\\r',
        "\t" => '\\t',
    ]);

    return '"'.$escaped.'"';
}

function normalizeAppUrl(string $url): string
{
    $url = rtrim(trim($url), '/');
    if (filter_var($url, FILTER_VALIDATE_URL) === false || preg_match('/\Ahttps?:\/\//i', $url) !== 1) {
        throw new InstallerValidationException('Website URL must start with http:// or https://.');
    }

    return $url;
}

function isHttpsRequest(): bool
{
    if ((! empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }

    $forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));

    return $forwardedProto === 'https' && installerTrustedProxyAddress((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
}

function installerTrustedProxyAddress(string $address): bool
{
    if ($address === '' || filter_var($address, FILTER_VALIDATE_IP) === false) {
        return false;
    }

    if (in_array($address, ['127.0.0.1', '::1'], true)) {
        return true;
    }

    return filter_var(
        $address,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
    ) === false;
}

function enforceInstallerRateLimit(array &$state, string $key, int $maximumAttempts, int $windowSeconds, string $message): void
{
    $now = time();
    $rateLimits = is_array($state['rate_limits'] ?? null) ? $state['rate_limits'] : [];
    $attempts = is_array($rateLimits[$key] ?? null) ? $rateLimits[$key] : [];
    $attempts = array_values(array_filter(
        $attempts,
        static fn (mixed $timestamp): bool => is_int($timestamp) && $timestamp > $now - $windowSeconds,
    ));

    if (count($attempts) >= $maximumAttempts) {
        throw new InstallerValidationException($message);
    }

    $attempts[] = $now;
    $rateLimits[$key] = $attempts;
    $state['rate_limits'] = $rateLimits;
}

function detectedAppUrl(): string
{
    $scheme = isHttpsRequest() ? 'https' : 'http';
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/install/index.php'));
    $publicBase = preg_replace('#/install/index\.php$#', '', $script) ?? '';

    return $scheme.'://'.$host.rtrim($publicBase, '/');
}

function installationLockOwnedByToken(string $path, string $installToken): bool
{
    if (! is_file($path) || $installToken === '') {
        return false;
    }

    $payload = json_decode((string) @file_get_contents($path), true);
    $expectedHash = is_array($payload) ? ($payload['token_hash'] ?? null) : null;

    return is_string($expectedHash)
        && preg_match('/\A[a-f0-9]{64}\z/', $expectedHash) === 1
        && hash_equals($expectedHash, hash('sha256', $installToken));
}

function installerIsLocked(string $lockPath, string $installingPath, string $envPath): bool
{
    if (is_file($lockPath)) {
        return true;
    }
    if (is_file($installingPath)) {
        return false;
    }

    return is_file($envPath) || existingInstallationDetected($envPath);
}

function existingInstallationDetected(string $envPath): bool
{
    if (! is_file($envPath)) {
        return false;
    }

    $values = parseSimpleEnv($envPath);
    $connection = strtolower($values['DB_CONNECTION'] ?? '');

    try {
        if ($connection === 'mysql') {
            $database = [
                'host' => $values['DB_HOST'] ?? '127.0.0.1',
                'port' => (int) ($values['DB_PORT'] ?? 3306),
                'database' => $values['DB_DATABASE'] ?? '',
                'username' => $values['DB_USERNAME'] ?? '',
                'password' => $values['DB_PASSWORD'] ?? '',
            ];
            if ($database['database'] === '' || $database['username'] === '') {
                return false;
            }
            $pdo = openDatabaseConnection($database);
            $statement = $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = \'admins\'');
            if ((int) $statement->fetchColumn() < 1) {
                return false;
            }

            return (int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0;
        }

        if ($connection === 'sqlite' && extension_loaded('pdo_sqlite')) {
            $databasePath = $values['DB_DATABASE'] ?? 'database/database.sqlite';
            if ($databasePath === '') {
                $databasePath = 'database/database.sqlite';
            }
            if (! str_starts_with($databasePath, '/') && preg_match('/\A[A-Za-z]:[\\\\\/]/', $databasePath) !== 1) {
                $databasePath = dirname($envPath).'/'.ltrim($databasePath, '/\\');
            }
            if (! is_file($databasePath)) {
                return false;
            }

            $pdo = new PDO('sqlite:'.$databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $statement = $pdo->query('SELECT COUNT(*) FROM sqlite_master WHERE type = \'table\' AND name = \'admins\'');
            if ((int) $statement->fetchColumn() < 1) {
                return false;
            }

            return (int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0;
        }
    } catch (Throwable) {
        return false;
    }

    return false;
}

/** @return array<string,string> */
function parseSimpleEnv(string $path): array
{
    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $value = trim($value);
        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            $value = strtr(substr($value, 1, -1), [
                '\\n' => "\n",
                '\\r' => "\r",
                '\\t' => "\t",
                '\\$' => '$',
                '\\"' => '"',
                '\\\\' => '\\',
            ]);
        } elseif (strlen($value) >= 2 && $value[0] === '\'' && str_ends_with($value, '\'')) {
            $value = str_replace(['\\\'', '\\\\'], ['\'', '\\'], substr($value, 1, -1));
        }
        $values[trim($key)] = $value;
    }

    return $values;
}

function sendInstallerSecurityHeaders(): void
{
    header_remove('X-Powered-By');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; img-src \'self\' data:; form-action \'self\'; base-uri \'none\'; frame-ancestors \'none\'');
}

function startInstallerSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_name('kaevcms_installer');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => isHttpsRequest(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (! session_start()) {
        throw new InstallerOperationException('Unable to start installer session.');
    }
}

function verifyCsrf(string $provided, string $expected): void
{
    if ($provided === '' || ! hash_equals($expected, $provided)) {
        throw new InstallerValidationException('Installer session expired. Reload the page and try again.');
    }
}

function redirectTo(string $step, string $language): never
{
    header('Location: ?step='.rawurlencode($step).'&lang='.rawurlencode($language), true, 303);
    exit;
}

function installerStepForAction(string $action, string $fallback): string
{
    return match ($action) {
        'start', 'recheck' => 'requirements',
        'test_database', 'continue_database' => 'database',
        'install' => 'administrator',
        default => $fallback,
    };
}

function consumeInstallerFlash(array &$state, string $key): ?string
{
    if (! isset($state[$key]) || ! is_string($state[$key])) {
        unset($state[$key]);

        return null;
    }

    $value = $state[$key];
    unset($state[$key]);

    return $value;
}

function publicInstallerError(Throwable $exception, array $text, string $reference): string
{
    if ($exception instanceof InstallerValidationException) {
        return installerSlice($exception->getMessage(), 0, 500);
    }
    if ($exception instanceof InstallerDatabaseConnectionException) {
        return $text['database_connection_failed'];
    }
    if ($exception instanceof InstallerDatabasePrivilegeException) {
        return $text['database_privileges_failed'];
    }
    if ($exception instanceof InstallerExistingInstallationException) {
        return $text['database_existing_installation'];
    }
    if ($exception instanceof InstallerBusyException) {
        return $text['installer_busy'];
    }

    return str_replace(':reference', $reference, $text['unexpected_error']);
}

function writeInstallerLog(string $root, Throwable $exception, ?string $password, string $reference): void
{
    $directory = $root.'/storage/logs';
    if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
        return;
    }

    $details = sprintf(
        "[%s] [%s] %s\n%s\n",
        gmdate(DATE_ATOM),
        $reference,
        formatThrowableForLog($exception),
        $exception->getTraceAsString(),
    );
    if (is_string($password) && $password !== '') {
        $details = str_replace($password, '***', $details);
    }

    @file_put_contents($directory.'/installer.log', $details."\n", FILE_APPEND | LOCK_EX);
    @chmod($directory.'/installer.log', 0600);
}

function formatThrowableForLog(Throwable $exception): string
{
    $lines = [];
    $current = $exception;
    do {
        $lines[] = $current::class.': '.$current->getMessage();
        $current = $current->getPrevious();
    } while ($current instanceof Throwable);

    return implode("\nCaused by: ", $lines);
}

function installerLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function installerSlice(string $value, int $offset, int $length): string
{
    return function_exists('mb_substr') ? mb_substr($value, $offset, $length) : substr($value, $offset, $length);
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrfField(array $state): string
{
    return '<input type="hidden" name="_token" value="'.e((string) $state['csrf']).'">';
}

function welcomeBody(array $text, array $state): string
{
    return '<section class="hero"><span class="eyebrow">Open source · Lineage II</span><h1>'.e($text['welcome_title']).'</h1><p>'.e($text['welcome_text']).'</p></section>'
        .'<form method="post" class="actions">'.csrfField($state).'<input type="hidden" name="action" value="start"><button class="button primary">'.e($text['begin']).'</button></form>';
}

/** @param list<array{label:string,ok:bool,details:string}> $checks */
function requirementsBody(array $text, array $checks, array $state): string
{
    $rows = '';
    foreach ($checks as $check) {
        $warning = (bool) ($check['warning'] ?? false);
        $statusClass = ! $check['ok'] ? 'bad' : ($warning ? 'warning' : 'ok');
        $statusLabel = ! $check['ok'] ? $text['failed'] : ($warning ? $text['warning'] : $text['ok']);
        $rows .= '<tr><td>'.e($check['label']).'</td><td><span class="status '.$statusClass.'">'.e($statusLabel).'</span></td><td>'.e($check['details']).'</td></tr>';
    }

    $next = hasFailedRequirements($checks)
        ? '<button class="button" name="action" value="recheck">'.e($text['recheck']).'</button>'
        : '<a class="button primary" href="?step=database&amp;lang='.e((string) ($_GET['lang'] ?? 'ru')).'">'.e($text['next']).'</a>';

    return '<h1>'.e($text['requirements_title']).'</h1><p>'.e($text['requirements_text']).'</p>'
        .'<div class="table-wrap"><table><thead><tr><th>'.e($text['component']).'</th><th>'.e($text['status']).'</th><th>'.e($text['details']).'</th></tr></thead><tbody>'.$rows.'</tbody></table></div>'
        .'<form method="post" class="actions">'.csrfField($state).$next.'</form>';
}

function databaseBody(array $text, array $state): string
{
    $db = $state['database'] ?? ['host' => '127.0.0.1', 'port' => 3306, 'database' => '', 'username' => '', 'password' => ''];
    $site = $state['site'] ?? ['url' => detectedAppUrl(), 'name' => 'KaevCMS'];
    $httpsWarning = isHttpsRequest() ? '' : '<div class="alert alert-warning">'.e($text['https_warning']).'</div>';
    $passwordHint = ($db['password'] ?? '') !== '' ? '<small>'.e($text['password_saved_hint']).'</small>' : '';

    return '<h1>'.e($text['database_title']).'</h1><p>'.e($text['database_text']).'</p>'.$httpsWarning
        .'<form method="post" class="form-grid">'.csrfField($state)
        .field($text['site_name'], 'site_name', $site['name'], 'text', 'organization')
        .field($text['site_url'], 'app_url', $site['url'], 'url', 'url')
        .field($text['db_host'], 'db_host', $db['host'], 'text', 'off')
        .field($text['db_port'], 'db_port', (string) $db['port'], 'number', 'off')
        .field($text['db_name'], 'db_database', $db['database'], 'text', 'off')
        .field($text['db_user'], 'db_username', $db['username'], 'text', 'username')
        .field($text['db_password'], 'db_password', '', 'password', 'current-password', $passwordHint, false)
        .'<div class="actions span-2"><a class="button" href="?step=requirements">'.e($text['back']).'</a><button class="button" name="action" value="test_database">'.e($text['test_connection']).'</button><button class="button primary" name="action" value="continue_database">'.e($text['next']).'</button></div></form>';
}

function administratorBody(array $text, array $state): string
{
    $httpsWarning = isHttpsRequest() ? '' : '<div class="alert alert-warning">'.e($text['https_warning']).'</div>';
    $draft = $state['administrator_draft'] ?? ['name' => '', 'email' => ''];

    return '<h1>'.e($text['administrator_title']).'</h1><p>'.e($text['administrator_text']).'</p>'.$httpsWarning
        .'<form method="post" class="form-grid">'.csrfField($state)
        .field($text['admin_name'], 'admin_name', (string) ($draft['name'] ?? ''), 'text', 'name')
        .field($text['admin_email'], 'admin_email', (string) ($draft['email'] ?? ''), 'email', 'email')
        .field($text['admin_password'], 'admin_password', '', 'password', 'new-password')
        .field($text['admin_password_confirmation'], 'admin_password_confirmation', '', 'password', 'new-password')
        .'<div class="actions span-2"><a class="button" href="?step=database">'.e($text['back']).'</a><button class="button primary" name="action" value="install">'.e($text['install']).'</button></div></form>';
}

/** @return list<array{label:string,status:string,details:string}> */
function postInstallSecurityChecks(string $root, string $publicRoot, string $envPath, string $lockPath, array $text): array
{
    $envValues = is_file($envPath) ? parseSimpleEnv($envPath) : [];
    $debugValue = strtolower(trim((string) ($envValues['APP_DEBUG'] ?? '')));
    $debugDisabled = in_array($debugValue, ['false', '0', 'off', 'no'], true);
    $httpsConfigured = strtolower(trim((string) ($envValues['APP_FORCE_HTTPS'] ?? ''))) === 'true'
        && strtolower(trim((string) ($envValues['SESSION_SECURE_COOKIE'] ?? ''))) === 'true';

    $coreSafe = ! installerPathInside($root, $publicRoot);
    $envSafe = is_file($envPath) && ! installerPathInside($envPath, $publicRoot);
    $storageWritable = is_dir($root.'/storage') && is_writable($root.'/storage');
    $cacheWritable = is_dir($root.'/bootstrap/cache') && is_writable($root.'/bootstrap/cache');
    $uploadsWritable = is_dir($publicRoot.'/uploads') && is_writable($publicRoot.'/uploads');

    $envMode = installerPermissionMode($envPath);
    $envPermissionsStatus = $envMode === null
        ? 'warning'
        : (installerSensitivePermissionsAreBroad($envPath) ? 'warning' : 'ok');
    $envPermissionsText = $envMode === null
        ? $text['security_permission_unknown']
        : ($envPermissionsStatus === 'ok' ? $text['security_permission_preferred'] : $text['security_permission_broad']);

    return [
        [
            'label' => $text['security_core_location'],
            'status' => $coreSafe ? 'ok' : 'error',
            'details' => ($coreSafe ? $text['private_core_safe'] : $text['private_core_unsafe']).' '.installerDisplayPath($root),
        ],
        [
            'label' => $text['security_env_location'],
            'status' => $envSafe ? 'ok' : 'error',
            'details' => ($envSafe ? $text['security_outside_public'] : $text['security_inside_public']).' '.installerDisplayPath($envPath),
        ],
        [
            'label' => $text['security_debug'],
            'status' => $debugDisabled ? 'ok' : 'error',
            'details' => $debugDisabled ? $text['security_debug_disabled'] : $text['security_debug_enabled'],
        ],
        [
            'label' => $text['security_https'],
            'status' => $httpsConfigured ? 'ok' : 'warning',
            'details' => $httpsConfigured ? $text['security_https_enabled'] : $text['security_https_disabled'],
        ],
        [
            'label' => $text['security_storage'],
            'status' => $storageWritable ? (installerWorldWritable($root.'/storage') ? 'warning' : 'ok') : 'error',
            'details' => ($storageWritable ? $text['security_writable'] : $text['security_not_writable']).' '.installerPermissionLabel($root.'/storage'),
        ],
        [
            'label' => $text['security_cache'],
            'status' => $cacheWritable ? (installerWorldWritable($root.'/bootstrap/cache') ? 'warning' : 'ok') : 'error',
            'details' => ($cacheWritable ? $text['security_writable'] : $text['security_not_writable']).' '.installerPermissionLabel($root.'/bootstrap/cache'),
        ],
        [
            'label' => $text['security_uploads'],
            'status' => $uploadsWritable ? (installerWorldWritable($publicRoot.'/uploads') ? 'warning' : 'ok') : 'error',
            'details' => ($uploadsWritable ? $text['security_writable'] : $text['security_not_writable']).' '.installerPermissionLabel($publicRoot.'/uploads'),
        ],
        [
            'label' => $text['security_env_permissions'],
            'status' => $envPermissionsStatus,
            'details' => $envPermissionsText.' '.installerPermissionLabel($envPath),
        ],
        [
            'label' => $text['security_lock'],
            'status' => is_file($lockPath) ? 'ok' : 'error',
            'details' => is_file($lockPath) ? $text['security_lock_present'] : $text['security_lock_missing'],
        ],
    ];
}

/** @param list<array{label:string,status:string,details:string}> $checks */
function securityReviewBody(array $text, array $checks): string
{
    if ($checks === []) {
        return '';
    }

    $rows = '';
    foreach ($checks as $check) {
        $status = in_array($check['status'], ['ok', 'warning', 'error'], true) ? $check['status'] : 'error';
        $class = $status === 'error' ? 'bad' : $status;
        $label = match ($status) {
            'ok' => $text['security_review_ok'],
            'warning' => $text['security_review_warning'],
            default => $text['security_review_error'],
        };
        $rows .= '<tr><td>'.e($check['label']).'</td><td><span class="status '.$class.'">'.e($label).'</span></td><td>'.e($check['details']).'</td></tr>';
    }

    return '<section class="security-review"><h2>'.e($text['security_review_title']).'</h2><p>'.e($text['security_review_text']).'</p><div class="table-wrap"><table><thead><tr><th>'.e($text['component']).'</th><th>'.e($text['status']).'</th><th>'.e($text['details']).'</th></tr></thead><tbody>'.$rows.'</tbody></table></div></section>';
}

function completeBody(array $text, array $state): string
{
    $complete = $state['complete'];
    $securityChecks = is_array($complete['security_checks'] ?? null) ? $complete['security_checks'] : [];

    return '<section class="hero"><span class="success-mark">✓</span><h1>'.e($text['complete_title']).'</h1><p>'.e($text['complete_text']).'</p></section>'
        .'<dl class="summary"><div><dt>'.e($text['admin_panel']).'</dt><dd><a href="'.e($complete['admin_url']).'">'.e($complete['admin_url']).'</a></dd></div><div><dt>'.e($text['owner']).'</dt><dd>'.e($complete['email']).'</dd></div></dl>'
        .securityReviewBody($text, $securityChecks)
        .'<p class="muted">'.e($text['finish_note']).'</p><div class="actions"><a class="button primary" href="'.e($complete['admin_url']).'">'.e($text['admin_panel']).'</a><a class="button" href="'.e($complete['site_url']).'">'.e($text['open_site']).'</a></div>';
}

/** @param list<array{label:string,status:string,details:string}> $securityChecks */
function installedBody(array $text, array $securityChecks = []): string
{
    $url = detectedAppUrl();

    return '<section class="hero"><span class="success-mark">✓</span><h1>'.e($text['installed_title']).'</h1><p>'.e($text['installed_text']).'</p></section>'
        .securityReviewBody($text, $securityChecks)
        .'<div class="actions"><a class="button primary" href="'.e($url.'/').'">'.e($text['open_site']).'</a></div>';
}

function field(string $label, string $name, string $value, string $type = 'text', string $autocomplete = 'off', string $hint = '', bool $required = true): string
{
    $requiredAttribute = $required ? ' required' : '';

    return '<label><span>'.e($label).'</span><input type="'.e($type).'" name="'.e($name).'" value="'.e($value).'"'.$requiredAttribute.' autocomplete="'.e($autocomplete).'">'.$hint.'</label>';
}

function renderPage(string $title, string $body, string $language, string $version, int $status = 200): void
{
    http_response_code($status);
    $otherLanguage = $language === 'ru' ? 'en' : 'ru';
    $otherLabel = strtoupper($otherLanguage);
    echo '<!doctype html><html lang="'.e($language).'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>'.e($title).'</title><style>'.installerCss().'</style></head><body><main class="shell"><header><a class="brand" href="?step=welcome&amp;lang='.e($language).'">KaevCMS</a><div><span class="version">v'.e($version).'</span><a class="language" href="?step='.e((string) ($_GET['step'] ?? 'welcome')).'&amp;lang='.e($otherLanguage).'">'.e($otherLabel).'</a></div></header><article class="card">'.$body.'</article><footer>KaevCMS · MIT License</footer></main></body></html>';
}

function installerCss(): string
{
    return <<<'CSS'
:root{color-scheme:light;--bg:#f4efe7;--card:#fffdf9;--ink:#28231f;--muted:#766d64;--line:#e5d9cc;--accent:#8d5f3d;--accent2:#6e452b;--ok:#2e7d52;--bad:#a13d3d}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top,#fffaf1 0,#f4efe7 45%,#ece4d9 100%);color:var(--ink);font:16px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif;min-height:100vh}.shell{width:min(980px,calc(100% - 32px));margin:0 auto;padding:34px 0}header{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}.brand{font:700 24px Georgia,serif;color:var(--ink);text-decoration:none}.version,.language{display:inline-flex;padding:7px 10px;border:1px solid var(--line);border-radius:999px;background:#fff9;color:var(--muted);font-size:13px}.language{margin-left:8px;text-decoration:none;color:var(--accent2);font-weight:700}.card{background:var(--card);border:1px solid var(--line);border-radius:24px;padding:clamp(24px,5vw,52px);box-shadow:0 20px 70px #59432a1a}.hero{max-width:700px}.eyebrow{display:inline-block;color:var(--accent);font-weight:700;text-transform:uppercase;letter-spacing:.08em;font-size:12px}h1{font:700 clamp(30px,5vw,48px)/1.1 Georgia,serif;margin:12px 0 16px}h2{font:700 26px/1.2 Georgia,serif;margin:0 0 10px}.security-review{margin-top:32px;padding-top:28px;border-top:1px solid var(--line)}p{color:var(--muted);max-width:760px}.actions{display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-top:28px}.button{appearance:none;border:1px solid var(--line);border-radius:12px;background:#fff;color:var(--ink);font:700 15px system-ui;padding:12px 18px;text-decoration:none;cursor:pointer}.button:hover{transform:translateY(-1px);box-shadow:0 8px 24px #4f38221a}.button.primary{background:var(--accent);border-color:var(--accent);color:#fff}.button.primary:hover{background:var(--accent2)}.table-wrap{overflow:auto;border:1px solid var(--line);border-radius:15px;margin-top:24px}table{border-collapse:collapse;width:100%;min-width:600px}th,td{text-align:left;padding:13px 16px;border-bottom:1px solid var(--line)}th{font-size:13px;color:var(--muted);background:#f8f2ea}.status{display:inline-flex;border-radius:999px;padding:4px 9px;font-size:12px;font-weight:800}.status.ok{color:var(--ok);background:#e8f5ed}.status.bad{color:var(--bad);background:#faeaea}.status.warning{color:#805b18;background:#fff3d6}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:26px}.form-grid label{display:grid;gap:7px;color:var(--muted);font-size:13px;font-weight:700}.form-grid input{width:100%;border:1px solid var(--line);border-radius:12px;background:#fff;padding:12px 13px;color:var(--ink);font:16px system-ui;outline:none}.form-grid input:focus{border-color:var(--accent);box-shadow:0 0 0 3px #8d5f3d18}.span-2{grid-column:1/-1}.alert{padding:13px 15px;border-radius:12px;margin-bottom:20px}.alert-error{color:var(--bad);background:#faeaea;border:1px solid #edcaca}.alert-success{color:var(--ok);background:#e8f5ed;border:1px solid #cae7d5}.alert-warning{color:#805b18;background:#fff3d6;border:1px solid #ead39a}.form-grid small{font-size:12px;font-weight:500;color:var(--muted)}.success-mark{display:grid;place-items:center;width:56px;height:56px;border-radius:50%;background:#e8f5ed;color:var(--ok);font-size:28px;font-weight:900}.summary{display:grid;gap:0;border:1px solid var(--line);border-radius:15px;overflow:hidden;margin-top:24px}.summary div{display:grid;grid-template-columns:190px 1fr;gap:14px;padding:14px 16px;border-bottom:1px solid var(--line)}.summary div:last-child{border-bottom:0}.summary dt{color:var(--muted);font-weight:700}.summary dd{margin:0}.summary a{color:var(--accent2)}.muted{font-size:14px}footer{text-align:center;color:var(--muted);font-size:13px;padding:20px}@media(max-width:680px){.shell{width:min(100% - 18px,980px);padding-top:14px}.card{border-radius:18px;padding:24px 18px}.form-grid{grid-template-columns:1fr}.span-2{grid-column:auto}.summary div{grid-template-columns:1fr;gap:4px}.actions .button{width:100%;text-align:center}}
CSS;
}
