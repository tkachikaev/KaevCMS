<?php

namespace KaevCMS\Modules\Forum\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use KaevCMS\Modules\Forum\Models\ForumCategory;
use KaevCMS\Modules\Forum\Models\ForumSection;

final class ForumStructure
{
    public function globalVisibility(): string
    {
        return (string) (DB::table('module_forum_settings')->where('id', 1)->value('visibility') ?? 'registered');
    }

    /** @return Collection<int, ForumCategory> */
    public function visibleCategories(ForumActor $actor, ForumPermissions $permissions): Collection
    {
        $rules = $permissions->allowedViewRules($actor, $this->globalVisibility());
        if ($rules === []) {
            return new Collection;
        }

        // Filter section visibility in SQL, not after eager-loading private rows.
        $query = ForumCategory::query()
            ->with(['sections' => static fn ($q) => $q
                ->whereIn('view_rule', $rules)
                ->orderBy('position')->orderBy('id')])
            ->orderBy('position')->orderBy('id');

        if (! $actor->isOwner()) {
            $query->whereHas('sections', static fn ($q) => $q->whereIn('view_rule', $rules));
        }

        return $query->get();
    }

    public function initialStructure(): bool
    {
        return DB::transaction(static function (): bool {
            // Serialise concurrent initialisation on the always-existing singleton.
            DB::table('module_forum_settings')->where('id', 1)->lockForUpdate()->first();

            if (ForumCategory::withTrashed()->exists()) {
                return false;
            }

            $defaults = [
                [
                    ['name_ru' => 'Информация', 'name_en' => 'Information',
                        'description_ru' => 'Официальные объявления', 'description_en' => 'Official announcements'],
                    [
                        ['name_ru' => 'Новости и объявления', 'name_en' => 'News and announcements',
                            'description_ru' => 'Обновления и важные новости', 'description_en' => 'Updates and important news',
                            'view_rule' => 'all', 'topic_rule' => 'owner', 'reply_rule' => 'nobody'],
                    ],
                ],
                [
                    ['name_ru' => 'Сообщество', 'name_en' => 'Community',
                        'description_ru' => 'Обсуждения игроков', 'description_en' => 'Player discussions'],
                    [
                        ['name_ru' => 'Общее общение', 'name_en' => 'General discussion',
                            'description_ru' => 'Знакомства и свободное общение', 'description_en' => 'Introductions and discussions'],
                        ['name_ru' => 'Вопросы и помощь', 'name_en' => 'Questions and help',
                            'description_ru' => 'Помощь новичкам и вопросы по игре', 'description_en' => 'Help and game questions'],
                        ['name_ru' => 'Предложения и идеи', 'name_en' => 'Suggestions and ideas',
                            'description_ru' => 'Предложения игроков по улучшению сервера', 'description_en' => 'Suggestions for the server'],
                    ],
                ],
                [
                    ['name_ru' => 'Разное', 'name_en' => 'Other',
                        'description_ru' => 'Дополнительные разделы', 'description_en' => 'Other sections'],
                    [
                        ['name_ru' => 'Архив', 'name_en' => 'Archive',
                            'description_ru' => 'Старые темы для чтения', 'description_en' => 'Older read-only discussions',
                            'view_rule' => 'all', 'topic_rule' => 'nobody', 'reply_rule' => 'nobody'],
                    ],
                ],
            ];

            foreach ($defaults as $position => [$categoryData, $sections]) {
                $category = ForumCategory::query()->create($categoryData + ['position' => $position + 1]);
                foreach ($sections as $offset => $sectionData) {
                    ForumSection::query()->create($sectionData + [
                        'category_id' => $category->id, 'position' => $offset + 1,
                        'view_rule' => 'all', 'topic_rule' => 'registered', 'reply_rule' => 'registered',
                    ]);
                }
            }

            return true;
        });
    }

    public function createCategory(array $data): ForumCategory
    {
        return DB::transaction(static function () use ($data): ForumCategory {
            DB::table('module_forum_settings')->where('id', 1)->lockForUpdate()->first();
            $last = (int) ForumCategory::query()->max('position');

            return ForumCategory::query()->create($data + ['position' => $last + 1]);
        });
    }

    public function updateCategory(ForumCategory $category, array $data): void
    {
        $category->update($data);
    }

    public function deleteCategory(ForumCategory $category): void
    {
        DB::transaction(static function () use ($category): void {
            $category = ForumCategory::query()->lockForUpdate()->findOrFail($category->id);
            if ($category->sections()->exists()) {
                throw ValidationException::withMessages(['category' => __('module-forum::messages.category_not_empty')]);
            }

            $category->delete();
        });
    }

    public function createSection(ForumCategory $category, array $data): ForumSection
    {
        $this->assertRules($data);

        return DB::transaction(static function () use ($category, $data): ForumSection {
            ForumCategory::query()->lockForUpdate()->findOrFail($category->id);
            $last = (int) ForumSection::query()->where('category_id', $category->id)->max('position');

            return ForumSection::query()->create($data + [
                'category_id' => $category->id, 'position' => $last + 1,
            ]);
        });
    }

    public function updateSection(ForumSection $section, array $data): void
    {
        $this->assertRules($data);

        DB::transaction(static function () use ($section, $data): void {
            $section = ForumSection::query()->lockForUpdate()->findOrFail($section->id);
            if ((int) $section->category_id !== (int) $data['category_id']) {
                $newCategory = ForumCategory::query()->lockForUpdate()->findOrFail($data['category_id']);
                $data['position'] = (int) ForumSection::query()->where('category_id', $newCategory->id)->max('position') + 1;
            }
            $section->update($data);
        });
    }

    public function deleteSection(ForumSection $section): void
    {
        $section->delete(); // Soft delete; never delete future topics through a foreign key cascade.
    }

    public function moveCategory(ForumCategory $category, string $direction): void
    {
        $this->moveOrdered(ForumCategory::class, (int) $category->id, $direction);
    }

    public function moveSection(ForumSection $section, string $direction): void
    {
        $this->moveOrdered(ForumSection::class, (int) $section->id, $direction, (int) $section->category_id);
    }

    private function moveOrdered(string $model, int $id, string $direction, ?int $categoryId = null): void
    {
        DB::transaction(static function () use ($model, $id, $direction, $categoryId): void {
            DB::table('module_forum_settings')->where('id', 1)->lockForUpdate()->first();

            $query = $model::query();
            if ($categoryId !== null) {
                $query->where('category_id', $categoryId);
            }
            $rows = $query->orderBy('position')->orderBy('id')->lockForUpdate()->get();
            $index = $rows->search(static fn ($row): bool => (int) $row->id === $id);
            if ($index === false) {
                abort(404);
            }

            $adjacent = $index + ($direction === 'up' ? -1 : 1);
            if (! isset($rows[$adjacent])) {
                return;
            }

            // Normalise positions so duplicate historical positions cannot cause a no-op swap.
            $ordered = $rows->values()->all();
            [$ordered[$index], $ordered[$adjacent]] = [$ordered[$adjacent], $ordered[$index]];
            foreach ($ordered as $position => $row) {
                if ((int) $row->position !== $position + 1) {
                    $row->update(['position' => $position + 1]);
                }
            }
        });
    }

    private function assertRules(array $data): void
    {
        if (! ForumPermissions::compatible($data['view_rule'], $data['topic_rule'], $data['reply_rule'])) {
            throw ValidationException::withMessages([
                'topic_rule' => __('module-forum::messages.invalid_permissions'),
            ]);
        }
    }
}
