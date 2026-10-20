<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace FG\Component\Fgloginasuser\Site\Controller;

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
 * Frontend end of the impersonation: exchanges the one-time token issued in the administrator
 * for a frontend session, and ends such a session again.
 */
class LoginController extends BaseController
{
    /**
     * POST index.php?option=com_fgloginasuser&task=login.consume, field "token" (sent by the administrator's hand-over
     * page, never as part of a URL).
     *
     * The token is the credential (256 random bits, stored only as a hash, single use, short
     * lived, optionally bound to the IP address that requested it), so no CSRF token applies.
     */
    public function consume(): void
    {
        $app = $this->app;
        $app->setHeader('Cache-Control', 'no-store, max-age=0', true);
        $app->setHeader('Referrer-Policy', 'no-referrer', true);

        $home   = Uri::root();
        $params = ComponentHelper::getParams('com_fgloginasuser');
        // Only from the request body: a token in the query string would end up in logs and the browser history.
        $token  = $this->input->getMethod() === 'POST' ? (string) $this->input->post->get('token', '', 'ALNUM') : '';
        $ip     = IpHelper::getIp();

        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            $this->setRedirect($home, Text::_('COM_FGLOGINASUSER_SITE_ERR_TOKEN'), 'error');

            return;
        }

        $container = Factory::getContainer();
        $log       = new LogStore($container->get(DatabaseInterface::class));
        $hash      = hash('sha256', $token);
        $row       = $log->findPending($hash);

        if ($row === null || $row->client !== AccessPolicy::CLIENT_SITE) {
            $this->setRedirect($home, Text::_('COM_FGLOGINASUSER_SITE_ERR_TOKEN'), 'error');

            return;
        }

        if ((int) $params->get('bind_ip', 1) && !hash_equals((string) $row->ip, $ip)) {
            $reason = 'COM_FGLOGINASUSER_ERR_TOKEN_IP|' . $ip;
            $log->markDenied((int) $row->id, $reason, LogStore::STATUS_PENDING);
            ActionLogger::log($app, ActionLogger::DENIED, (int) $row->actor_id, (string) $row->actor_username, (int) $row->target_id, (string) $row->target_username, $reason);
            $this->setRedirect($home, Text::_('COM_FGLOGINASUSER_SITE_ERR_IP'), 'error');

            return;
        }

        if (($envError = Environment::check($app)) !== null) {
            $log->markDenied((int) $row->id, $envError, LogStore::STATUS_PENDING);
            ActionLogger::log($app, ActionLogger::DENIED, (int) $row->actor_id, (string) $row->actor_username, (int) $row->target_id, (string) $row->target_username, $envError);
            $this->setRedirect($home, Text::_('COM_FGLOGINASUSER_SITE_ERR_DENIED'), 'error');

            return;
        }

        // Exactly one request can win this race; a replay finds the token gone.
        if (!$log->claim((int) $row->id, $hash)) {
            $this->setRedirect($home, Text::_('COM_FGLOGINASUSER_SITE_ERR_TOKEN'), 'error');

            return;
        }

        $userFactory = $container->get(UserFactoryInterface::class);
        $actor       = $userFactory->loadUserById((int) $row->actor_id);
        $target      = $userFactory->loadUserById((int) $row->target_id);

        // Permissions may have changed in the seconds since the token was issued.
        $error = (new AccessPolicy($params))->evaluate($actor, $target, AccessPolicy::CLIENT_SITE);

        if ($error !== null) {
            $log->markDenied((int) $row->id, $error, LogStore::STATUS_ACTIVE);
            ActionLogger::log($app, ActionLogger::DENIED, (int) $row->actor_id, (string) $row->actor_username, (int) $row->target_id, (string) $row->target_username, $error);
            $this->setRedirect($home, Text::_('COM_FGLOGINASUSER_SITE_ERR_DENIED'), 'error');

            return;
        }

        // A session that is still impersonating someone else (second click, second tab): close that one properly first.
        if (($previous = SessionSwitcher::getMarker($app)) !== null) {
            EndRecorder::record($app, $previous, 'COM_FGLOGINASUSER_NOTE_REPLACED');
        }

        try {
            SessionSwitcher::switchTo($app, $target, [
                'log_id'          => (int) $row->id,
                'actor_id'        => (int) $actor->id,
                'actor_username'  => $actor->username,
                'target_id'       => (int) $target->id,
                'target_username' => $target->username,
                'client'          => AccessPolicy::CLIENT_SITE,
                'reason'          => (string) ($row->reason ?? ''),
                'expires'         => SessionSwitcher::deadline($params),
            ]);
        } catch (ImpersonationException $e) {
            $log->markDenied((int) $row->id, $e->getMessage(), LogStore::STATUS_ACTIVE);
            ActionLogger::log($app, ActionLogger::DENIED, (int) $row->actor_id, (string) $row->actor_username, (int) $row->target_id, (string) $row->target_username, $e->getMessage());
            $this->setRedirect($home, Text::_('COM_FGLOGINASUSER_SITE_ERR_DENIED'), 'error');

            return;
        }

        // Reported only once the identity has really been switched.
        try {
            $log->bindSession((int) $row->id, $app->getSession()->getId());
        } catch (\Throwable $e) {
            \Joomla\CMS\Log\Log::add('Could not record the session of an impersonation: ' . $e->getMessage(), \Joomla\CMS\Log\Log::WARNING, 'com_fgloginasuser');
        }

        $reason = (string) ($row->reason ?? '');

        Notifier::send($app, $params, $actor->username, $target->username, AccessPolicy::CLIENT_SITE, $ip, $reason);
        ActionLogger::log($app, $reason !== '' ? ActionLogger::STARTED_SITE_REASON : ActionLogger::STARTED_SITE, (int) $actor->id, $actor->username, (int) $target->id, $target->username, $reason);

        $this->setRedirect($this->redirectUrl($params));
    }

    /**
     * End a frontend impersonation (GET + session token) and return to the administrator.
     */
    public function stop(): void
    {
        $this->checkToken('get');

        $marker = SessionSwitcher::getMarker($this->app);

        if ($marker === null || $marker['client'] !== AccessPolicy::CLIENT_SITE) {
            $this->setRedirect(Uri::root());

            return;
        }

        EndRecorder::record($this->app, $marker);

        SessionSwitcher::destroyCurrent($this->app);

        $this->setRedirect(Uri::root() . 'administrator/');
    }

    private function redirectUrl(\Joomla\Registry\Registry $params): string
    {
        $itemId = (int) $params->get('site_redirect_menuitem', 0);

        if ($itemId > 0) {
            return Route::_('index.php?Itemid=' . $itemId, false);
        }

        $url = trim((string) $params->get('site_redirect_url', ''));

        if ($url !== '' && Uri::isInternal($url)) {
            return $url;
        }

        return Uri::root();
    }
}
