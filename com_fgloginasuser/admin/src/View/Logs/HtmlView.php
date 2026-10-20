<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace FG\Component\Fgloginasuser\Administrator\View\Logs;

use FG\Component\Fgloginasuser\Administrator\Service\LogStore;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\GenericDataException;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

\defined('_JEXEC') or die;

class HtmlView extends BaseHtmlView
{
    protected $items;
    protected $pagination;
    protected $state;
    public $filterForm;
    public $activeFilters;

    public function display($tpl = null)
    {
        $model = $this->getModel();

        // Retention also runs when the log is looked at, not only when somebody starts a new login.
        (new LogStore(Factory::getContainer()->get(DatabaseInterface::class)))->purgeOlderThan((int) ComponentHelper::getParams('com_fgloginasuser')->get('log_retention_days', 365));

        $this->items         = $model->getItems();
        $this->pagination    = $model->getPagination();
        $this->state         = $model->getState();
        $this->filterForm    = $model->getFilterForm();
        $this->activeFilters = $model->getActiveFilters();

        if (\count($errors = $model->getErrors())) {
            throw new GenericDataException(implode("\n", $errors), 500);
        }

        $this->filterForm->addControlField('task')->addControlField('boxchecked', '0');

        $this->addToolbar();

        parent::display($tpl);
    }

    protected function addToolbar(): void
    {
        $user    = $this->getCurrentUser();
        $toolbar = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::_('COM_FGLOGINASUSER_LOGS_TITLE'), 'list');

        if ($user->authorise('core.delete', 'com_fgloginasuser')) {
            $toolbar->delete('logs.delete', 'JTOOLBAR_DELETE')
                ->message('JGLOBAL_CONFIRM_DELETE')
                ->listCheck(true);

            $toolbar->confirmButton('purge', 'COM_FGLOGINASUSER_LOGS_PURGE', 'logs.purge')
                ->icon('icon-trash')
                ->message('COM_FGLOGINASUSER_LOGS_PURGE_CONFIRM')
                ->listCheck(false);
        }

        if ($user->authorise('core.admin', 'com_fgloginasuser') || $user->authorise('core.options', 'com_fgloginasuser')) {
            ToolbarHelper::preferences('com_fgloginasuser');
        }
    }
}
