<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace FG\Component\Fgloginasuser\Site\Controller;

use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Uri\Uri;

\defined('_JEXEC') or die;

/**
 * The site part has no views of its own; anything but the login tasks goes to the home page.
 */
class DisplayController extends BaseController
{
    public function display($cachable = false, $urlparams = [])
    {
        $this->setRedirect(Uri::root());

        return $this;
    }
}
