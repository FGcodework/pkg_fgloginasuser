<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use FG\Plugin\System\Fgloginasuser\Extension\Fgloginasuser;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $plugin = new Fgloginasuser(
                    $container->get(DispatcherInterface::class),
                    (array) PluginHelper::getPlugin('system', 'fgloginasuser')
                );
                $plugin->setApplication(\Joomla\CMS\Factory::getApplication());

                return $plugin;
            }
        );
    }
};
