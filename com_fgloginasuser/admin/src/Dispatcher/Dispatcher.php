<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace FG\Component\Fgloginasuser\Administrator\Dispatcher;

use FG\Component\Fgloginasuser\Administrator\Service\SessionSwitcher;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Dispatcher\ComponentDispatcher;
use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

/**
 * Administrator dispatcher.
 *
 * An impersonated backend user usually has no access to this component, yet must always be
 * able to leave the impersonation. The "stop" task is therefore exempt from the core.manage
 * check, but only while the session really carries an impersonation marker (the controller
 * additionally verifies the CSRF token and the marker contents).
 */
class Dispatcher extends ComponentDispatcher
{
    protected function checkAccess()
    {
        if (
            $this->input->getCmd('task') === 'impersonate.stop'
            && SessionSwitcher::getMarker($this->app) !== null
        ) {
            return;
        }

        parent::checkAccess();

        // The audit log (IP addresses, who looked at whom) has its own right, separate from using the tool.
        $input = $this->input;

        if (
            ($input->getCmd('view') === 'logs' || str_starts_with((string) $input->getCmd('task', ''), 'logs.'))
            && !$this->app->getIdentity()->authorise('fgloginasuser.logs', 'com_fgloginasuser')
        ) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }
}
