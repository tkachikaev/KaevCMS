<?php

namespace App\Support\Payments;

use App\Support\Modules\ModuleAutoloader;
use RuntimeException;

/** Register only bundled Donations PHP code for the persistent callback/CLI path.
 * It must NOT enable the module, load its UI, or resolve an arbitrary DB class/path.
 */
final class DonationsTrustedAutoload
{
    public static function register(): void
    {
        $path = base_path('modules/donations/src');
        if (! is_dir($path)) {
            throw new RuntimeException('Donations payment handler is unavailable.');
        }
        app(ModuleAutoloader::class)->register([
            'id' => 'donations-payment-settlement',
            'namespace' => 'KaevCMS\\Modules\\Donations\\',
            'autoload_path' => $path,
        ]);
    }
}
