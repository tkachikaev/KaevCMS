<?php

namespace App\Auth;

enum AdminPermission: string
{
    case DashboardView = 'dashboard.view';
    case DashboardRefresh = 'dashboard.refresh';
    case ContentView = 'content.view';
    case ContentManage = 'content.manage';
    case UsersView = 'users.view';
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
    case SecurityView = 'security.view';
    case SecurityManage = 'security.manage';
    case AdminPanelView = 'admin_panel.view';
    case AdminPathManage = 'admin_path.manage';
    case AdministratorsView = 'administrators.view';
    case AdministratorsManage = 'administrators.manage';
    case AuditView = 'audit.view';
    case RewardsView = 'rewards.view';
    case RewardsManage = 'rewards.manage';
    case SystemView = 'system.view';
    case SystemUpdatesView = 'system_updates.view';
    case SystemUpdatesManage = 'system_updates.manage';
    case QueueView = 'queue.view';
    case QueueManage = 'queue.manage';
    case ProfileManage = 'profile.manage';
}
