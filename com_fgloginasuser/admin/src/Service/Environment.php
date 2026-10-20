<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace FG\Component\Fgloginasuser\Administrator\Service;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Plugin\PluginHelper;

\defined('_JEXEC') or die;

/**
 * Preconditions of the site itself that make an impersonation unsafe (language key of the refusal, or null).
 */
final class Environment
{
    /**
     * Warning for the administrator when the system plugin is disabled (empty string when all is well).
     */
    public static function pluginWarning(): string
    {
        return PluginHelper::isEnabled('system', 'fgloginasuser') ? '' : 'COM_FGLOGINASUSER_WARN_PLUGIN_OFF';
    }

    public static function check(CMSApplicationInterface $app): ?string
    {
        // One session shared by site and administrator: the impersonated identity would also become the
        // identity in the administration of the person who started it, and could not be left again.
        if ((int) $app->get('shared_session', 0)) {
            return 'COM_FGLOGINASUSER_ERR_SHARED_SESSION';
        }

        // The "return to my account" bar comes from the system plugin; without it nobody sees they are impersonating.
        if (!PluginHelper::isEnabled('system', 'fgloginasuser')) {
            return 'COM_FGLOGINASUSER_ERR_NO_BAR';
        }

        return null;
    }
}
