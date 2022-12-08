<?php

// This file is part of Bileto.
// Copyright 2022 Probesys
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Tests\Controller\Tickets;

use App\Tests\Factory\TicketFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class PriorityControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testGetEditRendersCorrectly(): void
    {
        $client = static::createClient();
        $user = UserFactory::createOne();
        $client->loginUser($user->object());
        $ticket = TicketFactory::createOne([
            'createdBy' => $user,
        ]);

        $client->request('GET', "/tickets/{$ticket->getUid()}/priority/edit");

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Edit the priority');
    }

    public function testGetEditRedirectsToLoginIfNotConnected(): void
    {
        $client = static::createClient();
        $user = UserFactory::createOne();
        $ticket = TicketFactory::createOne([
            'createdBy' => $user,
        ]);

        $client->request('GET', "/tickets/{$ticket->getUid()}/priority/edit");

        $this->assertResponseRedirects('http://localhost/login', 302);
    }

    public function testPostUpdateSavesTicketAndRedirects(): void
    {
        $client = static::createClient();
        $user = UserFactory::createOne();
        $client->loginUser($user->object());
        $oldUrgency = 'low';
        $oldImpact = 'low';
        $oldPriority = 'low';
        $newUrgency = 'high';
        $newImpact = 'high';
        $newPriority = 'high';
        $ticket = TicketFactory::createOne([
            'createdBy' => $user,
            'urgency' => $oldUrgency,
            'impact' => $oldImpact,
            'priority' => $oldPriority,
        ]);

        $client->request('GET', "/tickets/{$ticket->getUid()}/priority/edit");
        $crawler = $client->submitForm('form-update-priority-submit', [
            'urgency' => $newUrgency,
            'impact' => $newImpact,
            'priority' => $newPriority,
        ]);

        $this->assertResponseRedirects("/tickets/{$ticket->getUid()}", 302);
        $ticket->refresh();
        $this->assertSame($newUrgency, $ticket->getUrgency());
        $this->assertSame($newImpact, $ticket->getImpact());
        $this->assertSame($newPriority, $ticket->getPriority());
    }

    public function testPostUpdateFailsIfCsrfTokenIsInvalid(): void
    {
        $client = static::createClient();
        $user = UserFactory::createOne();
        $client->loginUser($user->object());
        $oldUrgency = 'low';
        $oldImpact = 'low';
        $oldPriority = 'low';
        $newUrgency = 'high';
        $newImpact = 'high';
        $newPriority = 'high';
        $ticket = TicketFactory::createOne([
            'createdBy' => $user,
            'urgency' => $oldUrgency,
            'impact' => $oldImpact,
            'priority' => $oldPriority,
        ]);

        $client->request('GET', "/tickets/{$ticket->getUid()}/priority/edit");
        $crawler = $client->submitForm('form-update-priority-submit', [
            '_csrf_token' => 'not the token',
            'urgency' => $newUrgency,
            'impact' => $newImpact,
            'priority' => $newPriority,
        ]);

        $ticket->refresh();
        $this->assertSame($oldUrgency, $ticket->getUrgency());
        $this->assertSame($oldImpact, $ticket->getImpact());
        $this->assertSame($oldPriority, $ticket->getPriority());
    }
}
