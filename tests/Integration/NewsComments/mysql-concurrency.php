<?php

/**
 * Destructive opt-in concurrency check for the News Comments module.
 *
 * APP_ENV=testing DB_CONNECTION=mysql DB_DATABASE=news_comments_test \
 * NEWS_COMMENTS_TEST_ALLOW_CONCURRENCY=1 SCHEMA_TEST_ALLOW=1 \
 * php tests/Integration/NewsComments/mysql-concurrency.php
 *
 * Use a disposable database on MySQL 8.4 and MariaDB 10.11 separately.
 * This script runs migrate:fresh. Never run it against real data.
 */

use App\Models\Admin;
use App\Models\News;
use App\Models\User;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use KaevCMS\Modules\NewsComments\Models\NewsComment;
use KaevCMS\Modules\NewsComments\Services\NewsCommentModerationService;
use KaevCMS\Modules\NewsComments\Services\NewsCommentService;

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
});

$database = (string) DB::connection()->getDatabaseName();
if (config('app.env') !== 'testing' || DB::connection()->getDriverName() !== 'mysql'
    || preg_match('/news_comments_test/i', $database) !== 1
    || getenv('NEWS_COMMENTS_TEST_ALLOW_CONCURRENCY') !== '1'
    || getenv('SCHEMA_TEST_ALLOW') !== '1') {
    throw new RuntimeException('Requires APP_ENV=testing, disposable news_comments_test MySQL database and both opt-in flags.');
}

