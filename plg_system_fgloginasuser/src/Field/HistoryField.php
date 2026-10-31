<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace FG\Plugin\System\Fgloginasuser\Field;

use FG\Component\Fgloginasuser\Administrator\Service\Reason;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Read-only history of "login as" for the user being edited: the last logins as this user and the last logins
 * made by this user. Shown only to people who may view the logs. Holds no value, nothing is saved.
 */
final class HistoryField extends FormField
{
    protected $type = 'History';

    private const LIMIT = 10;

    protected function getLabel()
    {
        return '<span class="visually-hidden">' . htmlspecialchars(Text::_('PLG_SYSTEM_FGLOGINASUSER_HISTORY'), ENT_QUOTES, 'UTF-8') . '</span>';
    }

    protected function getInput()
    {
        $id = (int) ($this->element['userid'] ?? 0);

        if ($id <= 0) {
            return '';
        }

        try {
            Factory::getApplication()->getLanguage()->load('com_fgloginasuser', JPATH_ADMINISTRATOR);

            return '<div class="fg-las-history"><h3 class="h5">' . $this->e(Text::_('PLG_SYSTEM_FGLOGINASUSER_HISTORY')) . '</h3>'
                . $this->section($id, 'target_id', 'PLG_SYSTEM_FGLOGINASUSER_HISTORY_AS_TARGET', 'target')
                . $this->section($id, 'actor_id', 'PLG_SYSTEM_FGLOGINASUSER_HISTORY_AS_ACTOR', 'actor')
                . '</div>';
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }

    private function section(int $userId, string $column, string $titleKey, string $filter): string
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $limit = self::LIMIT;
        $query = $db->createQuery()
            ->select($db->quoteName(['a.id', 'a.created', 'a.actor_username', 'a.target_username', 'a.client', 'a.status', 'a.note', 'a.reason']))
            ->from($db->quoteName('#__fgloginasuser_logs', 'a'))
            ->where($db->quoteName('a.' . $column) . ' = :uid')
            ->bind(':uid', $userId, ParameterType::INTEGER)
            ->order($db->quoteName('a.id') . ' DESC');
        $rows = $db->setQuery($query, 0, $limit)->loadObjectList();

        $out = '<h4 class="h6 mt-3">' . $this->e(Text::_($titleKey)) . '</h4>';

        if (!$rows) {
            return $out . '<p class="text-muted">' . $this->e(Text::_('PLG_SYSTEM_FGLOGINASUSER_HISTORY_NONE')) . '</p>';
        }

        $out .= '<table class="table table-sm"><thead><tr><th>' . $this->e(Text::_('COM_FGLOGINASUSER_COL_DATE')) . '</th><th>'
            . $this->e(Text::_($filter === 'target' ? 'COM_FGLOGINASUSER_COL_ACTOR' : 'COM_FGLOGINASUSER_COL_TARGET')) . '</th><th>'
            . $this->e(Text::_('COM_FGLOGINASUSER_COL_WHERE')) . '</th><th>' . $this->e(Text::_('JSTATUS')) . '</th></tr></thead><tbody>';

        foreach ($rows as $row) {
            $other = $filter === 'target' ? $row->actor_username : $row->target_username;
            $out  .= '<tr><td>' . $this->e(HTMLHelper::_('date', $row->created, Text::_('DATE_FORMAT_LC6'))) . '</td><td>' . $this->e((string) $other)
                . '</td><td>' . $this->e(Text::_($row->client === 'administrator' ? 'COM_FGLOGINASUSER_CLIENT_ADMIN' : 'COM_FGLOGINASUSER_CLIENT_SITE'))
                . '</td><td>' . $this->e(Text::_('COM_FGLOGINASUSER_STATUS_' . strtoupper((string) $row->status)))
                . ($row->status === 'denied' && $row->note !== '' ? ' <small class="text-muted">(' . $this->e(Reason::translate((string) $row->note)) . ')</small>' : '')
                . '</td></tr>';
        }

        $username = $this->username($userId);
        $link     = Route::_('index.php?option=com_fgloginasuser&view=logs&filter[' . $filter . ']=' . rawurlencode($username), false);

        return $out . '</tbody></table><p><a href="' . $this->e($link) . '">' . $this->e(Text::_('PLG_SYSTEM_FGLOGINASUSER_HISTORY_ALL')) . '</a></p>';
    }

    private function username(int $userId): string
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->createQuery()->select($db->quoteName('username'))->from($db->quoteName('#__users'))
            ->where($db->quoteName('id') . ' = :id')->bind(':id', $userId, ParameterType::INTEGER);

        return (string) $db->setQuery($query)->loadResult();
    }
}
