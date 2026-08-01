<?php

namespace App\Support\Notifications;

final readonly class AdminNotificationData
{
    /**
     * @param  array<string, bool|float|int|string|null>  $parameters
     * @param  array<string, bool|int|string>  $routeParameters
     */
    public function __construct(
        public AdminNotificationType $type,
        public AdminNotificationSeverity $severity,
        public string $titleKey,
        public ?string $messageKey = null,
        public array $parameters = [],
        public ?string $routeName = null,
        public array $routeParameters = [],
    ) {}
}