function assertNewsComments(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

if (($argv[1] ?? '') === '--worker') {
    $action = (string) ($argv[2] ?? '');
    $startArg = (string) end($argv);
    if (! str_starts_with($startArg, '--start=')) {
        throw new RuntimeException('Concurrency start timestamp is required.');
    }
    $start = (float) substr($startArg, 8);
    while (microtime(true) < $start) {
        usleep(2000);
    }

    try {
        if ($action === 'post') {
            $user = User::query()->findOrFail((int) ($argv[3] ?? 0));
            Auth::guard('web')->setUser($user);
            app(NewsCommentService::class)->submit(
                News::query()->findOrFail((int) ($argv[4] ?? 0)),
                $user,
                (string) ($argv[5] ?? ''),
            );
            echo "accepted\n";
        } elseif ($action === 'approve-edit') {
            $admin = Admin::query()->findOrFail((int) ($argv[3] ?? 0));
            Auth::guard('admin')->setUser($admin);
            app(NewsCommentModerationService::class)->act(
                $admin,
                (int) ($argv[4] ?? 0),
                NewsCommentModerationService::ACTION_APPROVE_EDIT,
                (string) ($argv[5] ?? ''),
            );
            echo "accepted\n";
        } elseif ($action === 'delete-comment') {
            $user = User::query()->findOrFail((int) ($argv[3] ?? 0));
            Auth::guard('web')->setUser($user);
            $comment = NewsComment::query()->findOrFail((int) ($argv[4] ?? 0));
            app(NewsCommentService::class)->deleteOwn(
                $comment->news()->firstOrFail(),
                $user,
                $comment->id,
            );
            echo "accepted\n";
        } else {
            throw new RuntimeException('Unknown worker action.');
        }
        exit(0);
    } catch (ValidationException) {
        echo "rejected\n";
        exit(0);
    }
}

/**
 * @param list<list<string>> $jobs
 * @return list<string>
 */
function raceNewsComments(array $jobs): array
{
    assertNewsComments(function_exists('proc_open'), 'proc_open is unavailable for concurrent testing.');
    $start = microtime(true) + 1.5;
    $workers = [];

    foreach ($jobs as $args) {
        $pipes = [];
        $command = [PHP_BINARY, __FILE__, '--worker', ...$args, '--start='.$start];
        $process = proc_open($command, [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, dirname(__DIR__, 3));
        assertNewsComments(is_resource($process), 'Unable to start a worker.');
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }

    $outcomes = [];
    foreach ($workers as [$process, $pipes]) {
        $out = trim((string) stream_get_contents($pipes[1]));
        $err = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        assertNewsComments(proc_close($process) === 0, 'Worker failed: '.$err);
        assertNewsComments(in_array($out, ['accepted', 'rejected'], true), 'Unexpected worker output: '.$out);
        $outcomes[] = $out;
    }

    return $outcomes;
}

Artisan::call('migrate:fresh', ['--force' => true]);
$module = app(ModuleManager::class)->enable('news-comments');
app(ModuleRuntime::class)->bootModule($module);
DB::table('module_news_comment_settings')->where('id', 1)->update([
    'enabled' => true,
    'publication_mode' => 'automatic',
    'allow_user_editing' => true,
    'max_comments_per_day' => 3,
    'max_comments_per_news_per_day' => 3,
    'min_submission_interval_seconds' => 0,
]);

$article = News::query()->forceCreate([
    'title' => 'Concurrency acceptance',
    'slug' => 'news-comments-concurrent',
    'body' => '<p>Testing only</p>',
    'is_published' => true,
    'published_at' => now()->subMinute(),
]);

$user = User::factory()->create();
$jobs = [];
for ($i = 0; $i < 8; $i++) {
    $jobs[] = ['post', (string) $user->id, (string) $article->id, 'Unique concurrent '.$i];
}
$outcomes = raceNewsComments($jobs);
assertNewsComments(count(array_filter($outcomes, fn (string $r): bool => $r === 'accepted')) === 3,
    'Eight simultaneous submissions exceeded the configured per-user quota.');
assertNewsComments(NewsComment::query()->where('user_id', $user->id)->count() === 3,
    'Unexpected number of accepted comments.');
assertNewsComments(DB::table('module_news_comment_submission_events')->where('user_id', $user->id)->count() === 3,
    'Quota submission events do not match accepted comments.');
echo "PASS: eight concurrent submissions, exactly three admitted\n";

$secondUser = User::factory()->create();
$outcomes = raceNewsComments(array_fill(0, 6, [
    'post', (string) $secondUser->id, (string) $article->id, 'Identical duplicate submission',
]));
assertNewsComments(count(array_filter($outcomes, fn (string $r): bool => $r === 'accepted')) === 1,
    'A duplicate concurrent submission created multiple comments.');
assertNewsComments(DB::table('module_news_comment_submission_events')
    ->where('user_id', $secondUser->id)->count() === 1,
    'Duplicate submission charged more than once.');
echo "PASS: six concurrent duplicate posts, exactly one accepted\n";

$author = User::factory()->create();
$replier = User::factory()->create();
$admin = Admin::factory()->create();
Auth::guard('web')->setUser($author);
$parent = app(NewsCommentService::class)->submit($article, $author, 'Original published text');
Auth::guard('web')->setUser($replier);
$reply = app(NewsCommentService::class)->submit($article, $replier, 'Reply must survive', $parent->id);
DB::table('module_news_comment_settings')->where('id', 1)->update([
    'publication_mode' => 'manual_review',
]);
Auth::guard('web')->setUser($author);
app(NewsCommentService::class)->edit($article, $author, $parent->id, 'Pending replacement');

$outcomes = raceNewsComments([
    ['approve-edit', (string) $admin->id, (string) $parent->id, (string) $parent->fresh()->pending_revision_token],
    ['delete-comment', (string) $author->id, (string) $parent->id],
]);
assertNewsComments(in_array('accepted', $outcomes, true), 'Neither concurrent actor completed.');
assertNewsComments($parent->fresh()->body === null && $parent->fresh()->pending_body === null,
    'Author deletion did not erase all text when it raced moderation.');
assertNewsComments($parent->fresh()->deleted_by_author_at !== null,
    'Author deletion did not persist the placeholder.');
assertNewsComments($reply->fresh() !== null && $reply->fresh()->parent_id === $parent->id,
    'Author deletion unexpectedly removed someone else’s reply.');
echo "PASS: concurrent edit approval and author deletion, no deleted text restored\n";

// Deleting a referenced message as staff must not cascade into its replies.
DB::table('module_news_comment_settings')->where('id', 1)->update([
    'publication_mode' => 'automatic',
]);
Auth::guard('web')->setUser($author);
$staffTarget = app(NewsCommentService::class)->submit($article, $author, 'Admin deletion target');
Auth::guard('web')->setUser($replier);
$staffReply = app(NewsCommentService::class)->submit($article, $replier, 'Staff must preserve me', $staffTarget->id);

Auth::guard('admin')->setUser($admin);
app(NewsCommentModerationService::class)->act($admin, $staffTarget->id, 'destroy');
assertNewsComments($staffTarget->fresh()?->body === null
    && $staffTarget->fresh()?->deleted_by_admin_at !== null,
    'Staff deletion of a referenced message did not create a tombstone.');
assertNewsComments($staffReply->fresh()?->parent_id === $staffTarget->id,
    'Staff deletion removed or unlinked a surviving reply.');
echo "PASS: staff deletion erases only referenced text and preserves replies\n";

DB::table('module_news_comment_news_settings')->insert([
    'news_id' => $article->id,
    'policy' => 'disabled',
    'created_at' => now(),
    'updated_at' => now(),
]);
app(ModuleManager::class)->disable('news-comments');
$article->forceDelete();
assertNewsComments(NewsComment::query()->count() === 0,
    'Deleting news with module disabled left comments behind.');
assertNewsComments(DB::table('module_news_comment_submission_events')->count() === 0,
    'Deleting news left submission records behind.');
assertNewsComments(DB::table('module_news_comment_news_settings')->count() === 0,
    'Deleting news left per-article policy behind.');
echo "PASS: news deletion cascades all module data with module disabled\n";
