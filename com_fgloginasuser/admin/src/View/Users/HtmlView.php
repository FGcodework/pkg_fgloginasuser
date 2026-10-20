<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace FG\Component\Fgloginasuser\Administrator\View\Users;

use FG\Component\Fgloginasuser\Administrator\Service\Environment;
use FG\Component\Fgloginasuser\Administrator\Service\AccessPolicy;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\GenericDataException;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\User\UserFactoryInterface;

\defined('_JEXEC') or die;

class HtmlView extends BaseHtmlView
{
    protected $items;
    protected $pagination;
    protected $state;
    public $filterForm;
    public $activeFilters;

    /** @var \Joomla\Registry\Registry */
    protected $params;

    public function display($tpl = null)
    {
        $model = $this->getModel();

        if (($warning = Environment::pluginWarning()) !== '') {
            Factory::getApplication()->enqueueMessage(Text::_($warning), 'warning');
        }

        $this->items         = $model->getItems();
        $this->pagination    = $model->getPagination();
        $this->state         = $model->getState();
        $this->filterForm    = $model->getFilterForm();
        $this->activeFilters = $model->getActiveFilters();
        $this->params        = ComponentHelper::getParams('com_fgloginasuser');

        if (\count($errors = $model->getErrors())) {
            throw new GenericDataException(implode("\n", $errors), 500);
        }

        $this->evaluateItems();

        $this->filterForm->addControlField('task')->addControlField('boxchecked', '0');

        $this->addToolbar();

        parent::display($tpl);
    }

    /**
     * Pre-compute per row whether (and why not) each client can be impersonated.
     */
    private function evaluateItems(): void
    {
        $actor   = $this->getCurrentUser();
        $policy  = new AccessPolicy($this->params);
        $factory = Factory::getContainer()->get(UserFactoryInterface::class);

        foreach ($this->items ?: [] as $item) {
            $target             = $factory->loadUserById((int) $item->id);
            $item->error_site   = $policy->evaluate($actor, $target, AccessPolicy::CLIENT_SITE);
            $item->error_admin  = $policy->evaluate($actor, $target, AccessPolicy::CLIENT_ADMIN);
        }
    }

    protected function addToolbar(): void
    {
        $user = $this->getCurrentUser();

        ToolbarHelper::title(Text::_('COM_FGLOGINASUSER_USERS_TITLE'), 'users');

        if ($user->authorise('core.admin', 'com_fgloginasuser') || $user->authorise('core.options', 'com_fgloginasuser')) {
            ToolbarHelper::preferences('com_fgloginasuser');
        }
    }
}
