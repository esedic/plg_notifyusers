<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Content.notifyusers
 *
 * @copyright   (C) 2010 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Content\Notifyusers\Extension;

use Exception;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Mail\Mail;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\Component\Content\Site\Helper\RouteHelper;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Notify users Content Plugin
 *
 * @since  1.6
 */
final class Notifyusers extends CMSPlugin implements SubscriberInterface, DatabaseAwareInterface
{
    use DatabaseAwareTrait;

    /**
     * The application object
     *
     * @var    CMSApplicationInterface
     * @since  4.0.0
     */
    protected $app;

    /**
     * Returns an array of events this subscriber will listen to.
     *
     * @return  array
     *
     * @since   5.0.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onContentAfterSave' => 'onContentAfterSave',
        ];
    }

    /**
     * Content after save event handler
     * Article is passed by reference, but after the save, so no changes will be saved.
     * Method is called right after the content is saved
     *
     * @param   Event  $event  The event object
     *
     * @return  void
     *
     * @since   1.6
     */
    public function onContentAfterSave(Event $event): void
    {
        $arguments = $event->getArguments();
        $context = $arguments['context'] ?? '';
        $article = $arguments['subject'] ?? null;
        $isNew = $arguments['isNew'] ?? false;

        // Don't run if we are not in article context
        if ($context !== 'com_content.article') {
            return;
        }

        // Don't run if article is not published
        if (!$article || $article->state != 1) {
            return;
        }

        $this->sendNotificationEmails($article);
    }

    /**
     * Send notification emails to configured user groups
     *
     * @param   object  $article  The article object
     *
     * @return  void
     *
     * @since   5.0.0
     */
    private function sendNotificationEmails($article): void
    {
        try {
            $config = Factory::getConfig();
            $mailer = Factory::getMailer();
            
            if (!$mailer) {
                $this->app->enqueueMessage(Text::_('JERROR_LOADING_MAILER'), 'error');
                return;
            }

            $mailer->CharSet = 'UTF-8';
            $mailer->Encoding = 'quoted-printable';

            // Email Sender
            $sender = [
                $config->get('mailfrom'),
                $config->get('fromname')
            ];

            $mailer->setSender($sender);

            // Email Recipients   
            $recipients = $this->getUsersEmails();

            if (empty($recipients)) {
                return;
            }

            // Get first element in array of emails - Recipient
            $recipient = reset($recipients);
            // Get other elements in array of emails - BCC
            $bccArray = array_slice($recipients, 1);

            if (!empty($bccArray)) {
                $mailer->addBcc($bccArray);
            }
            $mailer->addRecipient($recipient);

            // Get article info
            $title = $article->title;
            $articleLink = RouteHelper::getArticleRoute($article->id, $article->catid, $article->language);
            $url = Route::link('site', $articleLink, false, Route::TLS_IGNORE, true);

            // Create the Mail
            $subject = $this->params->get('subject', Text::_('PLG_CONTENT_NOTIFYUSERS_DEFAULT_SUBJECT'));
            $mailer->setSubject($subject);
            $mailer->isHtml(true);
            $mailer->Encoding = 'base64';
            
            $body = sprintf(
                '<p>%s <a href="%s">%s</a></p><hr/><p><img src="%s" width="279" height="60" alt="Logo"></p>',
                Text::_('PLG_CONTENT_NOTIFYUSERS_EMAIL_BODY_TEXT'),
                $url,
                htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
                Uri::root() . 'images/logo.png'
            );
            
            $mailer->setBody($body);

            // Send the Mail
            $send = $mailer->Send();

            if ($send === false) {
                $this->app->enqueueMessage(Text::_('JERROR_SENDING_EMAIL'), 'warning');
            }

        } catch (Exception $exception) {
            try {
                Log::add($exception->getMessage(), Log::WARNING, 'jerror');
            } catch (\RuntimeException $runtimeException) {
                $this->app->enqueueMessage(Text::_('JERROR_SENDING_EMAIL'), 'warning');
            }
        }
    }

    /**
     * Returns the Users emails based on configured user groups
     *
     * @param   string|null  $email  A list of specific Users to email (optional)
     *
     * @return  array  The list of User emails
     *
     * @since   3.5
     */
    private function getUsersEmails(?string $email = null): array
    {
        $db = $this->getDatabase();
        $emails = [];

        // Convert the email list to an array
        if (!empty($email)) {
            $temp = explode(',', $email);

            foreach ($temp as $entry) {
                $emails[] = trim($entry);
            }

            $emails = array_unique($emails);
        }

        // A list of groups
        $ret = [];
        $groups = $this->params->get('usergroups', []);

        if (empty($groups)) {
            return $ret;
        }

        // Ensure groups is an array
        if (!is_array($groups)) {
            $groups = [$groups];
        }

        // Get the user IDs of users belonging to the user groups
        try {
            $query = $db->getQuery(true)
                ->select($db->quoteName('user_id'))
                ->from($db->quoteName('#__user_usergroup_map'))
                ->whereIn($db->quoteName('group_id'), $groups, ParameterType::INTEGER);

            $db->setQuery($query);
            $userIDs = $db->loadColumn(0);

            if (empty($userIDs)) {
                return $ret;
            }
        } catch (Exception $exc) {
            Log::add('Error getting user IDs: ' . $exc->getMessage(), Log::ERROR, 'jerror');
            return $ret;
        }

        // Get the user information
        try {
            $query = $db->getQuery(true)
                ->select($db->quoteName('email'))
                ->from($db->quoteName('#__users'))
                ->whereIn($db->quoteName('id'), $userIDs, ParameterType::INTEGER)
                ->where($db->quoteName('block') . ' = 0')
                ->where($db->quoteName('sendEmail') . ' = 1');

            if (!empty($emails)) {
                $lowerCaseEmails = array_map('strtolower', $emails);
                $query->whereIn('LOWER(' . $db->quoteName('email') . ')', $lowerCaseEmails, ParameterType::STRING);
            }

            $db->setQuery($query);
            $ret = $db->loadColumn();
        } catch (Exception $exc) {
            Log::add('Error getting user emails: ' . $exc->getMessage(), Log::ERROR, 'jerror');
            return $ret;
        }

        return $ret ?: [];
    }
}