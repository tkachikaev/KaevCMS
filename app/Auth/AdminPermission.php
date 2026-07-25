<?php

namespace App\Auth;

enum AdminPermission: string
{
    case DashboardView = 'dashboard.view';
    case DashboardRefresh = 'dashboard.refresh';
    case ContentManage = 'content.manage';
    case UsersManage = 'users.manage';
    case AppearanceView = 'appearance.view';
    case AppearanceManage = 'appearance.manage';
    case ModulesView = 'modules.view';
    case ModulesManage = 'modules.manage';
    case ServersView = 'servers.view';
    case ServersManage = 'servers.manage';
    case MailView = 'mail.view';
    case MailManage = 'mail.manage';
    case SettingsView = 'settings.view';
    case SettingsManage = 'settings.manage';
    case SecurityManage = 'security.manage';
    case AdminPathManage = 'admin_path.manage';
    case AdministratorsManage = 'administrators.manage';
    case AuditView = 'audit.view';
    case RewardsManage = 'rewards.manage';
    case SystemView = 'system.view';
    case ProfileManage = 'profile.manage';
}
