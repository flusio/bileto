<?php

// This file is part of Bileto.
// Copyright 2022-2024 Probesys
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\MessageHandler;

use App\Repository\MessageRepository;
use App\Message\SendMessageEmail;
use App\Service\MessageDocumentStorage;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;

#[AsMessageHandler]
class SendMessageEmailHandler
{
    public function __construct(
        private MessageRepository $messageRepository,
        private MessageDocumentStorage $messageDocumentStorage,
        private TransportInterface $transportInterface,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendMessageEmail $data): void
    {
        $messageId = $data->getMessageId();
        $message = $this->messageRepository->find($messageId);

        if (!$message) {
            $this->logger->error("Message {$messageId} cannot be found in SendMessageEmailHandler.");
            return;
        }

        if ($message->isConfidential()) {
            // If the message is confidential, it should not be sent to recipients
            // who don't have the orga:see:tickets:messages:confidential
            // permission. At the moment, I don't know what's the best solution
            // to handle that so I've decided to never send the notification in
            // that case. This will have to be improved in the future.
            return;
        }

        $ticket = $message->getTicket();

        $author = $message->getCreatedBy();
        $requester = $ticket->getRequester();
        $observers = $ticket->getObservers();
        $assignee = $ticket->getAssignee();

        $recipients = [];

        if ($requester !== $author) {
            $recipients[] = $requester->getEmail();
        }

        foreach ($observers as $observer) {
            if ($observer !== $author) {
                $recipients[] = $observer->getEmail();
            }
        }

        if ($assignee && $assignee !== $author) {
            $recipients[] = $assignee->getEmail();
        }

        $recipients = array_unique($recipients);

        if (empty($recipients)) {
            return;
        }

        $previousMessage = $ticket->getMessageBefore($message, confidential: false);

        $subject = "[#{$ticket->getId()}] {$ticket->getTitle()}";

        if ($previousMessage) {
            $subject = "Re: {$subject}";
        }

        $locale = $author->getLocale();

        $email = new TemplatedEmail();
        $email->bcc(...$recipients);
        $email->subject($subject);
        $email->locale($locale);
        $content = $message->getContent();
        $email->context([
            'subject' => $subject,
            'ticket' => $ticket,
            'content' => $content,
        ]);
        $email->htmlTemplate('emails/message.html.twig');
        $email->textTemplate('emails/message.txt.twig');

        foreach ($message->getMessageDocuments() as $messageDocument) {
            $filepath = $this->messageDocumentStorage->getPathname($messageDocument);
            $file = new File($filepath);
            $dataPart = new DataPart(
                $file,
                $messageDocument->getName(),
                $messageDocument->getMimetype()
            );
            $email->addPart($dataPart);
        }

        // Set correct references headers so email clients can add the email to
        // the conversation thread.
        $emailReferences = [];
        foreach ($ticket->getMessages(confidential: false) as $message) {
            $references = $message->getEmailNotificationsReferences();
            if ($references) {
                $emailReferences = array_merge($emailReferences, $references);
            }
        }

        if ($emailReferences) {
            $email->getHeaders()->addIdHeader('References', $emailReferences);
        }

        if ($previousMessage) {
            $references = $previousMessage->getEmailNotificationsReferences();

            if ($references) {
                $email->getHeaders()->addIdHeader('In-Reply-To', $references[0]);
            }
        }

        // Ask compliant autoresponders to not reply to this email
        $email->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'All');

        $sentEmail = $this->transportInterface->send($email);

        $emailId = $sentEmail->getMessageId();
        $message->addEmailNotificationReference($emailId);
        $this->messageRepository->save($message, true);
    }
}
