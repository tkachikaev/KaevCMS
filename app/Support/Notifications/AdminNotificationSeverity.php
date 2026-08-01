<?php

namespace App\Support\Notifications;

enum AdminNotificationSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
    case Success = 'success';
}
