<?php
/**
 * Boots a Joomla installation (path in $argv[1]) and checks LogStore::reconcileActive() for sessions that are NOT
 * kept in the database (cannot be looked up): such rows must not be closed because of a missing session row.
 * Prints one JSON object; used by release123.py.
 */
const _JEXEC = 1;
define('JPATH_BASE', $argv[1] ?? '/tmp/jtest/www');
require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';
require_once JPATH_ADMINISTRATOR . '/components/com_fgloginasuser/src/Service/LogStore.php';

use FG\Component\Fgloginasuser\Administrator\Service\LogStore;
use Joomla\CMS\Factory;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;

// Same bootstrap as cli/joomla.php: a console application provides the Factory::getApplication() that services expect.
$container = Factory::getContainer();
$container->alias('session', 'session.cli')
    ->alias('JSession', 'session.cli')
    ->alias(\Joomla\CMS\Session\Session::class, 'session.cli')
    ->alias(\Joomla\Session\Session::class, 'session.cli')
    ->alias(\Joomla\Session\SessionInterface::class, 'session.cli');
$app = $container->get(\Joomla\Console\Application::class);
Factory::$application = $app;

$c     = Factory::getContainer();
$db    = $c->get(DatabaseInterface::class);
$users = $c->get(UserFactoryInterface::class);
$store = new LogStore($db);
$actor = $users->loadUserById((int) $argv[2]);
$tgt   = $users->loadUserById((int) $argv[3]);

$mk = function (string $minutesAgo, string $hash) use ($db, $store, $actor, $tgt): int {
    $id = $store->create($actor, $tgt, 'administrator', LogStore::STATUS_ACTIVE, '1.1.1.1');
    $when = Factory::getDate('-' . $minutesAgo)->toSql();
    $db->setQuery('UPDATE ' . $db->quoteName('#__fgloginasuser_logs') . ' SET created = ' . $db->quote($when) . ', started = ' . $db->quote($when)
        . ', session_hash = ' . $db->quote($hash) . ' WHERE id = ' . $id)->execute();

    return $id;
};
$status = fn (int $id): string => (string) $db->setQuery('SELECT status FROM ' . $db->quoteName('#__fgloginasuser_logs') . ' WHERE id = ' . $id)->loadResult();

$withHash = $mk('3 hours', hash('sha256', 'not-in-the-table'));
$legacy   = $mk('3 days', '');
$young    = $mk('3 hours', '');

$out = ['changed' => $store->reconcileActive(false)];
$out['with_hash_3h_kept']     = $status($withHash);
$out['no_hash_3d_presumed']   = $status($legacy);
$out['no_hash_3h_kept']       = $status($young);
$out['changed_when_in_db']    = $store->reconcileActive(true);
$out['with_hash_3h_when_db']  = $status($withHash);
$db->setQuery('DELETE FROM ' . $db->quoteName('#__fgloginasuser_logs') . ' WHERE id IN (' . implode(',', [$withHash, $legacy, $young]) . ')')->execute();
echo json_encode($out), "\n";
