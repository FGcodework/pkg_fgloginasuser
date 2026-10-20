<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace FG\Component\Fgloginasuser\Administrator\Model;

use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Users that can be impersonated (plain read of #__users, with group titles).
 */
class UsersModel extends ListModel
{
    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = [
                'id', 'a.id',
                'name', 'a.name',
                'username', 'a.username',
                'email', 'a.email',
                'lastvisitDate', 'a.lastvisitDate',
                'registerDate', 'a.registerDate',
            ];
        }

        parent::__construct($config, $factory);
    }

    protected function populateState($ordering = 'a.name', $direction = 'asc')
    {
        parent::populateState($ordering, $direction);
    }

    protected function getStoreId($id = '')
    {
        $id .= ':' . $this->getState('filter.search');
        $id .= ':' . $this->getState('filter.group_id');
        $id .= ':' . $this->getState('filter.state');

        return parent::getStoreId($id);
    }

    protected function getListQuery()
    {
        $db    = $this->getDatabase();
        $query = $db->createQuery();

        $query->select(
            $db->quoteName(
                ['a.id', 'a.name', 'a.username', 'a.email', 'a.block', 'a.activation', 'a.lastvisitDate', 'a.registerDate']
            )
        )
            ->from($db->quoteName('#__users', 'a'));

        $search = trim((string) $this->getState('filter.search'));

        if ($search !== '') {
            if (stripos($search, 'id:') === 0) {
                $id = (int) substr($search, 3);
                $query->where($db->quoteName('a.id') . ' = :uid')
                    ->bind(':uid', $id, ParameterType::INTEGER);
            } else {
                $like = '%' . str_replace(' ', '%', $search) . '%';
                $query->where(
                    '(' . $db->quoteName('a.name') . ' LIKE :s1 OR ' . $db->quoteName('a.username') . ' LIKE :s2'
                    . ' OR ' . $db->quoteName('a.email') . ' LIKE :s3)'
                )
                    ->bind([':s1', ':s2', ':s3'], $like);
            }
        }

        $groupId = (int) $this->getState('filter.group_id');

        if ($groupId > 0) {
            $query->join('INNER', $db->quoteName('#__user_usergroup_map', 'm') . ' ON m.user_id = a.id')
                ->where($db->quoteName('m.group_id') . ' = :gid')
                ->bind(':gid', $groupId, ParameterType::INTEGER);
        }

        $state = $this->getState('filter.state');

        if ($state !== null && $state !== '') {
            $block = (int) $state;
            $query->where($db->quoteName('a.block') . ' = :block')
                ->bind(':block', $block, ParameterType::INTEGER);
        }

        $query->order(
            $db->escape($this->getState('list.ordering', 'a.name')) . ' ' . $db->escape($this->getState('list.direction', 'ASC'))
        );

        return $query;
    }

    /**
     * Attach a comma separated list of group titles to every item.
     */
    public function getItems()
    {
        $items = parent::getItems();

        if (!$items) {
            return $items;
        }

        $db  = $this->getDatabase();
        $ids = array_map(static fn ($item) => (int) $item->id, $items);

        $query = $db->createQuery()
            ->select([$db->quoteName('m.user_id'), $db->quoteName('g.title')])
            ->from($db->quoteName('#__user_usergroup_map', 'm'))
            ->join('INNER', $db->quoteName('#__usergroups', 'g') . ' ON g.id = m.group_id')
            ->whereIn($db->quoteName('m.user_id'), $ids)
            ->order($db->quoteName('g.lft'));

        $titles = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $titles[(int) $row->user_id][] = $row->title;
        }

        foreach ($items as $item) {
            $item->group_titles = implode(', ', $titles[(int) $item->id] ?? []);
        }

        return $items;
    }
}
