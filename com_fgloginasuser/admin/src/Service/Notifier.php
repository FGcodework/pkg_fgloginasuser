<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace FG\Component\Fgloginasuser\Administrator\Service;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Mail\MailHelper;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * Optional e-mail notification. Uses Joomla's configured mailer (so SMTP works on hosts
 * without PHP mail()). A mail failure is logged and never blocks the impersonation.
 */
final class Notifier
{
    public static function send(
        CMSApplication $app,
        Registry $params,
        string $actor,
        string $target,
        string $client,
        string $ip
    ): void {
        if (!(int) $params->get('notify_enabled', 0)) {
            return;
        }

        try {
            $to = trim((string) $params->get('notify_email', ''));

            if ($to === '') {
                $to = (string) $app->get('mailfrom');
            }

            if (!MailHelper::isEmailAddress($to)) {
                return;
            }

            // The strings live in the administrator language file, also when called from the site.
            $app->getLanguage()->load('com_fgloginasuser', JPATH_ADMINISTRATOR);

            $where = $client === AccessPolicy::CLIENT_SITE
                ? Text::_('COM_FGLOGINASUSER_CLIENT_SITE')
                : Text::_('COM_FGLOGINASUSER_CLIENT_ADMIN');

            $mailer = Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer();
            $mailer->addRecipient($to);
            $mailer->setSubject(Text::sprintf('COM_FGLOGINASUSER_MAIL_SUBJECT', $app->get('sitename')));
            $mailer->isHtml(false);
            $mailer->setBody(
                Text::sprintf(
                    'COM_FGLOGINASUSER_MAIL_BODY',
                    $actor,
                    $target,
                    $where,
                    $ip,
                    Factory::getDate()->format('Y-m-d H:i:s') . ' UTC'
                )
            );
            $mailer->Send();
        } catch (\Throwable $e) {
            Log::add('Notification mail failed: ' . $e->getMessage(), Log::WARNING, 'com_fgloginasuser');
        }
    }
}
