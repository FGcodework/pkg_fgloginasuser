<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace FG\Component\Fgloginasuser\Administrator\Model;

use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ListModel;

\defined('_JEXEC') or die;

class LogsModel extends ListModel
{
    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = [
                'id', 'a.id',
                'created', 'a.created',
                'actor_username', 'a.actor_username',
                'target_username', 'a.target_username',
                'client', 'a.client',
                'status', 'a.status',
                'ip', 'a.ip',
            ];
        }

        parent::__construct($config, $factory);
    }

    protected function populateState($ordering = 'a.id', $direction = 'desc')
    {
        parent::populateState($ordering, $direction);
    }

    protected function getStoreId($id = '')
    {
        $id .= ':' . $this->getState('filter.search');
        $id .= ':' . $this->getState('filter.client');
        $id .= ':' . $this->getState('filter.status');

        return parent::getStoreId($id);
    }

    protected function getListQuery()
    {
        $db    = $this->getDatabase();
        $query = $db->createQuery();

        $query->select($db->quoteName(['a.id', 'a.actor_id', 'a.actor_username', 'a.target_id', 'a.target_username',
            'a.client', 'a.status', 'a.ip', 'a.note', 'a.reason', 'a.token_expires', 'a.created', 'a.started', 'a.ended']))
            ->from($db->quoteName('#__fgloginasuser_logs', 'a'));

        $search = trim((string) $this->getState('filter.search'));

        if ($search !== '') {
            $like = '%' . $search . '%';
            $query->where(
                '(' . $db->quoteName('a.actor_username') . ' LIKE :s1 OR ' . $db->quoteName('a.target_username') . ' LIKE :s2'
                . ' OR ' . $db->quoteName('a.ip') . ' LIKE :s3 OR ' . $db->quoteName('a.note') . ' LIKE :s4 OR ' . $db->quoteName('a.reason') . ' LIKE :s5)'
            )
                ->bind([':s1', ':s2', ':s3', ':s4', ':s5'], $like);
        }

        $client = (string) $this->getState('filter.client');

        if (\in_array($client, ['site', 'administrator'], true)) {
            $query->where($db->quoteName('a.client') . ' = :client')->bind(':client', $client);
        }

        $status = (string) $this->getState('filter.status');

        if (\in_array($status, ['pending', 'active', 'ended', 'denied'], true)) {
            $query->where($db->quoteName('a.status') . ' = :status')->bind(':status', $status);
        }

        $query->order(
            $db->escape($this->getState('list.ordering', 'a.id')) . ' ' . $db->escape($this->getState('list.direction', 'DESC'))
        );

        return $query;
    }
}
