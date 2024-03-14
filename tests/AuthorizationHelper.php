<?php

// This file is part of Bileto.
// Copyright 2022-2024 Probesys
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Tests;

use App\Entity\Authorization;
use App\Entity\Organization;
use App\Entity\Role;
use App\Entity\Team;
use App\Entity\User;
use App\Service\TeamService;
use App\Utils\Random;
use App\Utils\Time;
use Doctrine\ORM\EntityManager;

trait AuthorizationHelper
{
    /**
     * @param string[] $permissions
     */
    public function grantAdmin(User $user, array $permissions): void
    {
        if (empty($permissions)) {
            return;
        }

        $container = static::getContainer();
        /** @var \Doctrine\Bundle\DoctrineBundle\Registry $registry */
        $registry = $container->get('doctrine');
        $entityManager = $registry->getManager();

        /** @var \App\Repository\RoleRepository $roleRepo */
        $roleRepo = $entityManager->getRepository(Role::class);
        /** @var \App\Repository\AuthorizationRepository $authorizationRepo */
        $authorizationRepo = $entityManager->getRepository(Authorization::class);

        $superPermissionGranted = in_array('admin:*', $permissions);
        if ($superPermissionGranted) {
            $role = $roleRepo->findOrCreateSuperRole();
            $authorizationRepo->grant($user, $role);
        } else {
            $permissions = Role::sanitizePermissions('admin', $permissions);

            $role = new Role();
            $role->setName(Random::hex(10));
            $role->setDescription('The role description');
            $role->setType('admin');
            $role->setPermissions($permissions);

            $roleRepo->save($role);
            $authorizationRepo->grant($user, $role);
        }
    }

    /**
     * @param string[] $permissions
     * @param 'user'|'agent' $type
     */
    public function grantOrga(
        User $user,
        array $permissions,
        ?Organization $organization = null,
        string $type = 'agent',
    ): void {
        if (empty($permissions)) {
            return;
        }

        $container = static::getContainer();
        /** @var \Doctrine\Bundle\DoctrineBundle\Registry $registry */
        $registry = $container->get('doctrine');
        $entityManager = $registry->getManager();

        /** @var \App\Repository\RoleRepository $roleRepo */
        $roleRepo = $entityManager->getRepository(Role::class);
        /** @var \App\Repository\AuthorizationRepository $authorizationRepo */
        $authorizationRepo = $entityManager->getRepository(Authorization::class);

        $permissions = Role::sanitizePermissions($type, $permissions);

        $role = new Role();
        $role->setName(Random::hex(10));
        $role->setDescription('The role description');
        $role->setType($type);
        $role->setPermissions($permissions);

        $roleRepo->save($role);
        $authorizationRepo->grant($user, $role, $organization);
    }

    /**
     * @param string[] $permissions
     */
    public function grantTeam(
        Team $team,
        array $permissions,
        ?Organization $organization = null,
    ): void {
        if (empty($permissions)) {
            return;
        }

        $container = static::getContainer();
        /** @var \Doctrine\Bundle\DoctrineBundle\Registry $registry */
        $registry = $container->get('doctrine');
        $entityManager = $registry->getManager();

        /** @var \App\Repository\RoleRepository */
        $roleRepo = $entityManager->getRepository(Role::class);
        /** @var TeamService */
        $teamService = $container->get(TeamService::class);

        $permissions = Role::sanitizePermissions('agent', $permissions);

        $role = new Role();
        $role->setName(Random::hex(10));
        $role->setDescription('The role description');
        $role->setType('agent');
        $role->setPermissions($permissions);

        $roleRepo->save($role);
        $teamService->createAuthorization($team, $role, $organization);
    }
}
