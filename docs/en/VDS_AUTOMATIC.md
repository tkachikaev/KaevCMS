# Automatic Linux server preparation

Run `deployment/vds/install.sh` from an extracted full release or checkout with `composer.lock` on a **dedicated server**. It provisions nginx, PHP-FPM, MariaDB, production Composer dependencies, file permissions and systemd services. Finish CMS installation with the existing `/install/` Web Installer. The script does not create the CMS `.env`, application tables or owner account and never activates payments.

| System | Selected PHP |
| --- | --- |
| Ubuntu 24.04 LTS | 8.3 |
| Ubuntu 26.04 LTS | 8.5 |
| Debian 13 | 8.4 |

Native OS repositories are used. Other distributions are rejected before changing packages. Node.js is not required. One managed CMS per server is supported.

```bash
cd /root/kaevcms-source
sudo bash deployment/vds/install.sh --plan
sudo bash deployment/vds/install.sh --host example.com
```

Use the server IPv4 instead of a domain for HTTP testing. The destination defaults to `/var/www/kaevcms`; `--root /var/www/other-name` selects an empty direct child of `/var/www`. The domain/IP is requested interactively if omitted. Existing CMS installations or nginx sites are not overwritten.

The unfinished Web Installer accepts only localhost and the SSH client's IPv4. If your browser uses another public IPv4, supply `--installer-ip ADDRESS` during preparation. For proxies, use a direct connection or `ssh -L 8080:127.0.0.1:80 user@SERVER_IP`, then open `http://127.0.0.1:8080/install/`. Enter the real site URL in the wizard, not the tunnel URL.

Get the generated database credentials in your private SSH terminal:

```bash
sudo bash /var/www/kaevcms/deployment/vds/install.sh --credentials
```

Enter these credentials in the Web Installer and create the owner there. Root-only provisioning state is retained at `/var/lib/kaevcms-installer/state.json` (mode `0600`). Do not publish the credential output or delete state to rerun the installer.

Only `public/` is served by nginx. Source files belong to the `kaevcms` system account, while PHP runs as `www-data`. Writable runtime directories are `storage`, `bootstrap/cache` and `public/uploads`. The Web Installer temporarily needs parent-directory write access to atomically create `.env` and remove `public/install`. After its completion, a root-owned helper outside the site tree closes these temporary permissions and sets `.env`/`installed.lock` to `0640`. Completion is checked once per minute.

Queue and scheduler timers wait for `storage/app/installed.lock`. The queue explicitly handles `mail-probe,mail,default`. The script does not change SSH/firewall rules, perform a full OS upgrade or reboot. Allow HTTP/HTTPS separately in the firewall/provider console if required.

For a domain already pointing to this server, optional HTTPS uses Certbot:

```bash
sudo bash deployment/vds/install.sh --host example.com \
  --https --email admin@example.com --accept-acme-terms
```

The final flag explicitly accepts the [Let's Encrypt subscriber terms](https://letsencrypt.org/repository/); otherwise interactive acceptance is required. Ports 80/443 must be reachable. Use `https://example.com` in the Web Installer. Automatic renewal is enabled through `certbot.timer`.

After successful preparation, rerunning checks services without recopying source or changing database passwords, nginx configuration, `.env`, application data or CMS accounts. Use nginx/Certbot directly to change the address or add HTTPS later. An interrupted preparation can be resumed; inconsistent state requires manual inspection and never triggers data deletion. Updates use the existing CMS updater/full-deployment procedure. Install the optional VDS update agent separately after CMS installation.

```bash
systemctl status nginx mariadb php8.5-fpm
systemctl list-timers 'kaevcms-*'
journalctl -u kaevcms-queue -u kaevcms-scheduler -u kaevcms-finish-setup -n 50
```

Use `php8.3-fpm` on Ubuntu 24.04 or `php8.4-fpm` on Debian 13.
