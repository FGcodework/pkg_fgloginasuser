<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace FG\Component\Fgloginasuser\Site\Controller;

use FG\Component\Fgloginasuser\Administrator\Service\AccessPolicy;
use FG\Component\Fgloginasuser\Administrator\Service\ActionLogger;
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
     * GET index.php?option=com_fgloginasuser&task=login.consume&token=...
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
        $token  = (string) $this->input->get->get('token', '', 'ALNUM');
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
            $log->markDenied((int) $row->id, 'Token used from a different IP address: ' . $ip);
            ActionLogger::log($app, ActionLogger::DENIED, (int) $row->actor_id, (string) $row->actor_username, (int) $row->target_id, (string) $row->target_username, 'Token used from a different IP address: ' . $ip);
            $this->setRedirect($home, Text::_('COM_FGLOGINASUSER_SITE_ERR_IP'), 'error');

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
            $log->markDenied((int) $row->id, $error);
            ActionLogger::log($app, ActionLogger::DENIED, (int) $row->actor_id, (string) $row->actor_username, (int) $row->target_id, (string) $row->target_username, $error);
            $this->setRedirect($home, Text::_('COM_FGLOGINASUSER_SITE_ERR_DENIED'), 'error');

            return;
        }

        Notifier::send($app, $params, $actor->username, $target->username, AccessPolicy::CLIENT_SITE, $ip);
        ActionLogger::log($app, ActionLogger::STARTED_SITE, (int) $actor->id, $actor->username, (int) $target->id, $target->username);

        SessionSwitcher::switchTo($app, $target, [
            'log_id'          => (int) $row->id,
            'actor_id'        => (int) $actor->id,
            'actor_username'  => $actor->username,
            'target_id'       => (int) $target->id,
            'target_username' => $target->username,
            'client'          => AccessPolicy::CLIENT_SITE,
        ]);

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

        (new LogStore(Factory::getContainer()->get(DatabaseInterface::class)))->end((int) ($marker['log_id'] ?? 0));

        ActionLogger::log(
            $this->app,
            ActionLogger::ENDED,
            (int) $marker['actor_id'],
            (string) ($marker['actor_username'] ?? ''),
            (int) ($marker['target_id'] ?? 0),
            (string) ($marker['target_username'] ?? '')
        );

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
