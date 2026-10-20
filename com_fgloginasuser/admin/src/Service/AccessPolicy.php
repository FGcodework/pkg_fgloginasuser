<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace FG\Component\Fgloginasuser\Administrator\Service;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
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

    /**
     * Checks that apply to the person starting (or completing) an impersonation. Run again when the
     * one-time token is used, because the actor may have been blocked or lost rights in the meantime.
     */
    public function evaluateActor(User $actor, string $client): ?string
    {
        $action     = $client === self::CLIENT_SITE ? 'fgloginasuser.frontend' : 'fgloginasuser.backend';
        $activation = trim((string) $actor->activation);

        if (
            $actor->guest || !$actor->id
            || (int) $actor->block === 1
            || ($activation !== '' && $activation !== '0')
            || !$actor->authorise('core.login.admin')
            || !$actor->authorise('core.manage', 'com_fgloginasuser')
            || !$actor->authorise($action, 'com_fgloginasuser')
        ) {
            return 'COM_FGLOGINASUSER_ERR_NOT_ALLOWED';
        }

        return null;
    }

    public function evaluate(User $actor, User $target, string $client): ?string
    {
        if (($error = $this->evaluateActor($actor, $client)) !== null) {
            return $error;
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

        // Core redirects such a user to the password form before dispatching any component, which would also
        // swallow the "return to my account" link.
        if ((int) $target->requireReset === 1) {
            return 'COM_FGLOGINASUSER_ERR_RESET';
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
     * Per-group restrictions.
     *
     * - A rule applies when its actor group is one of the actor's groups, including inherited ones
     *   (an Administrator is a child of Manager, so a rule for Manager covers Administrators too).
     * - Rules that apply are combined (union of their target groups).
     * - EVERY group the target belongs to must lie within the allowed groups (a group counts when it is
     *   an allowed group or a descendant of one). Belonging to one allowed group is not enough: a user
     *   who is also in a more privileged group is refused.
     * - Without an applicable rule the "restrictions_default" option decides (allow by default).
     */
    private function restrictionsAllow(User $actor, User $target): bool
    {
        $rows        = (array) $this->params->get('restrictions', []);
        $actorGroups = array_map('intval', Access::getGroupsByUser((int) $actor->id, true));
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
            return !(int) $this->params->get('restrictions_default', 0);
        }

        $targetGroups = array_map('intval', array_values((array) $target->groups));

        if ($targetGroups === []) {
            return false;
        }

        foreach ($targetGroups as $groupId) {
            if (!array_intersect(self::ancestors($groupId), array_keys($allowed))) {
                return false;
            }
        }

        return true;
    }

    /**
     * The group and all its ancestors (nested set), cached per request.
     *
     * @return  int[]
     */
    private static function ancestors(int $groupId): array
    {
        static $cache = [];

        if (!isset($cache[$groupId])) {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->createQuery()
                ->select($db->quoteName('p.id'))
                ->from($db->quoteName('#__usergroups', 'g'))
                ->join(
                    'INNER',
                    $db->quoteName('#__usergroups', 'p') . ' ON ' . $db->quoteName('p.lft') . ' <= ' . $db->quoteName('g.lft')
                    . ' AND ' . $db->quoteName('p.rgt') . ' >= ' . $db->quoteName('g.rgt')
                )
                ->where($db->quoteName('g.id') . ' = :id')
                ->bind(':id', $groupId, ParameterType::INTEGER);

            $cache[$groupId] = array_map('intval', $db->setQuery($query)->loadColumn());
        }

        return $cache[$groupId];
    }
}
