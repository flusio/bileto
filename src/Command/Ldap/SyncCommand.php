<?php

// This file is part of Bileto.
// Copyright 2022-2025 Probesys
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Command\Ldap;

use App\Message\SynchronizeLdap;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

#[AsCommand(
    name: 'app:ldap:sync',
    description: 'Synchronize the LDAP directory manually',
)]
class SyncCommand
{
    public function __construct(
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(): int
    {
        $this->bus->dispatch(new SynchronizeLdap(), [
            new TransportNamesStamp('sync'),
        ]);

        return Command::SUCCESS;
    }
}
