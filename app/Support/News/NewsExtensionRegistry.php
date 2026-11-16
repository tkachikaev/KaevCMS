<?php

namespace App\Support\News;

use App\Models\News;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Throwable;

final class NewsExtensionRegistry
{
    /** @var array<string, Closure(News): (View|null)> */
    private array $renderers = [];

    /** @param Closure(News): (View|null) $renderer */
    public function register(string $moduleId, Closure $renderer): void
    {
        $this->renderers[$moduleId] = $renderer;
    }

    /** @return list<HtmlString> */
    public function views(News $news): array
    {
        if (! $news->exists || ! $news->isLive()) {
            return [];
        }

        $views = [];

        foreach ($this->renderers as $moduleId => $renderer) {
            try {
                $view = $renderer($news);
                if ($view instanceof View) {
                    $views[] = new HtmlString($view->render());
                }
            } catch (Throwable $exception) {
                Log::warning('News extension could not be displayed.', [
                    'module_id' => $moduleId,
                    'exception_class' => $exception::class,
                ]);
            }
        }

        return $views;
    }
}
