<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace FG\Component\Fgloginasuser\Administrator\Controller;

use Joomla\CMS\MVC\Controller\BaseController;

\defined('_JEXEC') or die;

class DisplayController extends BaseController
{
    protected $default_view = 'users';

    public function display($cachable = false, $urlparams = [])
    {
        if (!\in_array($this->input->getCmd('view', 'users'), ['users', 'logs'], true)) {
            $this->input->set('view', 'users');
        }

        return parent::display($cachable, $urlparams);
    }
}
