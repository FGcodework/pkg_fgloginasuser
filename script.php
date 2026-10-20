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

return new class () implements InstallerScriptInterface {
    /** @var array<string, bool> Companion plugins that were already installed before this run. */
    private array $existed = [];

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

        if (version_compare(JVERSION, '6.0.0', '<')) {
            Factory::getApplication()->enqueueMessage('FG Login as User requires Joomla 6 or newer.', 'error');

            return false;
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);

        foreach (['system', 'actionlog'] as $folder) {
            $this->existed[$folder] = $this->pluginExists($db, $folder);
        }

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
