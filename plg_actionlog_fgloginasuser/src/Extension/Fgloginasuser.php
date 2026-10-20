<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace FG\Plugin\Actionlog\Fgloginasuser\Extension;

use Joomla\CMS\Plugin\CMSPlugin;

\defined('_JEXEC') or die;

/**
 * Carries the User Actions Log message strings of com_fgloginasuser. Joomla loads the language
 * files of all actionlog plugins when it renders the log, and com_fgloginasuser only writes log
 * entries while this plugin is enabled. It has no event handlers of its own.
 */
final class Fgloginasuser extends CMSPlugin
{
}
