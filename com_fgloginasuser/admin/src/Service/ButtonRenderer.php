<?php

/**
 * @package     FG Login as User
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace FG\Component\Fgloginasuser\Administrator\Service;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * Markup of the login buttons, shared by the component's own Users screen and the list in com_users
 * (injected by the system plugin). The buttons are inert without the single hidden form from form()
 * and media/js/users.js.
 */
final class ButtonRenderer
{
    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param   ?string  $errorSite   Refusal language key for the frontend, null when allowed.
     * @param   ?string  $errorAdmin  Same for the backend.
     * @param   bool     $showDisabled  Render refused actions as disabled buttons with the reason as tooltip.
     * @param   bool     $compact       Short labels (Frontend / Backend) with the full sentence as tooltip, for the narrow
     *                                  column in the core Users list. Otherwise the full text is the label.
     */
    public static function buttons(
        Registry $params,
        int $id,
        string $username,
        ?string $errorSite,
        ?string $errorAdmin,
        bool $showDisabled,
        bool $compact = false
    ): string {
        $newTab = (int) $params->get('site_new_tab', 1);
        $html   = '';

        $targets = [
            ['site', $errorSite, 'COM_FGLOGINASUSER_BTN_SITE', 'COM_FGLOGINASUSER_BTN_SITE_SHORT', trim((string) $params->get('button_text_site', '')), 'icon-eye', 'btn-primary'],
            ['administrator', $errorAdmin, 'COM_FGLOGINASUSER_BTN_ADMIN', 'COM_FGLOGINASUSER_BTN_ADMIN_SHORT', trim((string) $params->get('button_text_admin', '')), 'icon-cogs', 'btn-warning'],
        ];

        foreach ($targets as [$client, $error, $fullKey, $shortKey, $custom, $icon, $class]) {
            $full  = self::e(str_replace('%s', $username, $custom !== '' ? $custom : Text::_($fullKey)));
            // A text configured by the administrator always wins; the compact label only replaces the default text.
            $label = $compact && $custom === '' ? self::e(Text::_($shortKey)) : $full;

            if ($error === null) {
                $confirm = $client === 'administrator'
                    ? ' data-confirm="' . self::e(Text::sprintf('COM_FGLOGINASUSER_CONFIRM_ADMIN', $username)) . '"'
                    : '';
                $tip = $compact ? ' title="' . $full . '" aria-label="' . $full . '"' : '';

                $html .= '<button type="button" class="btn btn-sm ' . $class . ' fg-las-btn" data-id="' . $id
                    . '" data-client="' . $client . '" data-new-tab="' . ($client === 'site' ? $newTab : 0) . '"'
                    . ' data-username="' . self::e($username) . '"' . $tip . $confirm . '><span class="' . $icon . '" aria-hidden="true"></span> ' . $label . '</button>';
            } elseif ($showDisabled) {
                $reason = self::e(Text::_($error));
                $tip    = $compact ? $full . ' - ' . $reason : $reason;
                $html  .= '<button type="button" class="btn btn-sm btn-outline-secondary" disabled title="' . $tip
                    . '" aria-label="' . $full . ' - ' . $reason . '"><span class="icon-lock" aria-hidden="true"></span> ' . $label . '</button>';
            }
        }

        return $html;
    }

