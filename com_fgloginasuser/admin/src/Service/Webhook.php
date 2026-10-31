<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace FG\Component\Fgloginasuser\Administrator\Service;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Log\Log;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * Optional notification to one web address (off by default) when a login as another user starts, ends or is refused.
 *
 * - https only, no credentials in the address, redirects are not followed
 * - total timeout of 3 seconds; any failure is only written to the log file, it never changes the outcome of the login
 * - no token, no password and no session data are ever part of the message
 * - presets: generic JSON (optionally signed with HMAC-SHA256 in "X-FG-Signature"), Slack, Discord, Microsoft Teams
 */
final class Webhook
{
    public const TIMEOUT = 3;

    private const EVENTS = [
        ActionLogger::STARTED_SITE         => 'started',
        ActionLogger::STARTED_ADMIN        => 'started',
        ActionLogger::STARTED_SITE_REASON  => 'started',
        ActionLogger::STARTED_ADMIN_REASON => 'started',
        ActionLogger::ENDED                => 'ended',
        ActionLogger::ENDED_RETURNED       => 'ended',
        ActionLogger::ENDED_LOGOUT         => 'ended',
        ActionLogger::ENDED_TIMEOUT        => 'ended',
        ActionLogger::ENDED_RIGHTS         => 'ended',
        ActionLogger::DENIED               => 'denied',
    ];

    /**
     * Is $url something that may be called at all?
     */
    public static function validUrl(string $url): bool
    {
        $parts = parse_url($url);

        return \is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && !empty($parts['host'])
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }

    /**
     * @param   string  $messageKey  One of the ActionLogger message keys; others (export) are ignored.
     */
    public static function notify(string $messageKey, string $client, int $actorId, string $actorName, int $targetId, string $targetName, string $reason): void
    {
        try {
            $event = self::EVENTS[$messageKey] ?? null;
            $params = ComponentHelper::getParams('com_fgloginasuser');

            if ($event === null || !(int) $params->get('webhook_enabled', 0)) {
                return;
            }

            $wanted = (array) $params->get('webhook_events', ['started', 'ended', 'denied']);

            if ($wanted !== [] && !\in_array($event, $wanted, true)) {
                return;
            }

            $url = trim((string) $params->get('webhook_url', ''));

            if (!self::validUrl($url)) {
                return;
            }

            $site = (string) Factory::getApplication()->get('sitename', '');
            $data = [
                'event'  => $event,
                'time'   => gmdate('c'),
                'site'   => $site,
                'client' => $client,
                'actor'  => ['id' => $actorId, 'username' => $actorName],
                'target' => ['id' => $targetId, 'username' => $targetName],
                'reason' => $reason,
                'ip'     => \Joomla\Utilities\IpHelper::getIp(),
            ];

            [$body, $headers] = self::build((string) $params->get('webhook_preset', 'generic'), $data, (string) $params->get('webhook_secret', ''));

            $http = HttpFactory::getHttp(new Registry(['follow_location' => false, 'timeout' => self::TIMEOUT]), ['curl', 'stream']);
            $resp = $http->post($url, $body, $headers, self::TIMEOUT);

            if ($resp->getStatusCode() >= 300) {
                throw new \RuntimeException('HTTP ' . $resp->getStatusCode());
            }
        } catch (\Throwable $e) {
            Log::addLogger(['text_file' => 'com_fgloginasuser.log.php'], Log::WARNING, ['com_fgloginasuser']);
            Log::add('Webhook failed: ' . $e->getMessage(), Log::WARNING, 'com_fgloginasuser');
        }
    }

    /**
     * @return  array{0:string, 1:array<string,string>}  body and headers
     */
    public static function build(string $preset, array $data, string $secret = ''): array
    {
        $headers = ['Content-Type' => 'application/json'];
        $flags   = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
        $text    = self::text($data);

        switch ($preset) {
            case 'slack':
            case 'teams':
                $body = json_encode(['text' => $text], $flags);
                break;
            case 'discord':
                // Mentions are not wanted from user supplied text.
                $body = json_encode(['content' => $text, 'allowed_mentions' => ['parse' => []]], $flags);
                break;
            default:
                $body = json_encode($data, $flags);

                if ($secret !== '') {
                    $headers['X-FG-Signature'] = 'sha256=' . hash_hmac('sha256', $body, $secret);
                }
        }

        return [$body, $headers];
    }

    private static function text(array $d): string
    {
        $verb = ['started' => 'logged in as', 'ended' => 'ended the login as', 'denied' => 'was refused a login as'][$d['event']] ?? $d['event'];
        $line = '[' . $d['site'] . '] ' . $d['actor']['username'] . ' ' . $verb . ' '
            . ($d['target']['username'] !== '' ? $d['target']['username'] : '#' . $d['target']['id'])
            . ($d['client'] !== '' ? ' (' . $d['client'] . ')' : '')
            . ($d['reason'] !== '' ? ' - ' . $d['reason'] : '')
            . ' from ' . $d['ip'];

        return mb_substr($line, 0, 1500);
    }
}
