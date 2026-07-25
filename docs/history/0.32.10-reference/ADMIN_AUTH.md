# Administrator authentication

KaevCMS uses a separate `admins` table and a separate Laravel session guard named `admin`. Gaming accounts and future website user accounts are not administrators.

## First administrator

After migrations have completed, create the first administrator interactively:

```powershell
php artisan kaevcms:admin-create
```

The command does not accept a password argument, so the password is not written to PowerShell history or the process command line.

Password requirements:

- at least 12 characters;
- uppercase and lowercase letters;
- at least one number.

The password is stored using the configured Laravel hasher. The default `auto` mode prefers Argon2id and falls back to bcrypt only when the active PHP build does not provide Argon2id.

## Routes

- `GET /admin/login` — login form;
- `POST /admin/login` — authentication;
- `GET /admin/two-factor-challenge` — TOTP or recovery-code challenge;
- `POST /admin/two-factor-challenge` — challenge verification;
- `GET /admin/account/security` — current administrator security settings;
- `GET /admin` — protected dashboard;
- `POST /admin/logout` — logout.

## Login protection

The login limiter combines normalized email and client IP. Defaults:

```env
ADMIN_LOGIN_MAX_ATTEMPTS=5
ADMIN_LOGIN_DECAY_SECONDS=60
```

Every password and second-factor result that reaches the authentication controller is written to `admin_login_logs`. Requests stopped by route rate limiters do not create database rows. Passwords, TOTP secrets and recovery codes are never logged.

## Two-factor authentication

Two-factor authentication is optional and configured separately by each administrator at `/admin/account/security`.

Setup flow:

1. confirm the current password;
2. scan the locally rendered QR code or enter the manual key;
3. verify a six-digit TOTP code;
4. save the eight one-time recovery codes.

The TOTP secret uses the standard 30-second SHA-1 profile. It is encrypted with `APP_KEY`. Recovery codes are stored as password hashes and cannot be displayed again. Losing `APP_KEY` makes encrypted TOTP secrets unusable, so the application key must be backed up securely.

Enabling or disabling 2FA invalidates the administrator’s other active sessions. When 2FA is enabled, a valid password creates only a temporary ten-minute challenge. The administrator guard is authenticated only after a valid TOTP or unused recovery code. The challenge has separate per-minute and per-hour rate limits.

Emergency console reset:

```powershell
php artisan kaevcms:admin-2fa:disable admin@example.com
```

The reset removes the secret and recovery codes and invalidates existing sessions for that administrator.

## Administrator management

After creating the first account, additional administrators are created and managed at `/admin/administrators`. Accounts are disabled instead of being physically deleted. The current administrator and the last active administrator cannot be disabled.

Details: [ADMINISTRATORS.md](ADMINISTRATORS.md).
