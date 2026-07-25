<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UploadNewsImageRequest;
use App\Services\News\NewsImageStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class NewsImageController extends Controller
{
    public function store(UploadNewsImageRequest $request, NewsImageStorage $storage): JsonResponse
    {
        $path = $storage->storeContent($request->file('image'));

        Log::notice('CMS news content image uploaded.', [
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
