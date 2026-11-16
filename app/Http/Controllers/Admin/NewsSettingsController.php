<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\InteractsWithSettingsAudit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveNewsDisplaySettingsRequest;
use App\Services\AuditLogger;
use App\Services\NewsDisplaySettings;
use App\Services\Settings\SettingsImageStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Throwable;

class NewsSettingsController extends Controller
{
    use InteractsWithSettingsAudit;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function show(NewsDisplaySettings $newsDisplay): View
    {
        return view('admin.settings.news', [
            'settings' => $newsDisplay->values(),
        ]);
    }

    public function update(
        SaveNewsDisplaySettingsRequest $request,
        NewsDisplaySettings $newsDisplay,
        SettingsImageStorage $images,
    ): RedirectResponse {
        $validated = $request->validated();
        $current = $newsDisplay->values();
        $storedCover = null;

        try {
            if ($request->hasFile('default_cover')) {
                $storedCover = $images->store($request->file('default_cover'), 'news-cover');
            }

            $cover = $storedCover ?? ($request->boolean('remove_default_cover') ? null : $current['default_cover']);

            $newsDisplay->update([
                'home_limit' => (int) $validated['home_limit'],
                'per_page' => (int) $validated['per_page'],
                'default_cover' => $cover,
            ]);
        } catch (Throwable $exception) {
            if ($storedCover !== null) {
                $images->delete($storedCover, 'news-cover');
            }

            throw $exception;
        }

        if ($current['default_cover'] !== null && $current['default_cover'] !== $cover) {
            $images->delete($current['default_cover'], 'news-cover');
        }

        $after = $newsDisplay->values();
        $this->auditLogger->success(
            category: 'admin',
            action: 'settings.news_updated',
            target: __('News'),
            details: [
                'changes' => $this->auditChanges(
                    ['home_limit' => $current['home_limit'], 'per_page' => $current['per_page']],
                    ['home_limit' => $after['home_limit'], 'per_page' => $after['per_page']],
                ),
                'default_cover_changed' => $current['default_cover'] !== $after['default_cover'],
            ],
        );

        return redirect()
            ->route('admin.settings.news')
            ->with('status', __('News settings saved.'));
    }
}
