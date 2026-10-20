<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use FG\Component\Fgloginasuser\Administrator\Service\ButtonRenderer;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

/** @var \FG\Component\Fgloginasuser\Administrator\View\Users\HtmlView $this */

$wa = $this->getDocument()->getWebAssetManager();
$wa->registerAndUseScript('com_fgloginasuser.users', 'com_fgloginasuser/users.js', [], ['defer' => true]);
$wa->registerAndUseStyle('com_fgloginasuser.admin', 'com_fgloginasuser/admin.css');

$customCss = ButtonRenderer::customCss($this->params);
$showEmail = \FG\Component\Fgloginasuser\Administrator\Model\UsersModel::canSeeEmail();

if ($customCss !== '') {
    $wa->addInlineStyle($customCss);
}

$listOrder = $this->escape($this->state->get('list.ordering'));
$listDirn  = $this->escape($this->state->get('list.direction'));
$me        = (int) $this->getCurrentUser()->id;
?>
<form action="<?php echo Route::_('index.php?option=com_fgloginasuser&view=users'); ?>" method="post" name="adminForm" id="adminForm">
    <div id="j-main-container" class="j-main-container">
        <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>
        <?php if (empty($this->items)) : ?>
            <div class="alert alert-info">
                <span class="icon-info-circle" aria-hidden="true"></span><span class="visually-hidden"><?php echo Text::_('INFO'); ?></span>
                <?php echo Text::_('JGLOBAL_NO_MATCHING_RESULTS'); ?>
            </div>
        <?php else : ?>
            <table class="table" id="fgUserList">
                <caption class="visually-hidden"><?php echo Text::_('COM_FGLOGINASUSER_USERS_TITLE'); ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'COM_FGLOGINASUSER_COL_NAME', 'a.name', $listDirn, $listOrder); ?></th>
                        <th scope="col" class="d-none d-md-table-cell"><?php echo HTMLHelper::_('searchtools.sort', 'COM_FGLOGINASUSER_COL_USERNAME', 'a.username', $listDirn, $listOrder); ?></th>
                        <th scope="col" class="d-none d-lg-table-cell"><?php echo Text::_('COM_FGLOGINASUSER_COL_GROUPS'); ?></th>
                        <th scope="col" class="d-none d-lg-table-cell"><?php echo HTMLHelper::_('searchtools.sort', 'COM_FGLOGINASUSER_COL_LASTVISIT', 'a.lastvisitDate', $listDirn, $listOrder); ?></th>
                        <th scope="col" class="text-end"><?php echo Text::_('COM_FGLOGINASUSER_COL_ACTIONS'); ?></th>
                        <th scope="col" class="w-1 d-none d-md-table-cell"><?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($this->items as $i => $item) : ?>
                    <tr class="row<?php echo $i % 2; ?>">
                        <th scope="row">
                            <?php echo $this->escape($item->name); ?>
                            <?php if ((int) $item->block === 1) : ?>
                                <span class="badge bg-secondary"><?php echo Text::_('COM_FGLOGINASUSER_STATE_BLOCKED'); ?></span>
                            <?php endif; ?>
                            <?php if ((int) $item->id === $me) : ?>
                                <span class="badge bg-info"><?php echo Text::_('COM_FGLOGINASUSER_YOU'); ?></span>
                            <?php endif; ?>
                            <?php if ($showEmail) : ?>
                                <div class="small text-muted"><?php echo $this->escape($item->email); ?></div>
                            <?php endif; ?>
                        </th>
                        <td class="d-none d-md-table-cell"><?php echo $this->escape($item->username); ?></td>
                        <td class="d-none d-lg-table-cell small"><?php echo $this->escape($item->group_titles ?? ''); ?></td>
                        <td class="d-none d-lg-table-cell small">
                            <?php echo $item->lastvisitDate ? HTMLHelper::_('date', $item->lastvisitDate, Text::_('DATE_FORMAT_LC6')) : Text::_('JNEVER'); ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <?php echo ButtonRenderer::buttons($this->params, (int) $item->id, $item->username, $item->error_site, $item->error_admin, true); ?>
                        </td>
                        <td class="d-none d-md-table-cell"><?php echo (int) $item->id; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php echo $this->pagination->getListFooter(); ?>
        <?php endif; ?>

        <input type="hidden" name="task" value="">
        <input type="hidden" name="boxchecked" value="0">
        <?php echo HTMLHelper::_('form.token'); ?>
    </div>
</form>

<?php /* Separate form (never nested in adminForm): pressing Enter in the search box must not impersonate anybody. */ ?>
<?php echo ButtonRenderer::form(); ?>
