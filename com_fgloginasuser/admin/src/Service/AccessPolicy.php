<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace FG\Component\Fgloginasuser\Administrator\Service;

use Joomla\CMS\Access\Access;
use Joomla\CMS\User\User;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * Decides whether an actor may impersonate a target user on a given client.
 *
 * evaluate() returns null when allowed, otherwise the language key of the refusal reason.
 */
final class AccessPolicy
{
    public const CLIENT_SITE  = 'site';
    public const CLIENT_ADMIN = 'administrator';

    public function __construct(private readonly Registry $params)
    {
    }

    public static function normaliseClient(string $client): ?string
    {
        return match ($client) {
            self::CLIENT_SITE  => self::CLIENT_SITE,
            self::CLIENT_ADMIN => self::CLIENT_ADMIN,
            default            => null,
        };
    }

    public function evaluate(User $actor, User $target, string $client): ?string
    {
        $action = $client === self::CLIENT_SITE ? 'fgloginasuser.frontend' : 'fgloginasuser.backend';

        if (
            $actor->guest || !$actor->id
            || !$actor->authorise('core.manage', 'com_fgloginasuser')
            || !$actor->authorise($action, 'com_fgloginasuser')
        ) {
            return 'COM_FGLOGINASUSER_ERR_NOT_ALLOWED';
        }

        if (!$target->id) {
            return 'COM_FGLOGINASUSER_ERR_NO_USER';
        }

        if ((int) $target->id === (int) $actor->id) {
            return 'COM_FGLOGINASUSER_ERR_SELF';
        }

        if ((int) $target->block === 1) {
            return 'COM_FGLOGINASUSER_ERR_BLOCKED';
        }

        $activation = trim((string) $target->activation);

        if ($activation !== '' && $activation !== '0') {
            return 'COM_FGLOGINASUSER_ERR_NOT_ACTIVATED';
        }

        $loginAction = $client === self::CLIENT_SITE ? 'core.login.site' : 'core.login.admin';

        if (!$target->authorise($loginAction)) {
            return $client === self::CLIENT_SITE
                ? 'COM_FGLOGINASUSER_ERR_TARGET_NO_SITE'
                : 'COM_FGLOGINASUSER_ERR_TARGET_NO_ADMIN';
        }

        // Super users are only reachable by another super user and only when explicitly enabled.
        if ($target->authorise('core.admin')) {
            if (!$actor->authorise('core.admin') || !(int) $this->params->get('allow_superusers', 0)) {
                return 'COM_FGLOGINASUSER_ERR_SUPERUSER';
            }
        }

        if (!$actor->authorise('core.admin') && !$this->restrictionsAllow($actor, $target)) {
            return 'COM_FGLOGINASUSER_ERR_RESTRICTED';
        }

        return null;
    }

    /**
     * Per-group restrictions: when at least one rule names one of the actor's own groups,
     * the target has to belong (directly or through inheritance) to one of the allowed groups.
     */
    private function restrictionsAllow(User $actor, User $target): bool
    {
        $rows        = (array) $this->params->get('restrictions', []);
        $actorGroups = array_map('intval', array_values((array) $actor->groups));
        $allowed     = [];
        $restricted  = false;

        foreach ($rows as $row) {
            $row     = (array) $row;
            $groupId = (int) ($row['actor_group'] ?? 0);

            if ($groupId > 0 && \in_array($groupId, $actorGroups, true)) {
                $restricted = true;

                foreach ((array) ($row['target_groups'] ?? []) as $targetGroup) {
                    $allowed[(int) $targetGroup] = true;
                }
            }
        }

        if (!$restricted) {
            return true;
        }

        foreach (Access::getGroupsByUser((int) $target->id, true) as $groupId) {
            if (isset($allowed[(int) $groupId])) {
                return true;
            }
        }

        return false;
    }
}
