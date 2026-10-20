<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace FG\Component\Fgloginasuser\Administrator\Service;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Records the end of an impersonation in the audit log and the User Actions Log.
 *
 * Never throws: ending an impersonation (leaving the foreign identity) must not depend on the audit
 * storage being available.
 */
final class EndRecorder
{
    /**
     * @param   array  $marker  The impersonation marker of the session being ended.
     * @param   string $note    Optional reason stored with the log row.
     */
    public static function record(CMSApplication $app, array $marker, string $note = ''): void
    {
        try {
            (new LogStore(Factory::getContainer()->get(DatabaseInterface::class)))
                ->end((int) ($marker['log_id'] ?? 0), $note);
        } catch (\Throwable $e) {
            Log::add('FG Login as User: could not close the log row: ' . $e->getMessage(), Log::WARNING, 'fgloginasuser');
        }

        ActionLogger::log(
            $app,
            ActionLogger::ENDED,
            (int) ($marker['actor_id'] ?? 0),
            (string) ($marker['actor_username'] ?? ''),
            (int) ($marker['target_id'] ?? 0),
            (string) ($marker['target_username'] ?? ''),
            $note
        );
    }
}
