<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace FG\Component\Fgloginasuser\Administrator\Controller;

use FG\Component\Fgloginasuser\Administrator\Service\ActionLogger;
use FG\Component\Fgloginasuser\Administrator\Service\Reason;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\AdminController;
use Joomla\CMS\Router\Route;

\defined('_JEXEC') or die;

class LogsController extends AdminController
{
    protected $text_prefix = 'COM_FGLOGINASUSER_LOGS';

    public function getModel($name = 'Log', $prefix = 'Administrator', $config = ['ignore_request' => true])
    {
        return parent::getModel($name, $prefix, $config);
    }

    /**
     * Delete every log row.
     */
    public function purge(): void
    {
        $this->checkToken();

        $this->setRedirect(Route::_('index.php?option=com_fgloginasuser&view=logs', false));

        if (!$this->app->getIdentity()->authorise('core.delete', 'com_fgloginasuser')) {
            $this->setMessage(Text::_('JERROR_CORE_DELETE_NOT_PERMITTED'), 'error');

            return;
        }

        if ($this->getModel()->purgeAll()) {
            $this->setMessage(Text::_('COM_FGLOGINASUSER_LOGS_PURGED'));
        } else {
            $this->setMessage(Text::_('COM_FGLOGINASUSER_LOGS_PURGE_FAILED'), 'error');
        }
    }

    public function exportCsv(): void
    {
        $this->export('csv', ',');
    }

    public function exportExcel(): void
    {
        $this->export('csv-excel', ';');
    }

    public function exportJson(): void
    {
        $this->export('json', '');
    }

    /**
     * Streams the rows matching the current filters. The token hash is never part of the export.
     * The Dispatcher already requires the "fgloginasuser.logs" right for every logs.* task.
     */
    private function export(string $format, string $delimiter): void
    {
        $this->checkToken();

        $model = $this->getModel('Logs', 'Administrator', ['ignore_request' => false]);
        $app   = $this->app;
        $app->getLanguage()->load('com_fgloginasuser', JPATH_ADMINISTRATOR);

        $columns = ['id', 'created_utc', 'actor_id', 'actor_username', 'target_id', 'target_username', 'client', 'status',
            'ip', 'reason', 'note', 'note_key', 'started_utc', 'ended_utc'];

        $map = static function (object $row): array {
            $note = (string) $row->note;

            return [
                (int) $row->id, (string) $row->created, (int) $row->actor_id, (string) $row->actor_username,
                (int) $row->target_id, (string) $row->target_username, (string) $row->client, (string) $row->status,
                (string) $row->ip, (string) $row->reason, Reason::translate($note), str_starts_with($note, 'COM_') ? $note : '',
                (string) ($row->started ?? ''), (string) ($row->ended ?? ''),
            ];
        };

        $name = 'fgloginasuser-logs-' . gmdate('Ymd-His') . ($format === 'json' ? '.json' : '.csv');

        $app->clearHeaders();
        $app->setHeader('Content-Type', $format === 'json' ? 'application/json; charset=utf-8' : 'text/csv; charset=utf-8', true);
        $app->setHeader('Content-Disposition', 'attachment; filename="' . $name . '"', true);
        $app->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate', true);
        $app->setHeader('X-Content-Type-Options', 'nosniff', true);
        $app->sendHeaders();

        $out   = fopen('php://output', 'w');
        $count = 0;

        if ($format === 'json') {
            fwrite($out, '[');
        } else {
            fwrite($out, "\xEF\xBB\xBF");
            $this->putCsv($out, $columns, $delimiter);
        }

        foreach ($model->export() as $row) {
            $values = $map($row);

            if ($format === 'json') {
                fwrite($out, ($count ? ',' : '') . "\n" . json_encode(array_combine($columns, $values), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
            } else {
                $this->putCsv($out, $values, $delimiter);
            }

            $count++;
        }

        if ($format === 'json') {
            fwrite($out, "\n]\n");
        }

        fclose($out);

        $user = $app->getIdentity();
        ActionLogger::log($app, ActionLogger::EXPORTED, (int) $user->id, (string) $user->username, 0, '', '', ['count' => $count, 'format' => $format]);

        $app->close();
    }

    /**
     * Cells that a spreadsheet would read as a formula get a leading apostrophe (CSV injection).
     */
    private function putCsv($handle, array $cells, string $delimiter): void
    {
        $cells = array_map(static function ($cell) {
            return \is_string($cell) && $cell !== '' && strpbrk($cell[0], "=+-@\t\r") !== false ? "'" . $cell : $cell;
        }, $cells);

        fputcsv($handle, $cells, $delimiter, '"', '\\', "\r\n");
    }
}
