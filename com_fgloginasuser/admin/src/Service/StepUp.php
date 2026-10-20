<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace FG\Component\Fgloginasuser\Administrator\Service;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Event\MultiFactor\Validate;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\CMS\User\UserHelper;
use Joomla\Component\Users\Administrator\Helper\Mfa as MfaHelper;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * Optional re-authentication ("step-up") of the administrator before an impersonation starts, so that a
 * stolen or left-open administrator session is not enough. Parameter "stepup_mode": 0 = off (default),
 * 1 = password, 2 = password and, when the account has one, a code of its second factor.
 *
 * A successful check is remembered in the session for "stepup_minutes" and only for that very user (an
 * impersonated session never inherits it). Wrong attempts are limited per session.
 *
 * Second-factor methods that need a browser ceremony or send the code on demand (WebAuthn, e-mail) cannot be
 * asked for here; an account that has only those is asked for the password only.
 */
final class StepUp
{
    private const SESSION_OK   = 'fgloginasuser.stepup';
    private const SESSION_FAIL = 'fgloginasuser.stepup_fail';
    private const MAX_FAILURES = 5;
    private const LOCK_SECONDS = 900;
    private const UNSUPPORTED  = ['webauthn', 'email', 'backupcodes'];

    public static function mode(Registry $params): int
    {
        return max(0, min(2, (int) $params->get('stepup_mode', 0)));
    }

    public static function minutes(Registry $params): int
    {
        return max(1, min(60, (int) $params->get('stepup_minutes', 5)));
    }

    /**
     * Is a recent successful check of this very user still valid?
     */
    public static function isFresh(CMSApplication $app, Registry $params, User $actor): bool
    {
        if (self::mode($params) === 0) {
            return true;
        }

        $raw = json_decode((string) $app->getSession()->get(self::SESSION_OK, ''), true);

        return \is_array($raw)
            && (int) ($raw['user'] ?? 0) === (int) $actor->id
            && (int) ($raw['until'] ?? 0) > time();
    }

    /**
     * Must a second-factor code be entered as well?
     */
    public static function needsCode(CMSApplication $app, Registry $params, User $actor): bool
    {
        return self::mode($params) === 2 && self::factors($app, $actor)['state'] === 'code';
    }

    /**
     * @return  string|null  Language key of the refusal, null when the check passed (and is remembered).
     */
    public static function verify(CMSApplication $app, Registry $params, User $actor, string $password, string $code): ?string
    {
        if (self::isFresh($app, $params, $actor)) {
            return null;
        }

        $session = $app->getSession();
        $fail    = json_decode((string) $session->get(self::SESSION_FAIL, ''), true);
        $fail    = \is_array($fail) ? $fail : ['n' => 0, 'ts' => 0];

        if ($fail['n'] >= self::MAX_FAILURES) {
            if (time() - (int) $fail['ts'] < self::LOCK_SECONDS) {
                return 'COM_FGLOGINASUSER_ERR_STEPUP_LOCKED';
            }

            $fail = ['n' => 0, 'ts' => 0];
        }

        // Fail closed: when the second factor is required, a failure to find out about it must never fall back to the
        // password alone, and an account whose factors cannot be asked for here is refused if the administrator chose so.
        if (self::mode($params) === 2) {
            $state = self::factors($app, $actor)['state'];

            if ($state === 'error') {
                return 'COM_FGLOGINASUSER_ERR_STEPUP_MFA_UNAVAILABLE';
            }

            if ($state === 'unsupported' && (int) $params->get('stepup_unsupported', 0) === 1) {
                return 'COM_FGLOGINASUSER_ERR_STEPUP_MFA_UNSUPPORTED';
            }
        }

        // Nothing typed (for instance the dialog was opened while the check was still valid): ask, do not count.
        if ($password === '' || (self::needsCode($app, $params, $actor) && trim($code) === '')) {
            return 'COM_FGLOGINASUSER_ERR_STEPUP_REQUIRED';
        }

        $error = self::passwordOk($actor, $password) ? null : 'COM_FGLOGINASUSER_ERR_STEPUP_PASSWORD';

        if ($error === null && self::needsCode($app, $params, $actor) && !self::codeOk($app, $actor, trim($code))) {
            $error = 'COM_FGLOGINASUSER_ERR_STEPUP_CODE';
        }

        if ($error !== null) {
            $session->set(self::SESSION_FAIL, json_encode(['n' => (int) $fail['n'] + 1, 'ts' => time()]));

            return $error;
        }

        $session->clear(self::SESSION_FAIL);
        $session->set(self::SESSION_OK, json_encode(['user' => (int) $actor->id, 'until' => time() + self::minutes($params) * 60]));

        return null;
    }

