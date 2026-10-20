<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace FG\Component\Fgloginasuser\Administrator\Model;

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
     * Delete every log row.
     */
    public function purgeAll(): bool
    {
        $db = $this->getDatabase();

        try {
            $db->setQuery($db->createQuery()->delete($db->quoteName('#__fgloginasuser_logs')))->execute();
        } catch (\RuntimeException $e) {
            $this->setError($e->getMessage());

            return false;
        }

        return true;
    }
}
