<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace FG\Component\Fgloginasuser\Administrator\Service;

use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * The reason an administrator gives for logging in as somebody else (parameter "reason_mode":
 * 0 = not asked, 1 = optional, 2 = required).
 */
final class Justification
{
    public const MAX = 255;

    public static function mode(Registry $params): int
    {
        return max(0, min(2, (int) $params->get('reason_mode', 1)));
    }

    /**
     * Single line, no control characters, at most MAX characters.
     */
    public static function clean(string $text): string
    {
        $text = preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $text) ?? '';

        return mb_substr(trim(preg_replace('/\s+/u', ' ', $text) ?? ''), 0, self::MAX);
    }
}
