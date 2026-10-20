<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace FG\Component\Fgloginasuser\Administrator\Service;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;

\defined('_JEXEC') or die;

/**
 * Writes to Joomla's own User Actions Log (com_actionlogs).
 *
 * The message strings live in plg_actionlog_fgloginasuser, because Joomla only loads the
 * language files of actionlog-group plugins when it renders the log. Enabling or disabling
 * that plugin therefore also switches this logging on or off, like core's own action log plugins.
 */
final class ActionLogger
{
    public const EXTENSION = 'com_fgloginasuser';

    public const STARTED_SITE  = 'COM_FGLOGINASUSER_ACTIONLOG_STARTED_SITE';
    public const STARTED_ADMIN = 'COM_FGLOGINASUSER_ACTIONLOG_STARTED_ADMIN';
    public const ENDED         = 'COM_FGLOGINASUSER_ACTIONLOG_ENDED';
    public const DENIED        = 'COM_FGLOGINASUSER_ACTIONLOG_DENIED';

    /**
     * @param   string  $reason  Language key or plain text; only used by DENIED.
     */
    public static function log(
        CMSApplication $app,
        string $messageKey,
        int $actorId,
        string $actorName,
        int $targetId,
        string $targetName,
        string $reason = ''
    ): void {
        if (!PluginHelper::isEnabled('actionlog', 'fgloginasuser')) {
            return;
        }

        try {
            if (str_starts_with($reason, 'COM_')) {
                $app->getLanguage()->load('com_fgloginasuser', JPATH_ADMINISTRATOR);
                $reason = Reason::translate($reason);
            }

            $message = [
                'id'          => $targetId,
                'username'    => $actorName,
                'accountlink' => 'index.php?option=com_users&task=user.edit&id=' . $actorId,
                'target'      => $targetName !== '' ? $targetName : '#' . $targetId,
                'targetlink'  => 'index.php?option=com_users&task=user.edit&id=' . $targetId,
                'reason'      => $reason,
            ];

            $app->bootComponent('actionlogs')
                ->getMVCFactory()
                ->createModel('Actionlog', 'Administrator', ['ignore_request' => true])
                ->addLog([$message], $messageKey, self::EXTENSION, $actorId);
        } catch (\Throwable $e) {
            Log::add('Action log write failed: ' . $e->getMessage(), Log::WARNING, 'com_fgloginasuser');
        }
    }
}
