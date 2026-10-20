<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace FG\Component\Fgloginasuser\Administrator\Controller;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\AdminController;
use Joomla\CMS\Router\Route;

\defined('_JEXEC') or die;

class LogsController extends AdminController
{
    protected $text_prefix = 'COM_FGLOGINASUSER_LOGS';

    public function getModel($name = 'Log', $prefix = 'Administrator', $config = ['ignore_request' => true])
    {
        return parent::getModel($name, $prefix, $config);
    }

    /**
     * Delete every log row.
     */
    public function purge(): void
    {
        $this->checkToken();

        $this->setRedirect(Route::_('index.php?option=com_fgloginasuser&view=logs', false));

        if (!$this->app->getIdentity()->authorise('core.delete', 'com_fgloginasuser')) {
            $this->setMessage(Text::_('JERROR_CORE_DELETE_NOT_PERMITTED'), 'error');

            return;
        }

        if ($this->getModel()->purgeAll()) {
            $this->setMessage(Text::_('COM_FGLOGINASUSER_LOGS_PURGED'));
        } else {
            $this->setMessage(Text::_('COM_FGLOGINASUSER_LOGS_PURGE_FAILED'), 'error');
        }
    }
}