    private static function passwordOk(User $actor, string $password): bool
    {
        // The hash comes from the database, not from the user object kept in the session.
        $stored = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById((int) $actor->id);

        return $stored->id && (string) $stored->password !== '' && UserHelper::verifyPassword($password, (string) $stored->password, (int) $stored->id);
    }

    /**
     * What the account has in the way of second factors:
     *  - code:        at least one method a typed code can be checked against ("records")
     *  - unsupported: only methods that cannot be asked for here (WebAuthn, e-mail)
     *  - none:        no active second factor
     *  - error:       the records could not be read (never to be treated as "none")
     *
     * @return  array{state:string, records:array}
     */
    private static function factors(CMSApplication $app, User $actor): array
    {
        try {
            // Our own query: core's helper swallows database errors and would report "no second factor".
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $id    = (int) $actor->id;
            $query = $db->createQuery()
                ->select($db->quoteName('method'))
                ->from($db->quoteName('#__user_mfa'))
                ->where($db->quoteName('user_id') . ' = :id')
                ->bind(':id', $id, ParameterType::INTEGER);
            $methods = array_map('strval', $db->setQuery($query)->loadColumn() ?: []);

            // Only methods whose plugin is active count (core skips the others at login as well); backup codes alone are no factor.
            $active   = array_map('strval', array_keys(MfaHelper::getMfaMethods()));
            $relevant = array_values(array_filter($methods, static fn (string $m): bool => $m !== 'backupcodes' && \in_array($m, $active, true)));

            if ($relevant === []) {
                return ['state' => 'none', 'records' => []];
            }

            $usable = array_values(array_filter($relevant, static fn (string $m): bool => !\in_array($m, self::UNSUPPORTED, true)));

            if ($usable === []) {
                return ['state' => 'unsupported', 'records' => []];
            }

            $model   = $app->bootComponent('com_users')->getMVCFactory()->createModel('Captive', 'Administrator', ['ignore_request' => true]);
            $records = array_values(array_filter(
                $model->getRecords($actor, false),
                static fn ($record): bool => !\in_array($record->method, self::UNSUPPORTED, true)
            ));

            // The rows exist but could not be loaded: that is an error, not "no factor".
            if ($records === []) {
                throw new \RuntimeException('Second factor records could not be loaded');
            }

            return ['state' => 'code', 'records' => $records];
        } catch (\Throwable $e) {
            Log::add('Second factor lookup failed: ' . $e->getMessage(), Log::WARNING, 'com_fgloginasuser');

            return ['state' => 'error', 'records' => []];
        }
    }

    private static function codeOk(CMSApplication $app, User $actor, string $code): bool
    {
        $model = $app->bootComponent('com_users')->getMVCFactory()->createModel('Captive', 'Administrator', ['ignore_request' => true]);

        foreach (self::factors($app, $actor)['records'] as $record) {
            // Core's own try limit per method (counts every attempt, resets on success).
            if (!$model->checkTryLimit($record)) {
                continue;
            }

            $event   = new Validate($record, $actor, $code);
            $results = $app->getDispatcher()->dispatch($event->getName(), $event)->getArgument('result', []);

            if (array_reduce($results, static fn (bool $carry, $r): bool => $carry || (bool) $r, false)) {
                $record->tries     = 0;
                $record->last_try  = null;
                $record->last_used = Factory::getDate()->toSql();
                $record->store();

                return true;
            }
        }

        return false;
    }
}
