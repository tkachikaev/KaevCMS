<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\SecuritySettings;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    /** @var array<int, string> */
    private const DEFAULT_CATEGORIES = ['admin', 'user', 'mail', 'system'];

    public function index(Request $request, SecuritySettings $securitySettings): View
    {
        $category = strtolower(trim((string) $request->query('category')));

        if ($category === '' || preg_match('/^[a-z0-9._-]{1,64}$/', $category) !== 1) {
            $category = null;
        }

        $query = AuditLog::query()->latest('id');

        if ($category !== null) {
            $query->where('category', $category);
        }

        $counts = AuditLog::query()
            ->selectRaw('category, COUNT(*) as aggregate')
            ->groupBy('category')
            ->pluck('aggregate', 'category');
        $categories = collect(self::DEFAULT_CATEGORIES)
            ->merge($counts->keys())
            ->unique()
            ->values();

        return view('admin.audit.index', [
            'logs' => $query->paginate(50)->withQueryString(),
            'activeCategory' => $category,
            'counts' => $counts,
            'categories' => $categories,
            'totalCount' => AuditLog::query()->count(),
            'retentionDays' => $securitySettings->values()['audit_retention_days'],
        ]);
    }

    public function show(AuditLog $auditLog): View
    {
        return view('admin.audit.show', [
            'auditLog' => $auditLog,
        ]);
    }
}
