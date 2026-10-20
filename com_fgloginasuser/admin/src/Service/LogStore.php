<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace FG\Component\Fgloginasuser\Administrator\Service;

use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Persistence for the impersonation log. A row in status "pending" doubles as the one-time
 * login token for the frontend (only its SHA-256 hash is stored).
 */
final class LogStore
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE  = 'active';
    public const STATUS_ENDED   = 'ended';
    public const STATUS_DENIED  = 'denied';

    private const TABLE = '#__fgloginasuser_logs';

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    public function create(
        User $actor,
        ?User $target,
        string $client,
        string $status,
        string $ip,
        string $note = '',
        ?string $tokenHash = null,
        ?string $tokenExpires = null
    ): int {
        $now = Factory::getDate()->toSql();

        $row = (object) [
            'actor_id'        => (int) $actor->id,
            'actor_username'  => (string) $actor->username,
            'target_id'       => $target ? (int) $target->id : 0,
            'target_username' => $target ? (string) $target->username : '',
            'client'          => $client,
            'status'          => $status,
            'ip'              => substr($ip, 0, 45),
            'note'            => mb_substr($note, 0, 255),
            'token_hash'      => $tokenHash,
            'token_expires'   => $tokenExpires,
            'created'         => $now,
            'started'         => $status === self::STATUS_ACTIVE ? $now : null,
        ];

        $this->db->insertObject(self::TABLE, $row, 'id');

        return (int) $row->id;
    }

    /**
     * Find a pending, unexpired token row.
     */
    public function findPending(string $tokenHash): ?object
    {
        $now     = Factory::getDate()->toSql();
        $pending = self::STATUS_PENDING;
        $query   = $this->db->createQuery()
            ->select('*')
            ->from($this->db->quoteName(self::TABLE))
            ->where($this->db->quoteName('token_hash') . ' = :hash')
            ->where($this->db->quoteName('status') . ' = :status')
            ->where($this->db->quoteName('token_expires') . ' >= :now')
            ->bind(':hash', $tokenHash)
            ->bind(':status', $pending)
            ->bind(':now', $now);

        return $this->db->setQuery($query)->loadObject() ?: null;
    }

    /**
     * Atomically consume a token: succeeds for exactly one caller, even under concurrency.
     */
    public function claim(int $id, string $tokenHash): bool
    {
        $now     = Factory::getDate()->toSql();
        $pending = self::STATUS_PENDING;
        $active  = self::STATUS_ACTIVE;

        $query = $this->db->createQuery()
            ->update($this->db->quoteName(self::TABLE))
            ->set($this->db->quoteName('status') . ' = :active')
            ->set($this->db->quoteName('started') . ' = :started')
            ->set($this->db->quoteName('token_hash') . ' = NULL')
            ->where($this->db->quoteName('id') . ' = :id')
            ->where($this->db->quoteName('token_hash') . ' = :hash')
            ->where($this->db->quoteName('status') . ' = :pending')
            ->where($this->db->quoteName('token_expires') . ' >= :now')
            ->bind(':active', $active)
            ->bind(':started', $now)
            ->bind(':id', $id, ParameterType::INTEGER)
            ->bind(':hash', $tokenHash)
            ->bind(':pending', $pending)
            ->bind(':now', $now);

        $this->db->setQuery($query)->execute();

        return $this->db->getAffectedRows() === 1;
    }

    /**
     * Mark an attempt as denied, but only while the row is still in the expected state: a refusal must
     * never overwrite the outcome of a concurrent request (e.g. a successful claim).
     *
     * @param   string  $from  STATUS_PENDING before the token was claimed, STATUS_ACTIVE after the claim.
     */
    public function markDenied(int $id, string $note, string $from = self::STATUS_PENDING): bool
    {
        $denied = self::STATUS_DENIED;
        $note   = mb_substr($note, 0, 255);

        $query = $this->db->createQuery()
            ->update($this->db->quoteName(self::TABLE))
            ->set($this->db->quoteName('status') . ' = :denied')
            ->set($this->db->quoteName('note') . ' = :note')
            ->set($this->db->quoteName('token_hash') . ' = NULL')
            ->where($this->db->quoteName('id') . ' = :id')
            ->where($this->db->quoteName('status') . ' = :from')
            ->bind(':denied', $denied)
            ->bind(':note', $note)
            ->bind(':id', $id, ParameterType::INTEGER)
            ->bind(':from', $from);

        $this->db->setQuery($query)->execute();

        return $this->db->getAffectedRows() === 1;
    }

    public function end(int $id, string $note = ''): void
    {
        $now    = Factory::getDate()->toSql();
        $ended  = self::STATUS_ENDED;
        $active = self::STATUS_ACTIVE;

        $query = $this->db->createQuery()
            ->update($this->db->quoteName(self::TABLE))
            ->set($this->db->quoteName('status') . ' = :ended')
            ->set($this->db->quoteName('ended') . ' = :now')
            ->where($this->db->quoteName('id') . ' = :id')
            ->where($this->db->quoteName('status') . ' = :active')
            ->bind(':ended', $ended)
            ->bind(':now', $now)
            ->bind(':id', $id, ParameterType::INTEGER)
            ->bind(':active', $active);

        if ($note !== '') {
            $note = mb_substr($note, 0, 255);
            $query->set($this->db->quoteName('note') . ' = :note')->bind(':note', $note);
        }

        $this->db->setQuery($query)->execute();
    }

    /**
     * Delete finished history only: ended and denied rows, expired unused tokens and "active" rows
     * that have certainly outlived their session (older than a day). Unexpired tokens and recent
     * active impersonations are kept.
     */
    public function purgeFinished(): bool
    {
        $now     = Factory::getDate()->toSql();
        $dayAgo  = Factory::getDate('-1 day')->toSql();
        $ended   = self::STATUS_ENDED;
        $denied  = self::STATUS_DENIED;
        $pending = self::STATUS_PENDING;
        $active  = self::STATUS_ACTIVE;
        $q       = fn (string $c): string => $this->db->quoteName($c);

        $query = $this->db->createQuery()
            ->delete($q(self::TABLE))
            ->where(
                '(' . $q('status') . ' IN (:ended, :denied)'
                . ' OR (' . $q('status') . ' = :pending AND ' . $q('token_expires') . ' < :now)'
                . ' OR (' . $q('status') . ' = :active AND ' . $q('created') . ' < :dayago))'
            )
            ->bind(':ended', $ended)
            ->bind(':denied', $denied)
            ->bind(':pending', $pending)
            ->bind(':now', $now)
            ->bind(':active', $active)
            ->bind(':dayago', $dayAgo);

        $this->db->setQuery($query)->execute();

        return true;
    }

    /**
     * Delete rows older than $days (0 = keep forever).
     */
    public function purgeOlderThan(int $days): void
    {
        if ($days <= 0) {
            return;
        }

        $cutoff = Factory::getDate('-' . $days . ' days')->toSql();

        $query = $this->db->createQuery()
            ->delete($this->db->quoteName(self::TABLE))
            ->where($this->db->quoteName('created') . ' < :cutoff')
            ->bind(':cutoff', $cutoff);

        $this->db->setQuery($query)->execute();
    }
}
