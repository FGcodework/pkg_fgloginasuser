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
use FG\Component\Fgloginasuser\Administrator\Service\Justification;
use FG\Component\Fgloginasuser\Administrator\Service\LogStore;
use FG\Component\Fgloginasuser\Administrator\Service\Notifier;
use FG\Component\Fgloginasuser\Administrator\Service\SessionSwitcher;
use FG\Component\Fgloginasuser\Administrator\Service\StepUp;
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

        $reason = Justification::clean($this->input->post->getString('reason', ''));

        if (($reasonError = Justification::validate($params, $reason)) !== null) {
            $this->deny($log, $actor, $target, $targetId, $client, $ip, $reasonError);

            return;
        }

        // Re-authentication of the administrator (off by default). Never passed on or stored.
        $stepError = StepUp::verify(
            $this->app,
            $params,
            $actor,
            (string) $this->input->post->get('stepup_password', '', 'RAW'),
            (string) $this->input->post->getString('stepup_code', '')
        );

        if ($stepError !== null) {
            $this->deny($log, $actor, $target, $targetId, $client, $ip, $stepError);

            return;
        }

        $log->purgeOlderThan((int) $params->get('log_retention_days', 365), Environment::sessionsInDatabase($this->app));

        if ($client === AccessPolicy::CLIENT_SITE) {
            $ttl     = max(10, min(600, (int) $params->get('token_ttl', 60)));
            $token   = bin2hex(random_bytes(32));
            $expires = Factory::getDate('+' . $ttl . ' seconds')->toSql();

            $maxPending = max(1, min(100, (int) $params->get('max_pending', 10)));

            // The limit check and the insert are one atomic step (see LogStore).
            $created = $log->createPendingLimited(
                $actor,
                $target,
                $ip,
                hash('sha256', $token),
                $expires,
                $reason,
                $maxPending
            );

            if ($created === null) {
                $this->deny($log, $actor, $target, $targetId, $client, $ip, 'COM_FGLOGINASUSER_ERR_TOO_MANY');

                return;
            }

            $this->handOver($token);

            return;
        }

        // Backend: switch this very session.
        $logId = $log->create($actor, $target, $client, LogStore::STATUS_ACTIVE, $ip, '', null, null, $reason);

        try {
            SessionSwitcher::switchTo($this->app, $target, [
                'log_id'          => $logId,
                'actor_id'        => (int) $actor->id,
                'actor_username'  => $actor->username,
                'target_id'       => (int) $target->id,
                'target_username' => $target->username,
                'client'          => AccessPolicy::CLIENT_ADMIN,
                'reason'          => $reason,
                'expires'         => SessionSwitcher::deadline($params),
            ]);
        } catch (ImpersonationException $e) {
            // Nothing was switched: record the failure instead of a login that never happened.
            $log->markDenied($logId, $e->getMessage(), LogStore::STATUS_ACTIVE);
            ActionLogger::log($this->app, ActionLogger::DENIED, (int) $actor->id, $actor->username, (int) $target->id, $target->username, $e->getMessage());
            $this->setMessage(Text::_($e->getMessage()), 'error');

            return;
        }

        try {
            $log->bindSession($logId, $this->app->getSession()->getId());
        } catch (\Throwable $e) {
            \Joomla\CMS\Log\Log::add('Could not record the session of an impersonation: ' . $e->getMessage(), \Joomla\CMS\Log\Log::WARNING, 'com_fgloginasuser');
        }

        // Reported only once the identity has really been switched.
        Notifier::send($this->app, $params, $actor->username, $target->username, $client, $ip, $reason);
        ActionLogger::log($this->app, $reason !== '' ? ActionLogger::STARTED_ADMIN_REASON : ActionLogger::STARTED_ADMIN, (int) $actor->id, $actor->username, (int) $target->id, $target->username, $reason);

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

        if (!AccessPolicy::canRestore($actor)) {
            // The original account can no longer log in: end the session instead of restoring it.
            SessionSwitcher::destroyCurrent($this->app);
            $this->setRedirect(Route::_('index.php', false));

            return;
        }

        SessionSwitcher::switchTo($this->app, $actor, null);

        $this->setRedirect(Route::_('index.php?option=com_fgloginasuser&view=users', false));
    }

    /**
     * Hand the one-time token to the site in the body of a POST request, so that it never appears in a URL
     * (browser history, server and proxy logs, Referer headers). The page submits itself with an external script
     * (no inline script, works under a strict Content-Security-Policy); the button is the fallback.
     */
    private function handOver(string $token): void
    {
        $e      = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $action = Uri::root() . 'index.php?option=com_fgloginasuser&task=login.consume';
        $script = Uri::root(true) . '/media/com_fgloginasuser/js/handoff.js';

        $this->app->setHeader('Content-Type', 'text/html; charset=utf-8', true);
        $this->app->setHeader('Referrer-Policy', 'no-referrer', true);
        $this->app->setHeader('Cache-Control', 'no-store, max-age=0', true);
        $this->app->sendHeaders();

        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $e(Text::_('COM_FGLOGINASUSER_HANDOFF_TITLE')) . '</title></head>'
            . '<body style="font:16px/1.5 system-ui,sans-serif;margin:3rem;text-align:center">'
            . '<form id="fgLasHandoff" method="post" action="' . $e($action) . '">'
            . '<input type="hidden" name="token" value="' . $e($token) . '">'
            . '<p>' . $e(Text::_('COM_FGLOGINASUSER_HANDOFF_BODY')) . '</p>'
            . '<button type="submit" style="font:inherit;padding:.5rem 1.2rem">' . $e(Text::_('COM_FGLOGINASUSER_HANDOFF_BUTTON')) . '</button>'
            . '</form><script src="' . $e($script) . '"></script></body></html>';

        $this->app->close();
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
