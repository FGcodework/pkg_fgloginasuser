<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace FG\Component\Fgloginasuser\Administrator\Model;

use Joomla\CMS\Factory;
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
        // The calendar field fails on anything that is not a date, and the filter is kept in the session: a bad
        // value in the URL must not leave the screen broken. Dates that are not YYYY-MM-DD are dropped first.
        $app     = Factory::getApplication();
        $request = $app->getInput()->get('filter', null, 'array');

        if (\is_array($request)) {
            foreach (['date_from', 'date_to'] as $name) {
                if (isset($request[$name]) && $this->dayBoundary((string) $request[$name], '00:00:00') === null) {
                    $request[$name] = '';
                }
            }

            $app->getInput()->set('filter', $request);
            $app->getInput()->request->set('filter', $request);
        }

        $stored = $app->getUserState($this->context . '.filter');

        if (\is_array($stored)) {
            foreach (['date_from', 'date_to'] as $name) {
                if (!empty($stored[$name]) && $this->dayBoundary((string) $stored[$name], '00:00:00') === null) {
                    $stored[$name] = '';
                }
            }

            $app->setUserState($this->context . '.filter', $stored);
        }

        parent::populateState($ordering, $direction);
    }

    protected function getStoreId($id = '')
    {
        $id .= ':' . $this->getState('filter.search');
        $id .= ':' . $this->getState('filter.client');
        $id .= ':' . $this->getState('filter.status');
        $id .= ':' . $this->getState('filter.reason');
        $id .= ':' . $this->getState('filter.actor');
        $id .= ':' . $this->getState('filter.target');
        $id .= ':' . $this->getState('filter.date_from');
        $id .= ':' . $this->getState('filter.date_to');

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
            $searchLike = '%' . $search . '%';
            $query->where(
                '(' . $db->quoteName('a.actor_username') . ' LIKE :s1 OR ' . $db->quoteName('a.target_username') . ' LIKE :s2'
                . ' OR ' . $db->quoteName('a.ip') . ' LIKE :s3 OR ' . $db->quoteName('a.note') . ' LIKE :s4 OR ' . $db->quoteName('a.reason') . ' LIKE :s5)'
            )
                ->bind([':s1', ':s2', ':s3', ':s4', ':s5'], $searchLike);
        }

        $client = (string) $this->getState('filter.client');

        if (\in_array($client, ['site', 'administrator'], true)) {
            $query->where($db->quoteName('a.client') . ' = :client')->bind(':client', $client);
        }

        $status = (string) $this->getState('filter.status');

        if (\in_array($status, ['pending', 'active', 'ended', 'denied'], true)) {
            $query->where($db->quoteName('a.status') . ' = :status')->bind(':status', $status);
        }

        $reason = (string) $this->getState('filter.reason');

        if ($reason === '__none__') {
            $query->where($db->quoteName('a.reason') . ' = ' . $db->quote(''));
        } elseif ($reason !== '') {
            // A preset, with or without a note ("Label" or "Label: note").
            $exact = $reason;
            $reasonLike = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $reason) . ': %';
            $query->where('(' . $db->quoteName('a.reason') . ' = :rexact OR ' . $db->quoteName('a.reason') . ' LIKE :rlike)')
                ->bind(':rexact', $exact)
                ->bind(':rlike', $reasonLike);
        }

        // bind() keeps a reference to the variable, so every value needs its own variable.
        $likes = [];

        foreach (['actor' => 'a.actor_username', 'target' => 'a.target_username'] as $name => $column) {
            $value = trim((string) $this->getState('filter.' . $name));

            if ($value !== '') {
                $likes[$name] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value) . '%';
                $query->where($db->quoteName($column) . ' LIKE :f' . $name)->bind(':f' . $name, $likes[$name]);
            }
        }

        // Whole days in the time zone of the person looking at the log, compared against the UTC "created".
        if (($from = $this->dayBoundary((string) $this->getState('filter.date_from'), '00:00:00')) !== null) {
            $query->where($db->quoteName('a.created') . ' >= :dfrom')->bind(':dfrom', $from);
        }

        if (($to = $this->dayBoundary((string) $this->getState('filter.date_to'), '23:59:59')) !== null) {
            $query->where($db->quoteName('a.created') . ' <= :dto')->bind(':dto', $to);
        }

        $query->order(
            $db->escape($this->getState('list.ordering', 'a.id')) . ' ' . $db->escape($this->getState('list.direction', 'DESC'))
        );

        return $query;
    }

    /**
     * "YYYY-MM-DD" in the user's time zone -> UTC database datetime; null for anything else.
     */
    private function dayBoundary(string $day, string $time): ?string
    {
        $day = trim($day);

        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        try {
            $user = Factory::getApplication()->getIdentity();

            return Factory::getDate($m[1] . '-' . $m[2] . '-' . $m[3] . ' ' . $time, $user ? $user->getTimezone() : null)->toSql();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Streams every row matching the current filters, newest first, in keyset batches (no OFFSET, no big result set).
     * Never includes the token hash.
     *
     * @return  \Generator<int, object>
     */
    public function export(int $batch = 1000): \Generator
    {
        $db   = $this->getDatabase();
        $last = null;

        while (true) {
            $query = clone $this->getListQuery();
            $query->clear('order');

            if ($last !== null) {
                $query->where($db->quoteName('a.id') . ' < :lastid')->bind(':lastid', $last, \Joomla\Database\ParameterType::INTEGER);
            }

            $query->order($db->quoteName('a.id') . ' DESC');
            $db->setQuery($query, 0, $batch);
            $rows = $db->loadObjectList();

            if (!$rows) {
                return;
            }

            foreach ($rows as $row) {
                yield $row;
                $last = (int) $row->id;
            }

            if (\count($rows) < $batch) {
                return;
            }
        }
    }
}