    /**
     * The one hidden POST form every button submits through. Kept outside any list form so that
     * pressing Enter in a search box can never start an impersonation.
     */
    public static function form(): string
    {
        $e    = static fn (string $key): string => self::e(Text::_($key));
        $mode = Justification::mode(ComponentHelper::getParams('com_fgloginasuser'));

        $form = '<form id="fgLasForm" action="' . self::e(Route::_('index.php?option=com_fgloginasuser&task=impersonate.start', false))
            . '" method="post" class="d-none"><input type="hidden" name="id" value="">'
            . '<input type="hidden" name="client" value=""><input type="hidden" name="reason" value=""><input type="hidden" name="stepup_password" value=""><input type="hidden" name="stepup_code" value="">' . HTMLHelper::_('form.token') . '</form>';

        // Confirmation (backend switch) and reason, in one styled dialog. Own element, not nested in any form;
        // users.js falls back to window.confirm / window.prompt when <dialog> is not supported.
        $reasonBlock = '';

        if ($mode > 0) {
            $params  = ComponentHelper::getParams('com_fgloginasuser');
            $presets = Justification::presets($params);
            $other   = Justification::otherAllowed($params);

            if ($presets !== []) {
                $options = '<option value="">' . $e($mode === 2 ? 'COM_FGLOGINASUSER_DIALOG_CHOOSE' : 'COM_FGLOGINASUSER_DIALOG_NO_REASON') . '</option>';

                foreach ($presets as $label) {
                    $options .= '<option value="p:' . self::e($label) . '">' . self::e($label) . '</option>';
                }

                if ($other) {
                    $options .= '<option value="other">' . $e('COM_FGLOGINASUSER_DIALOG_OTHER') . '</option>';
                }

                $reasonBlock = '<label class="fg-las-dialog-label" for="fgLasReasonSelect">' . $e($mode === 2 ? 'COM_FGLOGINASUSER_DIALOG_REASON_SELECT_REQUIRED' : 'COM_FGLOGINASUSER_DIALOG_REASON_SELECT')
                    . '</label><select id="fgLasReasonSelect" class="form-select mb-2"' . ($mode === 2 ? ' required' : '') . '>' . $options . '</select>'
                    . '<label class="fg-las-dialog-label" for="fgLasReason" id="fgLasReasonLabel" hidden></label>'
                    . '<textarea id="fgLasReason" class="form-control" rows="2" maxlength="' . Justification::MAX . '" hidden'
                    . ' data-label-note="' . $e('COM_FGLOGINASUSER_DIALOG_NOTE') . '" data-label-other="' . $e('COM_FGLOGINASUSER_DIALOG_OTHER_TEXT') . '"></textarea>';
            } else {
                $reasonBlock = '<label class="fg-las-dialog-label" for="fgLasReason">' . $e($mode === 2 ? 'COM_FGLOGINASUSER_DIALOG_REASON_REQUIRED' : 'COM_FGLOGINASUSER_DIALOG_REASON')
                    . '</label><textarea id="fgLasReason" class="form-control" rows="2" maxlength="' . Justification::MAX . '"'
                    . ($mode === 2 ? ' required' : '') . '></textarea>';
            }
        }

        // Re-authentication (off by default); not asked while a recent check of this administrator is still valid.
        $stepBlock = '';
        $stepOn    = 0;
        $app       = Factory::getApplication();
        $paramsAll = ComponentHelper::getParams('com_fgloginasuser');
        $me        = $app->getIdentity();

        if ($me && $me->id && StepUp::mode($paramsAll) > 0 && !StepUp::isFresh($app, $paramsAll, $me)) {
            $stepOn    = 1;
            $stepBlock = '<label class="fg-las-dialog-label" for="fgLasStepPw">' . $e('COM_FGLOGINASUSER_DIALOG_STEPUP_PASSWORD') . '</label>'
                . '<input type="password" id="fgLasStepPw" class="form-control mb-2" autocomplete="current-password" required>';

            if (StepUp::needsCode($app, $paramsAll, $me)) {
                $stepBlock .= '<label class="fg-las-dialog-label" for="fgLasStepCode">' . $e('COM_FGLOGINASUSER_DIALOG_STEPUP_CODE') . '</label>'
                    . '<input type="text" id="fgLasStepCode" class="form-control mb-2" inputmode="numeric" autocomplete="one-time-code" required>';
            }
        }

        $dialog = '<dialog id="fgLasDialog" class="fg-las-dialog" aria-labelledby="fgLasDialogTitle" data-reason-mode="' . $mode . '" data-stepup="' . $stepOn . '"'
            . ' data-title-administrator="' . $e('COM_FGLOGINASUSER_DIALOG_TITLE') . '" data-title-site="' . $e('COM_FGLOGINASUSER_DIALOG_TITLE_SITE') . '"'
            . ' data-ok-administrator="' . $e('COM_FGLOGINASUSER_DIALOG_OK') . '" data-ok-site="' . $e('COM_FGLOGINASUSER_DIALOG_OK_SITE') . '">'
            . '<form method="dialog">'
            . '<div class="fg-las-dialog-head"><span class="icon-cogs fg-las-dialog-icon" aria-hidden="true"></span>'
            . '<h2 id="fgLasDialogTitle" class="fg-las-dialog-title"></h2></div>'
            . '<p class="fg-las-dialog-body" data-for="administrator">' . $e('COM_FGLOGINASUSER_DIALOG_BODY') . '</p>'
            . '<p class="fg-las-dialog-body" data-for="site">' . $e('COM_FGLOGINASUSER_DIALOG_BODY_SITE') . '</p>'
            . $stepBlock
            . $reasonBlock
            . '<div class="fg-las-dialog-actions">'
            . '<button type="submit" value="cancel" id="fgLasDialogCancel" class="btn btn-secondary" formnovalidate autofocus>' . $e('JCANCEL') . '</button>'
            . '<button type="submit" value="ok" id="fgLasDialogOk" class="btn btn-warning"></button>'
            . '</div></form></dialog>';

        return $form . $dialog;
    }

    /**
     * Admin supplied CSS; "<" is stripped so it cannot break out of the style element.
     */
    public static function customCss(Registry $params): string
    {
        return trim(str_replace('<', '', (string) $params->get('custom_css', '')));
    }
}
