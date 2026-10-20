<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace FG\Component\Fgloginasuser\Administrator\Exception;

\defined('_JEXEC') or die;

/**
 * Raised when an impersonation request is refused; the message is a language key.
 */
class ImpersonationException extends \RuntimeException
{
}
