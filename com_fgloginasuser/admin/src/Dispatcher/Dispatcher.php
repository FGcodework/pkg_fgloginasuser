<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace FG\Component\Fgloginasuser\Administrator\Dispatcher;

use FG\Component\Fgloginasuser\Administrator\Service\SessionSwitcher;
use Joomla\CMS\Dispatcher\ComponentDispatcher;

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
    }
}
