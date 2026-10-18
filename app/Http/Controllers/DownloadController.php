<?php

namespace App\Http\Controllers;

use App\Services\Pages\DownloadPages;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class DownloadController
{
    public function __invoke(DownloadPages $downloads): View|RedirectResponse
    {
        $page = $downloads->selected();
        if ($page !== null && $page->isLive() && $page->translation() !== null) {
            return redirect()->to(page_url($page));
        }

        return view('theme::pages.downloads');
    }
}
