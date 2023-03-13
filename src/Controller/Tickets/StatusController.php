<?php

// This file is part of Bileto.
// Copyright 2022-2023 Probesys
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Controller\Tickets;

use App\Controller\BaseController;
use App\Entity\Ticket;
use App\Repository\TicketRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class StatusController extends BaseController
{
    #[Route('/tickets/{uid}/status/edit', name: 'edit ticket status', methods: ['GET', 'HEAD'])]
    public function edit(Ticket $ticket): Response
    {
        $organization = $ticket->getOrganization();
        $this->denyAccessUnlessGranted('orga:update:tickets:status', $organization);

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        if (!$ticket->hasActor($user)) {
            $this->denyAccessUnlessGranted('orga:see:tickets:all', $organization);
        }

        $statuses = Ticket::getStatusesWithLabels();

        return $this->render('tickets/status/edit.html.twig', [
            'ticket' => $ticket,
            'status' => $ticket->getStatus(),
            'statuses' => $statuses,
        ]);
    }

    #[Route('/tickets/{uid}/status/edit', name: 'update ticket status', methods: ['POST'])]
    public function update(
        Ticket $ticket,
        Request $request,
        TicketRepository $ticketRepository,
        ValidatorInterface $validator,
        TranslatorInterface $translator,
    ): Response {
        $organization = $ticket->getOrganization();
        $this->denyAccessUnlessGranted('orga:update:tickets:status', $organization);

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        if (!$ticket->hasActor($user)) {
            $this->denyAccessUnlessGranted('orga:see:tickets:all', $organization);
        }

        /** @var string $status */
        $status = $request->request->get('status', $ticket->getStatus());

        /** @var string $csrfToken */
        $csrfToken = $request->request->get('_csrf_token', '');

        $statuses = Ticket::getStatusesWithLabels();

        if (!$this->isCsrfTokenValid('update ticket status', $csrfToken)) {
            return $this->renderBadRequest('tickets/status/edit.html.twig', [
                'ticket' => $ticket,
                'status' => $status,
                'statuses' => $statuses,
                'error' => $translator->trans('csrf.invalid', [], 'errors'),
            ]);
        }

        $ticket->setStatus($status);

        $errors = $validator->validate($ticket);
        if (count($errors) > 0) {
            return $this->renderBadRequest('tickets/status/edit.html.twig', [
                'ticket' => $ticket,
                'status' => $status,
                'statuses' => $statuses,
                'errors' => $this->formatErrors($errors),
            ]);
        }

        $ticketRepository->save($ticket, true);

        return $this->redirectToRoute('ticket', [
            'uid' => $ticket->getUid(),
        ]);
    }
}
