<?php

namespace App\Services\Security;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Crypt;
use Throwable;

final class EncryptionHealth
{
    public function __construct(private readonly DatabaseManager $database) {}

    /**
     * @return array{
     *     app_key_configured: bool,
     *     state: string,
     *     status: string,
     *     details: string,
     *     encrypted_values_total: int,
     *     invalid_values_total: int,
     *     categories: list<array{key: string, label: string, saved: int, invalid: int}>
     * }
     */
    public function inspect(): array
    {
        $appKeyConfigured = trim((string) config('app.key', '')) !== '';

        if (! $appKeyConfigured) {
            return [
                'app_key_configured' => false,
                'state' => 'danger',
                'status' => __('APP_KEY is missing'),
                'details' => __('Generate APP_KEY before saving encrypted passwords or enabling administrator 2FA.'),
                'encrypted_values_total' => 0,
                'invalid_values_total' => 0,
                'categories' => [],
            ];
        }

        try {
            $categories = [
                $this->inspectSetting('mail_password', __('SMTP password'), 'mail.password_encrypted'),
                $this->inspectColumn('login_server_passwords', __('LoginServer database passwords'), 'login_servers', 'database_password'),
                $this->inspectColumn('game_server_passwords', __('GameServer database passwords'), 'game_servers', 'database_password'),
                $this->inspectColumn('admin_two_factor_secrets', __('Administrator 2FA secrets'), 'admins', 'two_factor_secret'),
                $this->inspectColumn('admin_recovery_codes', __('Administrator recovery codes'), 'admins', 'two_factor_recovery_codes'),
            ];
        } catch (Throwable) {
            return [
                'app_key_configured' => true,
                'state' => 'danger',
                'status' => __('Encryption check unavailable'),
                'details' => __('Encrypted values could not be inspected because the CMS database is unavailable.'),
                'encrypted_values_total' => 0,
                'invalid_values_total' => 0,
                'categories' => [],
            ];
        }

        $saved = (int) array_sum(array_column($categories, 'saved'));
        $invalid = (int) array_sum(array_column($categories, 'invalid'));

        if ($invalid > 0) {
            $state = 'danger';
            $status = __('Encrypted values unavailable');
            $details = trans_choice(
                ':count encrypted value cannot be decrypted with the current APP_KEY.|:count encrypted values cannot be decrypted with the current APP_KEY.',
                $invalid,
                ['count' => $invalid],
            );
        } elseif ($saved > 0) {
            $state = 'success';
            $status = __('Encryption is healthy');
            $details = trans_choice(
                ':count encrypted value was checked successfully.|:count encrypted values were checked successfully.',
                $saved,
                ['count' => $saved],
            );
        } else {
            $state = 'success';
            $status = __('Encryption is ready');
            $details = __('APP_KEY is configured. No encrypted values are currently stored.');
        }

        return [
            'app_key_configured' => $appKeyConfigured,
            'state' => $state,
            'status' => $status,
            'details' => $details,
            'encrypted_values_total' => $saved,
            'invalid_values_total' => $invalid,
            'categories' => $categories,
        ];
    }

    /** @return array{key: string, label: string, saved: int, invalid: int} */
    private function inspectSetting(string $key, string $label, string $settingKey): array
    {
        $connection = $this->database->connection();
        if (! $connection->getSchemaBuilder()->hasTable('cms_settings')) {
            return $this->category($key, $label, []);
        }

        $value = $connection->table('cms_settings')
            ->where('key', $settingKey)
            ->value('value');

        return $this->category($key, $label, [$value]);
    }

    /** @return array{key: string, label: string, saved: int, invalid: int} */
    private function inspectColumn(string $key, string $label, string $table, string $column): array
    {
        $connection = $this->database->connection();
        $schema = $connection->getSchemaBuilder();
        if (! $schema->hasTable($table) || ! $schema->hasColumn($table, $column)) {
            return $this->category($key, $label, []);
        }

        $values = $connection->table($table)
            ->whereNotNull($column)
            ->where($column, '<>', '')
            ->pluck($column)
            ->all();

        return $this->category($key, $label, $values);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array{key: string, label: string, saved: int, invalid: int}
     */
    private function category(string $key, string $label, array $values): array
    {
        $saved = 0;
        $invalid = 0;

        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $saved++;

            try {
                Crypt::decryptString($value);
            } catch (Throwable) {
                $invalid++;
            }
        }

        return [
            'key' => $key,
            'label' => $label,
            'saved' => $saved,
            'invalid' => $invalid,
        ];
    }
}
