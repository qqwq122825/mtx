<?php
declare(strict_types=1);
namespace MTX;
final class AdminLayout
{
    public static function assets(App $app): void
    {
        echo '<link rel="stylesheet" href="'.Http::escape($app->path('/admin/telegram.php')).'?asset=admin-css&amp;v=1">';
    }
    public static function begin(App $app,string $title,string $active): void
    {
        $groups=[
            '发布与下载'=>['releases'=>['游戏更新','/admin/'],'website'=>['主页与安装器','/admin/home.php']],
            'Telegram'=>['conversations'=>['客服会话','/admin/telegram.php?view=conversations'],'bot'=>['机器人设置','/admin/telegram.php'],'announcements'=>['公告管理','/admin/telegram-announcements.php'],'activities'=>['活动与卡密','/admin/telegram.php?view=activities']],
        ];
        echo '<div class="admin-layout"><aside class="admin-sidebar" id="admin-navigation"><a class="admin-brand" href="'.Http::escape($app->path('/admin/')).'"><span class="admin-brand-icon">✳</span><span>满天星<small>管理控制台</small></span></a><nav aria-label="后台导航">';
        foreach ($groups as $label=>$links) {
            echo '<div class="admin-nav-label">'.Http::escape($label).'</div>';
            foreach ($links as $key=>[$name,$path]) echo '<a class="admin-nav-link'.($active===$key?' is-active':'').'" '.($active===$key?'aria-current="page" ':'').'href="'.Http::escape($app->path($path)).'"><span class="nav-indicator" aria-hidden="true"></span>'.Http::escape($name).'</a>';
        }
        echo '</nav><div class="admin-sidebar-foot"><span class="admin-status-dot"></span> 满天星后台</div></aside><div class="admin-workspace"><header class="admin-topbar"><button class="admin-menu-toggle" type="button" aria-label="展开后台导航" aria-controls="admin-navigation" aria-expanded="false">☰</button><div><span class="admin-breadcrumb">控制台 / </span><strong>'.Http::escape($title).'</strong></div><div class="admin-topbar-actions"><a href="/" target="_blank" rel="noopener">查看网站 ↗</a><form method="post" action="'.Http::escape($app->path('/admin/action.php')).'"><input type="hidden" name="csrf" value="'.Http::escape($_SESSION['csrf']).'"><input type="hidden" name="action" value="logout"><button type="submit" class="admin-logout">退出</button></form><span class="admin-avatar" aria-label="管理员">M</span></div></header><button class="admin-nav-scrim" type="button" aria-label="关闭后台导航" hidden></button>';
    }
    public static function end(): void { echo '</div></div>'; }
}
