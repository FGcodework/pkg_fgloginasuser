<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace FG\Component\Fgloginasuser\Administrator\Controller;

use FG\Component\Fgloginasuser\Administrator\Service\AccessPolicy;
use FG\Component\Fgloginasuser\Administrator\Exception\ImpersonationException;
use FG\Component\Fgloginasuser\Administrator\Service\ActionLogger;
use FG\Component\Fgloginasuser\Administrator\Service\EndRecorder;
use FG\Component\Fgloginasuser\Administrator\Service\Environment;
use FG\Component\Fgloginasuser\Administrator\Service\LogStore;
use FG\Component\Fgloginasuser\Administrator\Service\Notifier;
use FG\Component\Fgloginasuser\Administrator\Service\SessionSwitcher;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Utilities\IpHelper;

\defined('_JEXEC') or die;

/**
 * Starts and stops an impersonation from the administrator.
 */
class ImpersonateController extends BaseController
{
    /**
     * Start impersonating a user (POST, CSRF protected).
     *
     * Frontend: a one-time token is stored and the browser is redirected to the site, where the
     * token is exchanged for a session (the site session is separate from the admin session).
     * Backend: the current administrator session is switched to the target user in place.
     */
    public function start(): void
    {
        $this->checkToken('post');
        $this->app->setHeader('Cache-Control', 'no-store, max-age=0', true);
        $this->setRedirect(Route::_('index.php?option=com_fgloginasuser&view=users', false));

        $params  = ComponentHelper::getParams('com_fgloginasuser');
        $actor   = $this->app->getIdentity();
        $ip      = IpHelper::getIp();
        $client  = AccessPolicy::normaliseClient($this->input->post->getCmd('client', ''));
        $targetId = $this->input->post->getInt('id', 0);

        $container = Factory::getContainer();
        $log       = new LogStore($container->get(DatabaseInterface::class));
        $target    = $container->get(UserFactoryInterface::class)->loadUserById($targetId);

        if ($client === null) {
            $this->deny($log, $actor, null, $targetId, AccessPolicy::CLIENT_SITE, $ip, 'COM_FGLOGINASUSER_ERR_CLIENT');

            return;
        }

        if (SessionSwitcher::getMarker($this->app) !== null) {
            $this->deny($log, $actor, $target, $targetId, $client, $ip, 'COM_FGLOGINASUSER_ERR_ALREADY');

            return;
        }

        $error = (new AccessPolicy($params))->evaluate($actor, $target, $client) ?? Environment::check($this->app);

        if ($error !== null) {
            $this->deny($log, $actor, $target, $targetId, $client, $ip, $error);

            return;
        }

        $log->purgeOlderThan((int) $params->get('log_retention_days', 365));

        if ($client === AccessPolicy::CLIENT_SITE) {
            $ttl     = max(10, min(600, (int) $params->get('token_ttl', 60)));
            $token   = bin2hex(random_bytes(32));
            $expires = Factory::getDate('+' . $ttl . ' seconds')->toSql();

            $log->create(
                $actor,
                $target,
                $client,
                LogStore::STATUS_PENDING,
                $ip,
                '',
                hash('sha256', $token),
                $expires
            );

            $this->setRedirect(
                Uri::root() . 'index.php?option=com_fgloginasuser&task=login.consume&token=' . $token
            );

            return;
        }

        // Backend: switch this very session.
        $logId = $log->create($actor, $target, $client, LogStore::STATUS_ACTIVE, $ip);

        try {
            SessionSwitcher::switchTo($this->app, $target, [
                'log_id'          => $logId,
                'actor_id'        => (int) $actor->id,
                'actor_username'  => $actor->username,
                'target_id'       => (int) $target->id,
                'target_username' => $target->username,
                'client'          => AccessPolicy::CLIENT_ADMIN,
            ]);
        } catch (ImpersonationException $e) {
            // Nothing was switched: record the failure instead of a login that never happened.
            $log->markDenied($logId, $e->getMessage(), LogStore::STATUS_ACTIVE);
            ActionLogger::log($this->app, ActionLogger::DENIED, (int) $actor->id, $actor->username, (int) $target->id, $target->username, $e->getMessage());
            $this->setMessage(Text::_($e->getMessage()), 'error');

            return;
        }

        // Reported only once the identity has really been switched.
        Notifier::send($this->app, $params, $actor->username, $target->username, $client, $ip);
        ActionLogger::log($this->app, ActionLogger::STARTED_ADMIN, (int) $actor->id, $actor->username, (int) $target->id, $target->username);

        $redirect = trim((string) $params->get('admin_redirect_url', ''));

        if ($redirect === '' || !Uri::isInternal($redirect)) {
            $redirect = 'index.php';
        }

        $this->setRedirect($redirect);
    }

    /**
     * Leave a backend impersonation and return to the original administrator (GET + token).
     */
    public function stop(): void
    {
        $this->checkToken('get');

        $marker = SessionSwitcher::getMarker($this->app);

        if ($marker === null || $marker['client'] !== AccessPolicy::CLIENT_ADMIN) {
            $this->setRedirect(Route::_('index.php', false), Text::_('COM_FGLOGINASUSER_NOT_IMPERSONATING'), 'warning');

            return;
        }

        $container = Factory::getContainer();

        // Audit problems must never keep the user inside the foreign identity.
        EndRecorder::record($this->app, $marker);

        $actor = $container->get(UserFactoryInterface::class)->loadUserById((int) $marker['actor_id']);

        if (!$actor->id || (int) $actor->block === 1 || !$actor->authorise('core.login.admin')) {
            // The original account can no longer log in: end the session instead of restoring it.
            SessionSwitcher::destroyCurrent($this->app);
            $this->setRedirect(Route::_('index.php', false));

            return;
        }

        SessionSwitcher::switchTo($this->app, $actor, null);

        $this->setRedirect(Route::_('index.php?option=com_fgloginasuser&view=users', false));
    }

    private function deny(
        LogStore $log,
        \Joomla\CMS\User\User $actor,
        ?\Joomla\CMS\User\User $target,
        int $requestedId,
        string $client,
        string $ip,
        string $languageKey
    ): void {
        $log->create($actor, $target, $client, LogStore::STATUS_DENIED, $ip, $languageKey);
        ActionLogger::log(
            $this->app,
            ActionLogger::DENIED,
            (int) $actor->id,
            $actor->username,
            $requestedId,
            $target && $target->id ? $target->username : '',
            $languageKey
        );
        $this->setMessage(Text::_($languageKey), 'error');
    }
}
