<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace FG\Component\Fgloginasuser\Administrator\Service;

use Joomla\CMS\Application\CMSApplication;
use FG\Component\Fgloginasuser\Administrator\Exception\ImpersonationException;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Swaps the user of the current session without going through onUserLogin.
 *
 * Core's onUserLogin handler also bumps the target's lastvisitDate and resets the MFA state,
 * which is wrong for support impersonation: the real user must not look as if they had logged
 * in. The remaining parts of the core routine (session fork, session table row, cache cookie)
 * are replicated here.
 */
final class SessionSwitcher
{
    public const MARKER = 'fgloginasuser.marker';

    /**
     * Read the impersonation marker of the current session.
     *
     * Stored as a JSON string because Joomla's session registry turns nested arrays into
     * objects once it has been serialised and restored.
     *
     * @return  array{log_id:int, actor_id:int, actor_username:string, target_username:string, client:string}|null
     */
    public static function getMarker(CMSApplicationInterface $app): ?array
    {
        $raw = $app->getSession()->get(self::MARKER);

        if (!\is_string($raw) || $raw === '') {
            return null;
        }

        $marker = json_decode($raw, true);

        return \is_array($marker) && isset($marker['actor_id'], $marker['client']) ? $marker : null;
    }

    /**
     * Make $target the user of the current session. A non-null $marker records the
     * impersonation, null clears it (used when returning to the original user).
     *
     * @throws  ImpersonationException  When the session id could not be regenerated.
     */
    public static function switchTo(CMSApplication $app, User $target, ?array $marker): void
    {
        $session = $app->getSession();
        $oldId   = $session->getId();

        // Regenerate the session id first; if that fails nothing has been changed yet.
        if ($session->fork() === false) {
            throw new ImpersonationException('COM_FGLOGINASUSER_ERR_SESSION');
        }

        $target->guest = 0;
        $app->loadIdentity($target);
        $session->set('user', $target);

        // The actor is already authenticated, so no second-factor captive page for the target.
        $session->set('com_users.mfa_checked', 1);
        $session->set('com_users.remember_deferred', 0);

        if ($marker !== null) {
            $session->set(self::MARKER, json_encode($marker));
        } else {
            $session->clear(self::MARKER);
        }

        if ($app->get('session_metadata', true)) {
            $app->checkSession();
        }

        self::deleteSessionRow($oldId);

        if ($app->isClient('site')) {
            // Same "user state" cookie core sets for reverse caching proxies.
            $app->getInput()->cookie->set(
                'joomla_user_state',
                'logged_in',
                [
                    'expires'  => 0,
                    'path'     => $app->get('cookie_path', '/'),
                    'domain'   => $app->get('cookie_domain', ''),
                    'secure'   => $app->isHttpsForced(),
                    'httponly' => true,
                ]
            );
        }
    }

    /**
     * Destroy only the current session. Deliberately not CMSApplication::logout(): core's logout
     * handler also terminates every other session of that user, i.e. the real user's own logins.
     */
    public static function destroyCurrent(CMSApplication $app): void
    {
        $session = $app->getSession();
        $id      = $session->getId();

        $session->destroy();
        self::deleteSessionRow($id);

        if ($app->isClient('site')) {
            $app->getInput()->cookie->set(
                'joomla_user_state',
                '',
                [
                    'expires' => 1,
                    'path'    => $app->get('cookie_path', '/'),
                    'domain'  => $app->get('cookie_domain', ''),
                ]
            );
        }
    }

    private static function deleteSessionRow(string $sessionId): void
    {
        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->createQuery()
                ->delete($db->quoteName('#__session'))
                ->where($db->quoteName('session_id') . ' = :sid')
                ->bind(':sid', $sessionId);

            $db->setQuery($query)->execute();
        } catch (\RuntimeException) {
            // A stale session row is harmless and must never block the switch.
        }
    }
}
