<?php
/**
 * Boots a Joomla installation (path in $argv[1]) and exercises LogStore against the real database.
 * Prints one JSON object; used by audit.py (finding: a refusal must not overwrite a concurrent success).
 */
const _JEXEC = 1;
define('JPATH_BASE', $argv[1] ?? '/tmp/jtest/www');
require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';
// Optional 4th argument: another copy of LogStore.php (to demonstrate the behaviour of an older release).
require_once $argv[4] ?? JPATH_ADMINISTRATOR . '/components/com_fgloginasuser/src/Service/LogStore.php';

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

$status = fn (int $id): string => (string) $db->setQuery('SELECT status FROM ' . $db->quoteName('#__fgloginasuser_logs') . ' WHERE id = ' . $id)->loadResult();
$out    = [];

$hash = hash('sha256', random_bytes(8));
$id   = $store->create($actor, $tgt, 'site', LogStore::STATUS_PENDING, '1.1.1.1', '', $hash, Factory::getDate('+60 seconds')->toSql());
$out['claimed']                 = $store->claim($id, $hash);
$out['status_after_claim']      = $status($id);
// A refusal that raced with the successful claim must not rewrite it.
$sig = (new ReflectionMethod($store, 'markDenied'))->getNumberOfParameters();
$out['denied_from_pending_hit'] = $sig >= 3 ? $store->markDenied($id, 'late refusal', LogStore::STATUS_PENDING) : $store->markDenied($id, 'late refusal');
$out['status_after_late_refusal'] = $status($id);
if ($sig >= 3) {
    $out['denied_from_active_hit'] = $store->markDenied($id, 'real refusal', LogStore::STATUS_ACTIVE);
    $out['status_after_active_refusal'] = $status($id);
}
echo json_encode($out), "\n";
