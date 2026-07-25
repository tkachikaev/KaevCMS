<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UploadPageImageRequest;
use App\Services\Pages\PageImageStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class PageImageController extends Controller
{
    public function store(UploadPageImageRequest $request, PageImageStorage $storage): JsonResponse
    {
        $path = $storage->storeContent($request->file('image'));

        Log::notice('CMS page content image uploaded.', [
            'admin_id' => Auth::guard('admin')->id(),
            'path' => $path,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'url' => $storage->publicPath($path),
            'path' => $path,
        ], 201);
    }
}
