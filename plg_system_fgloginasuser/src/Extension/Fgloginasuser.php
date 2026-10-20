<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace FG\Plugin\System\Fgloginasuser\Extension;

use FG\Component\Fgloginasuser\Administrator\Service\AccessPolicy;
use FG\Component\Fgloginasuser\Administrator\Service\ButtonRenderer;
use FG\Component\Fgloginasuser\Administrator\Service\EndRecorder;
use FG\Component\Fgloginasuser\Administrator\Service\SessionSwitcher;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\CMS\Event\User\LogoutEvent;
use Joomla\Event\Priority;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

/**
 * Companion of com_fgloginasuser. Presentation only, all logic stays in the component:
 *  - a fixed bar with a "return to my account" button while a session is impersonated
 *  - login buttons in the core Users list of the administrator (com_users)
 */
final class Fgloginasuser extends CMSPlugin implements SubscriberInterface
{
    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return [
            'onBeforeCompileHead' => 'onBeforeCompileHead',
            'onAfterRoute'        => 'onAfterRoute',
            'onAfterRender'       => 'onAfterRender',
            // Before core's user plugin, which would end all sessions of the impersonated user.
            'onUserLogout'        => ['onUserLogout', Priority::MAX],
        ];
    }

    /**
     * An ordinary logout (module, menu item, administrator menu) inside an impersonated session must only
     * end that impersonation. Core's handler would also destroy every other session of the impersonated
     * user - the real person's own logins - and would leave the audit log without an end.
     */
    public function onUserLogout(LogoutEvent $event): void
    {
        $app    = $this->getApplication();
        $marker = $app ? SessionSwitcher::getMarker($app) : null;

        if ($marker === null || $marker['client'] !== ($app->isClient('administrator') ? 'administrator' : 'site')) {
            return;
        }

        EndRecorder::record($app, $marker);
        SessionSwitcher::destroyCurrent($app);

        $event->stopPropagation();
    }

    /**
     * Absolute time limit: an impersonation older than "max_minutes" ends with the next request. The session is
     * destroyed (not handed back), so the person has to sign in again.
     */
    public function onAfterRoute(): void
    {
        $app    = $this->getApplication();
        $marker = $app ? SessionSwitcher::getMarker($app) : null;

        if ($marker === null || $marker['client'] !== ($app->isClient('administrator') ? 'administrator' : 'site') || !SessionSwitcher::isExpired($marker)) {
            return;
        }

        EndRecorder::record($app, $marker, 'COM_FGLOGINASUSER_NOTE_TIMEOUT');
        SessionSwitcher::destroyCurrent($app);
        $app->redirect($app->isClient('administrator') ? Uri::base() : Uri::root());
    }

    public function onBeforeCompileHead(): void
    {
        if (!$this->isUsersList()) {
            return;
        }

        $wa = $this->getApplication()->getDocument()->getWebAssetManager();
        $wa->registerAndUseScript('com_fgloginasuser.users', 'com_fgloginasuser/users.js', [], ['defer' => true]);
        $wa->registerAndUseStyle('com_fgloginasuser.admin', 'com_fgloginasuser/admin.css');

        $css = ButtonRenderer::customCss(ComponentHelper::getParams('com_fgloginasuser'));

        if ($css !== '') {
            $wa->addInlineStyle($css);
        }
    }

    public function onAfterRender(): void
    {
        $app = $this->getApplication();

        if (!$app || !($app->isClient('site') || $app->isClient('administrator'))) {
            return;
        }

        // Only full HTML pages; skip modal/iframe (tmpl=component) renderings and non-HTML output.
        if ($app->getDocument()->getType() !== 'html' || $app->getInput()->getCmd('tmpl', '') === 'component') {
            return;
        }

        if ($this->isUsersList()) {
            $this->injectUsersListButtons();
        }

        $this->injectBar();
    }

    /**
     * True on the core administrator Users list, for someone who may use com_fgloginasuser.
     */
    private function isUsersList(): bool
    {
        $app = $this->getApplication();

        if (
            !$app
            || !$app->isClient('administrator')
            || !(int) $this->params->get('show_in_users_list', 1)
            || $app->getInput()->getCmd('option') !== 'com_users'
            || $app->getInput()->getCmd('view', 'users') !== 'users'
            || $app->getInput()->getCmd('layout', 'default') !== 'default'
            || $app->getInput()->getCmd('tmpl', '') === 'component'
            || !class_exists(AccessPolicy::class)
        ) {
            return false;
        }

        $user = $app->getIdentity();

        return $user && !$user->guest && $user->authorise('core.manage', 'com_fgloginasuser');
    }

    /**
     * The buttons could not be inserted into the core user list (its markup probably changed).
     * Written to the log file (category com_fgloginasuser), at most once per session; nothing is shown to the visitor.
     */
    private function diagnose(string $reason): void
    {
        $app = $this->getApplication();

        try {
            $session = $app->getSession();

            if ($session->get('fgloginasuser.listwarned')) {
                return;
            }

            $session->set('fgloginasuser.listwarned', 1);

            Log::addLogger(['text_file' => 'com_fgloginasuser.log.php'], Log::WARNING, ['com_fgloginasuser']);
            Log::add(
                'Login-as buttons were not added to the Users list: ' . $reason . '. Joomla ' . JVERSION
                . ', FG Login as User plugin. The extension\'s own Users screen still works.',
                Log::WARNING,
                'com_fgloginasuser'
            );
        } catch (\Throwable $e) {
            // Diagnostics must never break the page.
        }
    }

    private function injectUsersListButtons(): void
    {
        $app  = $this->getApplication();
        $body = $app->getBody();

        $table = strpos($body, 'id="userList"');
        $start = $table === false ? false : strpos($body, '<tbody', $table);
        $end   = $start === false ? false : strpos($body, '</tbody>', $start);

        if ($table === false) {
            // No table at all: an empty list (nothing matched the filter) is normal, nothing to report.
            return;
        }

        if ($end === false) {
            $this->diagnose('the <tbody> of the user list was not found');

            return;
        }

        $app->getLanguage()->load('com_fgloginasuser', JPATH_ADMINISTRATOR);

        $params  = ComponentHelper::getParams('com_fgloginasuser');
        $policy  = new AccessPolicy($params);
        $factory = Factory::getContainer()->get(UserFactoryInterface::class);
        $actor   = $app->getIdentity();
        $added   = 0;
        $rows    = 0;

        $tbody  = substr($body, $start, $end - $start);
        $failed = false;

        $tbody = preg_replace_callback(
            '~<tr class="row\d+">.*?</tr>~s',
            function (array $match) use ($policy, $factory, $actor, $params, &$rows, &$added, &$failed): string {
                $row = $match[0];
                ++$rows;

                // The new cell goes right after the Username cell (row header cell, then the next cell).
                if (!preg_match('~</th>\s*<td\b[^>]*>.*?</td>~s', $row, $anchor, PREG_OFFSET_CAPTURE)) {
                    $failed = true;

                    return $row;
                }

                $buttons = '';

                if (
                    preg_match('~name="cid\[\]"\s+value="(\d+)"~', $row, $id)
                    || preg_match('~task=user\.edit&(?:amp;)?id=(\d+)~', $row, $id)
                ) {
                    $target = $factory->loadUserById((int) $id[1]);

                    if ($target->id && (int) $target->id !== (int) $actor->id) {
                        $buttons = ButtonRenderer::buttons(
                            $params,
                            (int) $target->id,
                            $target->username,
                            $policy->evaluate($actor, $target, AccessPolicy::CLIENT_SITE),
                            $policy->evaluate($actor, $target, AccessPolicy::CLIENT_ADMIN),
                            false,
                            true
                        );
                    }
                }

                $added += $buttons === '' ? 0 : 1;

                $cell = '<td class="d-none d-md-table-cell">'
                    . ($buttons === '' ? '' : '<div class="fg-las-inline">' . $buttons . '</div>') . '</td>';
                $at = $anchor[0][1] + \strlen($anchor[0][0]);

                return substr($row, 0, $at) . $cell . substr($row, $at);
            },
            $tbody
        );

        // The header cell, after the Username column header; all or nothing so the columns never get out of line.
        $head = substr($body, $table, $start - $table);

        if ($tbody === null) {
            $this->diagnose('processing the rows of the user list failed');

            return;
        }

        if ($rows === 0 && str_contains(substr($body, $start, $end - $start), '<tr')) {
            $this->diagnose('no row of the user list matched the expected markup (<tr class="rowN">)');

            return;
        }

        if ($failed) {
            $this->diagnose('a row of the user list has no username cell after its header cell');

            return;
        }

        if ($added === 0) {
            return;
        }

        if (!preg_match('~data-order="a\.username".*?</th>~s', $head, $hm, PREG_OFFSET_CAPTURE)) {
            $this->diagnose('the Username column header (data-order="a.username") was not found');

            return;
        }

        $at   = $hm[0][1] + \strlen($hm[0][0]);
        $head = substr($head, 0, $at) . '<th scope="col" class="d-none d-md-table-cell text-nowrap">'
            . htmlspecialchars(Text::_('COM_FGLOGINASUSER_COLUMN_LOGIN_AS'), ENT_QUOTES, 'UTF-8') . '</th>' . substr($head, $at);
        $body = substr($body, 0, $table) . $head . $tbody . substr($body, $end);

        // The one hidden form goes in right before the last </body>, i.e. outside the list form.
        $pos = strripos($body, '</body>');

        if ($pos !== false && !str_contains($body, 'id="fgLasForm"')) {
            $body = substr_replace($body, ButtonRenderer::form(), $pos, 0);
        }

        $app->setBody($body);
    }

    /**
     * Reason and remaining time shown in the bar.
     */
    private function barDetails(array $marker, callable $e): string
    {
        $html = '';

        if (($marker['reason'] ?? '') !== '') {
            $html .= '<span style="opacity:.9">' . $e(Text::sprintf('PLG_SYSTEM_FGLOGINASUSER_BAR_REASON', (string) $marker['reason'])) . '</span>';
        }

        if (!empty($marker['expires'])) {
            $html .= '<span style="opacity:.9">' . $e(Text::sprintf(
                'PLG_SYSTEM_FGLOGINASUSER_BAR_UNTIL',
                Factory::getDate((int) $marker['expires'])->setTimezone(new \DateTimeZone($this->getApplication()->get('offset', 'UTC')))->format('H:i')
            )) . '</span>';
        }

        return $html;
    }

    private function injectBar(): void
    {
        $app = $this->getApplication();
        $raw = $app->getSession()->get('fgloginasuser.marker');

        if (!\is_string($raw) || $raw === '') {
            return;
        }

        $marker = json_decode($raw, true);

        if (!\is_array($marker) || !isset($marker['client'])) {
            return;
        }

        $isAdmin = $app->isClient('administrator');

        if (($isAdmin ? 'administrator' : 'site') !== $marker['client']) {
            return;
        }

        $body = $app->getBody();
        $pos  = strripos($body, '</body>');

        if ($pos === false || str_contains($body, 'id="fg-las-bar"')) {
            return;
        }

        $token = Session::getFormToken();
        $url   = $isAdmin
            ? Uri::base() . 'index.php?option=com_fgloginasuser&task=impersonate.stop&' . $token . '=1'
            : Uri::root() . 'index.php?option=com_fgloginasuser&task=login.stop&' . $token . '=1';

        $label = $isAdmin ? 'PLG_SYSTEM_FGLOGINASUSER_RETURN_ADMIN' : 'PLG_SYSTEM_FGLOGINASUSER_RETURN_SITE';
        $e     = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

        $html = '<style>body{padding-bottom:56px!important}</style>'
            . '<div id="fg-las-bar" role="status" style="position:fixed;left:0;right:0;bottom:0;z-index:2147483000;'
            . 'display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:center;padding:10px 16px;'
            . 'background:#b45309;color:#fff;font:14px/1.4 system-ui,sans-serif;box-shadow:0 -2px 8px rgba(0,0,0,.3)">'
            . '<span>' . $e(Text::sprintf(
                'PLG_SYSTEM_FGLOGINASUSER_BAR',
                (string) ($marker['target_username'] ?? ''),
                (string) ($marker['actor_username'] ?? '')
            )) . '</span>'
            . $this->barDetails($marker, $e)
            . '<a href="' . $e($url) . '" style="background:#fff;color:#7c2d12;padding:6px 14px;border-radius:4px;'
            . 'font-weight:600;text-decoration:none">' . $e(Text::_($label)) . '</a></div>';

        $app->setBody(substr_replace($body, $html, $pos, 0));
    }
}
