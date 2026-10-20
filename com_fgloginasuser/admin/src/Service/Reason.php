<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace FG\Component\Fgloginasuser\Administrator\Service;

use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

/**
 * Notes/reasons are stored as language keys, optionally with one argument: "COM_KEY|argument".
 */
final class Reason
{
    public static function translate(string $note): string
    {
        if (strpos($note, 'COM_') !== 0) {
            return $note;
        }

        [$key, $argument] = array_pad(explode('|', $note, 2), 2, null);

        return $argument === null ? Text::_($key) : Text::sprintf($key, $argument);
    }
}
