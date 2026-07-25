# Security and file permissions

## Deployment boundary

Only the generated public directory may be reachable through the web server. `.env`, `vendor`, application code, logs, database backups, and installer logs belong to the private core.

## Recommended permissions

Typical Linux values:

```text
Regular files                         0644
Regular directories                   0755
storage/                              0755 or 0775
bootstrap/cache/                      0755 or 0775
public uploads/                       0755 or 0775
.env                                  0600 or 0640
storage/app/installed.lock            0600 or 0640
```

Do not recursively assign `0777`. KaevCMS checks actual write access and reports world-writable directories as warnings.

Web/CLI Updater backups use `0700` directories and `0600` SQL dump/metadata files on Linux. The public `uploads` directory contains Apache protection against PHP-like executable files; on nginx, allow PHP only for `index.php` and `install/index.php`.

Permissions are only one layer. On hosting platforms with ACLs or isolated account users, the numeric mode may not fully describe effective access. The Web Installer therefore shows both write tests and best-effort POSIX modes.

## Web Installer security review

After installation the wizard checks:

- private core location;
- `.env` location and permissions;
- `APP_DEBUG=false`;
- HTTPS and secure cookies;
- writable runtime directories;
- public upload permissions;
- `installed.lock` presence.

Critical failures are red. Non-blocking hardening recommendations are yellow.

## Operational rules

- Enable HTTPS for a public website. Installation over HTTP is allowed, but submitted values are transmitted without encryption.
- Use a dedicated empty database and database user.
- Keep production dependencies only.
- Do not publish release ZIPs, backups, `.env`, or installer logs.
- Review the audit log and runtime diagnostics.
- Run dependency audits when internet access is available.
