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
     * Predefined reasons configured by the administrator (empty = free text only).
     *
     * @return  string[]
     */
    public static function presets(Registry $params): array
    {
        $out = [];

        foreach ((array) $params->get('reason_presets', []) as $row) {
            $label = self::clean((string) (((array) $row)['label'] ?? ''));

            if ($label !== '' && !\in_array($label, $out, true)) {
                $out[] = $label;
            }
        }

        return $out;
    }

    /**
     * May the person write their own reason besides the presets?
     */
    public static function otherAllowed(Registry $params): bool
    {
        return (bool) (int) $params->get('reason_other', 1);
    }

    /**
     * The preset a stored reason belongs to ("Label" or "Label: note"), or null for a free-text reason.
     *
     * @param   string[]  $presets
     */
    public static function presetOf(string $reason, array $presets): ?string
    {
        foreach ($presets as $label) {
            if ($reason === $label || str_starts_with($reason, $label . ': ')) {
                return $label;
            }
        }

        return null;
    }

    /**
     * Language key of the refusal, or null when the (already cleaned) reason is acceptable.
     */
    public static function validate(Registry $params, string $reason): ?string
    {
        if ($reason === '') {
            return self::mode($params) === 2 ? 'COM_FGLOGINASUSER_ERR_REASON' : null;
        }

        $presets = self::presets($params);

        if ($presets !== [] && !self::otherAllowed($params) && self::presetOf($reason, $presets) === null) {
            return 'COM_FGLOGINASUSER_ERR_REASON_INVALID';
        }

        return null;
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
