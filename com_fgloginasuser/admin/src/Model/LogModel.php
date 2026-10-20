<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace FG\Component\Fgloginasuser\Administrator\Model;

use FG\Component\Fgloginasuser\Administrator\Service\LogStore;
use Joomla\CMS\MVC\Model\AdminModel;

\defined('_JEXEC') or die;

/**
 * Single-row model; used only for deleting log rows (there is no edit form).
 */
class LogModel extends AdminModel
{
    public function getForm($data = [], $loadData = true)
    {
        return false;
    }

    /**
     * Delete the finished history. Unexpired tokens and recent active impersonations are kept, so the
     * clean-up cannot cancel a login that is just about to happen or lose the record of a running one.
     */
    public function purgeAll(): bool
    {
        try {
            return (new LogStore($this->getDatabase()))->purgeFinished();
        } catch (\RuntimeException $e) {
            $this->setError($e->getMessage());

            return false;
        }
    }
}
