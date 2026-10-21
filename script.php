<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

return new class () implements InstallerScriptInterface {
    /** @var array<string, bool> Companion plugins that were already installed before this run. */
    private array $existed = [];

    /** True when the version installed before this run is older than 1.2.0 (set in preflight). */
    private bool $updatedFromOlderThan120 = false;

    public function install(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function update(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function uninstall(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type === 'uninstall') {
            return true;
        }

        if (version_compare(JVERSION, '5.4.0', '<')) {
            Factory::getApplication()->enqueueMessage('FG Login as User requires Joomla 5.4 or newer.', 'error');

            return false;
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);

        foreach (['system', 'actionlog'] as $folder) {
            $this->existed[$folder] = $this->pluginExists($db, $folder);
        }

        $query = $db->createQuery()
            ->select($db->quoteName('manifest_cache'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('com_fgloginasuser'));
        $installed = (string) (json_decode((string) $db->setQuery($query)->loadResult(), true)['version'] ?? '');

        $this->updatedFromOlderThan120 = $installed !== '' && version_compare($installed, '1.2.0', '<');

        if (!str_contains(strtolower($db->getServerType()), 'mysql')) {
            Factory::getApplication()->enqueueMessage('FG Login as User supports MySQL / MariaDB only.', 'error');

            return false;
        }

        return true;
    }

    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        // Enable each companion plugin only the first time it is installed (an update, including the
        // first update that adds the action log plugin, never overrides a later choice of the admin).
        if ($type === 'uninstall') {
            return true;
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);

        // 1.2.0 added "no higher rights" (on for new installs). Existing installs keep their behaviour until the
        // administrator decides: the option is stored as off unless it has been set.
        if ($type === 'update') {
            $this->keepOpenBehaviour($db);
        }

        foreach (['system', 'actionlog'] as $folder) {
            if (empty($this->existed[$folder])) {
                $enabled = 1;
                $query   = $db->createQuery()
                    ->update($db->quoteName('#__extensions'))
                    ->set($db->quoteName('enabled') . ' = :enabled')
                    ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                    ->where($db->quoteName('folder') . ' = ' . $db->quote($folder))
                    ->where($db->quoteName('element') . ' = ' . $db->quote('fgloginasuser'))
                    ->bind(':enabled', $enabled);

                $db->setQuery($query)->execute();
            }
        }

        return true;
    }

    private function keepOpenBehaviour(DatabaseInterface $db): void
    {
        $query = $db->createQuery()
            ->select([$db->quoteName('extension_id'), $db->quoteName('params')])
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('com_fgloginasuser'));
        $row = $db->setQuery($query)->loadObject();

        // Not for an update that merely re-installs a 1.2.x or newer package: the option is known by then.
        if (!$row || !$this->updatedFromOlderThan120) {
            return;
        }

        $params = new \Joomla\Registry\Registry((string) $row->params);

        if ($params->exists('block_higher')) {
            return;
        }

        $params->set('block_higher', 0);
        $json = $params->toString();
        $id   = (int) $row->extension_id;
        $upd  = $db->createQuery()
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('params') . ' = :params')
            ->where($db->quoteName('extension_id') . ' = :id')
            ->bind(':params', $json)
            ->bind(':id', $id, ParameterType::INTEGER);

        $db->setQuery($upd)->execute();
    }

    private function pluginExists(DatabaseInterface $db, string $folder): bool
    {
        $query = $db->createQuery()
            ->select('COUNT(*)')
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote($folder))
            ->where($db->quoteName('element') . ' = ' . $db->quote('fgloginasuser'));

        return (int) $db->setQuery($query)->loadResult() > 0;
    }
};
