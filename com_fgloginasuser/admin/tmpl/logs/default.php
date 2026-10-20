<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use FG\Component\Fgloginasuser\Administrator\Service\Reason;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

/** @var \FG\Component\Fgloginasuser\Administrator\View\Logs\HtmlView $this */

$wa = $this->getDocument()->getWebAssetManager();
$wa->useScript('table.columns')->useScript('multiselect');

$listOrder = $this->escape($this->state->get('list.ordering'));
$listDirn  = $this->escape($this->state->get('list.direction'));
$now       = Factory::getDate()->toSql();
$dateFmt   = Text::_('DATE_FORMAT_LC6');

$badge = [
    'pending' => 'bg-secondary',
    'active'  => 'bg-success',
    'ended'   => 'bg-info',
    'denied'  => 'bg-danger',
    'expired' => 'bg-light text-dark',
];
?>
<form action="<?php echo Route::_('index.php?option=com_fgloginasuser&view=logs'); ?>" method="post" name="adminForm" id="adminForm">
    <div id="j-main-container" class="j-main-container">
        <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>
        <?php if (empty($this->items)) : ?>
            <div class="alert alert-info">
                <span class="icon-info-circle" aria-hidden="true"></span><span class="visually-hidden"><?php echo Text::_('INFO'); ?></span>
                <?php echo Text::_('JGLOBAL_NO_MATCHING_RESULTS'); ?>
            </div>
        <?php else : ?>
            <table class="table">
                <caption class="visually-hidden"><?php echo Text::_('COM_FGLOGINASUSER_LOGS_TITLE'); ?></caption>
                <thead>
                    <tr>
                        <td class="w-1 text-center"><?php echo HTMLHelper::_('grid.checkall'); ?></td>
                        <th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'COM_FGLOGINASUSER_COL_DATE', 'a.created', $listDirn, $listOrder); ?></th>
                        <th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'COM_FGLOGINASUSER_COL_ACTOR', 'a.actor_username', $listDirn, $listOrder); ?></th>
                        <th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'COM_FGLOGINASUSER_COL_TARGET', 'a.target_username', $listDirn, $listOrder); ?></th>
                        <th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'COM_FGLOGINASUSER_COL_WHERE', 'a.client', $listDirn, $listOrder); ?></th>
                        <th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'JSTATUS', 'a.status', $listDirn, $listOrder); ?></th>
                        <th scope="col" class="d-none d-lg-table-cell"><?php echo HTMLHelper::_('searchtools.sort', 'COM_FGLOGINASUSER_COL_IP', 'a.ip', $listDirn, $listOrder); ?></th>
                        <th scope="col" class="d-none d-lg-table-cell"><?php echo Text::_('COM_FGLOGINASUSER_COL_ENDED'); ?></th>
                        <th scope="col" class="w-1 d-none d-md-table-cell"><?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($this->items as $i => $item) :
                    $status = $item->status === 'pending' && $item->token_expires && $item->token_expires < $now ? 'expired' : $item->status;
                    $note   = (string) $item->note;
                    ?>
                    <tr class="row<?php echo $i % 2; ?>">
                        <td class="text-center"><?php echo HTMLHelper::_('grid.id', $i, $item->id); ?></td>
                        <td class="small text-nowrap"><?php echo HTMLHelper::_('date', $item->created, $dateFmt); ?></td>
                        <td><?php echo $this->escape($item->actor_username); ?></td>
                        <td><?php echo $item->target_username !== '' ? $this->escape($item->target_username) : '–'; ?></td>
                        <td><?php echo Text::_($item->client === 'site' ? 'COM_FGLOGINASUSER_CLIENT_SITE' : 'COM_FGLOGINASUSER_CLIENT_ADMIN'); ?></td>
                        <td>
                            <span class="badge <?php echo $badge[$status] ?? 'bg-secondary'; ?>"><?php echo Text::_('COM_FGLOGINASUSER_STATUS_' . strtoupper($status)); ?></span>
                            <?php if ($note !== '') : ?>
                                <div class="small text-muted"><?php echo $this->escape(Reason::translate($note)); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="d-none d-lg-table-cell small"><?php echo $this->escape($item->ip); ?></td>
                        <td class="d-none d-lg-table-cell small text-nowrap"><?php echo $item->ended ? HTMLHelper::_('date', $item->ended, $dateFmt) : '–'; ?></td>
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
