<?php

namespace Tests\Feature;

use Tests\TestCase;

class VdsDocumentationReleaseTest extends TestCase
{
    public function test_release_contains_matching_ubuntu_vds_guides(): void
    {
        $english = $this->readReleaseFile('docs/en/VDS_UBUNTU.md');
        $russian = $this->readReleaseFile('docs/ru/VDS_UBUNTU.md');

        foreach ([$english, $russian] as $guide) {
            foreach ([
                'Ubuntu Server 24.04 LTS',
                '/var/www/kaevcms/public',
                'php8.3-fpm',
                'composer install --no-dev --optimize-autoloader --no-interaction',
                'composer check-platform-reqs --no-dev',
                'client_max_body_size 512m',
                'fastcgi_pass unix:/run/php/php8.3-fpm.sock;',
                'location = /index.php',
                'location = /install/index.php',
                'location ^~ /uploads/',
                'php artisan kaevcms:update /tmp/KaevCMS-update.zip',
                'certbot --nginx',
                '/etc/cron.d/kaevcms',
                '/etc/systemd/system/kaevcms-queue.service',
                'chmod 775 /var/www/kaevcms',
                'chmod 755 /var/www/kaevcms',
                'chmod 640 /var/www/kaevcms/.env',
                '/var/www/kaevcms/.env',
                '/etc/nginx/sites-available/kaevcms',
                'APP_FORCE_HTTPS=false',
                'SESSION_SECURE_COOKIE=false',
                '419 Page Expired',
                'public/uploads/news',
                'public/uploads/pages',
                'public/uploads/settings',
                'chmod -R 777 /var/www/kaevcms',
            ] as $required) {
                $this->assertStringContainsString($required, $guide);
            }

            $this->assertStringNotContainsString('root /var/www/kaevcms;', $guide);
        }

        $this->assertStringContainsString('Ubuntu 26.04 LTS ships PHP 8.5', $english);
        $this->assertStringContainsString('Ubuntu 26.04 LTS поставляется с PHP 8.5', $russian);
        $this->assertStringContainsString('http://192.168.50.111/install/', $english);
        $this->assertStringContainsString('http://192.168.50.111/install/', $russian);
        $this->assertStringContainsString('The HTTP warning does not block the database check or final installation.', $english);
        $this->assertStringContainsString('Предупреждение об HTTP не блокирует проверку базы или завершение установки.', $russian);
    }

    private function readReleaseFile(string $path): string
    {
        $contents = file_get_contents(base_path($path));
        $this->assertIsString($contents);

        return $contents;
    }
}
