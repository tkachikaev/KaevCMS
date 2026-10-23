<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

/** /downloads: kept for old links; opens the page whose address is "downloads". */
final class DownloadController
{
    public function __invoke(): RedirectResponse
    {
        return redirect()->to(page_key_url('downloads'));
    }
}
