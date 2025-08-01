<?php

// This file is part of Bileto.
// Copyright 2022-2025 Probesys
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\TicketActivity;

use App\Repository;
use App\Security;
use App\Service;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Handle the lifecycle of tickets.
 *
 * This class centralizes the updates of the tickets' status based on events
 * triggered by the rest of the application.
 */
class LifecycleSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            TicketEvent::CREATED => 'processCreatedTicket',
            TicketEvent::ASSIGNED => 'processAssignedTicket',
            TicketEvent::APPROVED => 'processApprovedTicket',
            MessageEvent::CREATED_ANSWER => 'processAnswer',
            MessageEvent::CREATED_SOLUTION => 'processNewSolution',
            MessageEvent::APPROVED_SOLUTION => 'processApprovedSolution',
            MessageEvent::REFUSED_SOLUTION => 'processRefusedSolution',
        ];
    }

    public function __construct(
        private Repository\TicketRepository $ticketRepository,
        private Repository\UserRepository $userRepository,
        private Security\Authorizer $authorizer,
        private Service\ActorsLister $actorsLister,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * Assign the ticket if there is only one possible assignee.
     */
    public function processCreatedTicket(TicketEvent $event): void
    {
        $ticket = $event->getTicket();
        $assignee = $ticket->getAssignee();

        if ($assignee !== null) {
            return;
        }

        $organization = $ticket->getOrganization();
        $possibleAssignees = $this->userRepository->findByAccessToOrganizations([$organization], 'agent');

        if (count($possibleAssignees) !== 1) {
            return;
        }

        // $ticket->setAssignee($possibleAssignees[0]);

        // $this->ticketRepository->save($ticket, true);

        // $ticketEvent = new TicketEvent($ticket);
        // $this->eventDispatcher->dispatch($ticketEvent, TicketEvent::ASSIGNED);
    }

    /**
     * Pass a "new" ticket to "in progress" on a ticket is assigned.
     */
    public function processAssignedTicket(TicketEvent $event): void
    {
        $ticket = $event->getTicket();
        $status = $ticket->getStatus();
        $assignee = $ticket->getAssignee();

        if ($assignee !== null && $status === 'new') {
            $ticket->setStatus('in_progress');
            $this->ticketRepository->save($ticket, true);
        }
    }

    /**
     * Automatically close a resolved ticket (e.g. after few days).
     */
    public function processApprovedTicket(TicketEvent $event): void
    {
        $ticket = $event->getTicket();

        if ($ticket->getStatus() !== 'resolved') {
            return;
        }

        $ticket->setStatus('closed');

        $this->ticketRepository->save($ticket, true);
    }

    /**
     * Update the ticket's status when answering to the ticket.
     */
    public function processAnswer(MessageEvent $event): void
    {
        $message = $event->getMessage();
        $ticket = $message->getTicket();
        $organization = $ticket->getOrganization();

        $messageAuthor = $message->getCreatedBy();
        $isConfidential = $message->isConfidential();
        $via = $message->getVia();
        $requester = $ticket->getRequester();
        $assignee = $ticket->getAssignee();
        $status = $ticket->getStatus();

        if ($messageAuthor == $assignee) {
            if ($status === 'in_progress' && !$isConfidential) {
                $ticket->setStatus('pending');
            }
        } elseif ($messageAuthor == $requester) {
            if ($status === 'pending') {
                $ticket->setStatus('in_progress');
            }
        }

        if ($status === 'resolved' && !$this->authorizer->isUserAgent($messageAuthor, $organization)) {
            $ticket->setStatus('in_progress');
            $ticket->setSolution(null);
        }

        $this->ticketRepository->save($ticket, true);
    }

    /**
     * Mark a ticket as resolved when a new solution is posted.
     */
    public function processNewSolution(MessageEvent $event): void
    {
        $message = $event->getMessage();
        $ticket = $message->getTicket();

        if (
            $ticket->hasSolution() ||
            $ticket->getStatus() === 'closed' ||
            $message->isConfidential()
        ) {
            return;
        }

        $ticket->setSolution($message);
        $ticket->setStatus('resolved');

        $this->ticketRepository->save($ticket, true);

        $ticketEvent = new TicketEvent($ticket);
        $this->eventDispatcher->dispatch($ticketEvent, TicketEvent::RESOLVED);
    }

    /**
     * Close a ticket when a solution is approved.
     */
    public function processApprovedSolution(MessageEvent $event): void
    {
        $message = $event->getMessage();
        $ticket = $message->getTicket();

        if ($ticket->getStatus() !== 'resolved') {
            return;
        }

        $ticket->setStatus('closed');

        $this->ticketRepository->save($ticket, true);
    }

    /**
     * Reopen a ticket when a solution is refused.
     */
    public function processRefusedSolution(MessageEvent $event): void
    {
        $message = $event->getMessage();
        $ticket = $message->getTicket();

        if ($ticket->getStatus() !== 'resolved') {
            return;
        }

        $ticket->setStatus('in_progress');
        $ticket->setSolution(null);

        $this->ticketRepository->save($ticket, true);
    }
}
