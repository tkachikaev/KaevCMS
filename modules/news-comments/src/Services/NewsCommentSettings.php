<?php

namespace KaevCMS\Modules\NewsComments\Services;

use App\Models\Admin;
use App\Models\News;
use App\Support\Database\TableExistence;
use KaevCMS\Modules\NewsComments\Models\NewsCommentNewsSetting;
use KaevCMS\Modules\NewsComments\Models\NewsCommentSetting;
use RuntimeException;
use Throwable;

final class NewsCommentSettings
{
    public const MODE_MANUAL = 'manual_review';

    public const MODE_AUTOMATIC = 'automatic';

    /** @var array<string, array{0: int, 1: int, 2: int}> */
    public const LIMITS = [
        'max_comments_per_day' => [20, 1, 200],
        'max_comments_per_news_per_day' => [5, 1, 100],
        'min_submission_interval_seconds' => [60, 0, 3600],
        'max_comment_length' => [2000, 100, 10000],
    ];

    /** @return array<string, bool|int|string> */
    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'publication_mode' => self::MODE_MANUAL,
            'allow_user_editing' => true,
            'allow_editor_moderation' => false,
            'max_comments_per_day' => self::LIMITS['max_comments_per_day'][0],
            'max_comments_per_news_per_day' => self::LIMITS['max_comments_per_news_per_day'][0],
            'min_submission_interval_seconds' => self::LIMITS['min_submission_interval_seconds'][0],
            'max_comment_length' => self::LIMITS['max_comment_length'][0],
        ];
    }

    /** @return array<string, bool|int|string>|null */
    public function values(): ?array
    {
        try {
            $tables = app(TableExistence::class);
            foreach ([
                'module_news_comment_settings',
                'module_news_comment_news_settings',
                'module_news_comments',
                'module_news_comment_submission_events',
            ] as $table) {
                if (! $tables->has($table)) {
                    return null;
                }
            }

            $row = NewsCommentSetting::query()->find(1);
            if (! $row instanceof NewsCommentSetting) {
                return null;
            }

            $values = [];
            foreach (['enabled', 'allow_user_editing', 'allow_editor_moderation'] as $field) {
                $value = $row->getRawOriginal($field);
                if (! in_array($value, [0, 1, '0', '1', false, true], true)) {
                    return null;
                }
                $values[$field] = $value === 1 || $value === '1' || $value === true;
            }

            $mode = $row->getRawOriginal('publication_mode');
            if (! in_array($mode, [self::MODE_MANUAL, self::MODE_AUTOMATIC], true)) {
                return null;
            }
            $values['publication_mode'] = $mode;

            foreach (self::LIMITS as $field => [$default, $min, $max]) {
                $raw = $row->getRawOriginal($field);
                if ((! is_int($raw) && (! is_string($raw) || ! ctype_digit($raw)))
                    || (int) $raw < $min || (int) $raw > $max) {
                    return null;
                }
                $values[$field] = (int) $raw;
            }

            return $values;
        } catch (Throwable) {
            return null;
        }
    }

    public function editorCanModerate(): bool
    {
        $values = $this->values();

        return $values !== null && $values['allow_editor_moderation'] === true;
    }

    public function enabledForNews(News $news): bool
    {
        if (! $news->exists || ! $news->isLive()) {
            return false;
        }

        $values = $this->values();
        if ($values === null || $values['enabled'] !== true) {
            return false;
        }

        try {
            $policy = NewsCommentNewsSetting::query()->whereKey($news->getKey())->value('policy');

            return $policy === null || in_array($policy, ['inherit', 'enabled'], true);
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $values */
    public function update(array $values, Admin $admin): void
    {
        if ($this->values() === null) {
            throw new RuntimeException('News comment settings are unavailable.');
        }

        $updated = NewsCommentSetting::query()->whereKey(1)->update([
            ...$values,
            'updated_by_admin_id' => $admin->getKey(),
        ]);

        if ($updated !== 1) {
            throw new RuntimeException('News comment settings could not be saved.');
        }
    }
}
