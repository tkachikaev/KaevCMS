<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

/** /about: kept for old links; opens the page whose address is "about". */
final class AboutController
{
    public function __invoke(): RedirectResponse
    {
        return redirect()->to(page_key_url('about'));
    }
}
