<?php

// This file is part of Bileto.
// Copyright 2022-2023 Probesys
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Controller;

use App\Entity\User;
use App\Repository\OrganizationRepository;
use App\Repository\UserRepository;
use App\Service\Sorter\OrganizationSorter;
use App\Service\Sorter\UserSorter;
use App\Utils\Time;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class UsersController extends BaseController
{
    #[Route('/users', name: 'users', methods: ['GET', 'HEAD'])]
    public function index(UserRepository $userRepository, UserSorter $userSorter): Response
    {
        $this->denyAccessUnlessGranted('admin:manage:users');

        $users = $userRepository->findAll();
        $userSorter->sort($users);

        return $this->render('users/index.html.twig', [
            'users' => $users,
        ]);
    }

    #[Route('/users/new', name: 'new user', methods: ['GET', 'HEAD'])]
    public function new(
        OrganizationRepository $organizationRepository,
        OrganizationSorter $organizationSorter,
    ): Response {
        $this->denyAccessUnlessGranted('admin:manage:users');

        $organizations = $organizationRepository->findAll();
        $organizations = $organizationSorter->asTree($organizations);

        return $this->render('users/new.html.twig', [
            'organizations' => $organizations,
            'email' => '',
            'name' => '',
            'organizationUid' => '',
        ]);
    }

    #[Route('/users/new', name: 'create user', methods: ['POST'])]
    public function create(
        Request $request,
        UserRepository $userRepository,
        OrganizationRepository $organizationRepository,
        OrganizationSorter $organizationSorter,
        ValidatorInterface $validator,
        TranslatorInterface $translator,
        UserPasswordHasherInterface $passwordHasher,
    ): Response {
        $this->denyAccessUnlessGranted('admin:manage:users');

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        /** @var string $email */
        $email = $request->request->get('email', '');

        /** @var string $name */
        $name = $request->request->get('name', '');

        /** @var string $password */
        $password = $request->request->get('password', '');

        /** @var string $organizationUid */
        $organizationUid = $request->request->get('organization', '');

        /** @var string $csrfToken */
        $csrfToken = $request->request->get('_csrf_token', '');

        $organizations = $organizationRepository->findAll();
        $organizations = $organizationSorter->asTree($organizations);

        if (!$this->isCsrfTokenValid('create user', $csrfToken)) {
            return $this->renderBadRequest('users/new.html.twig', [
                'organizations' => $organizations,
                'email' => $email,
                'name' => $name,
                'organizationUid' => $organizationUid,
                'error' => $translator->trans('csrf.invalid', [], 'errors'),
            ]);
        }

        $organization = $organizationRepository->findOneBy(['uid' => $organizationUid]);

        $newUser = new User();
        $newUser->setEmail($email);
        $newUser->setName($name);
        $newUser->setLocale($user->getLocale());
        $newUser->setPassword('');
        $newUser->setOrganization($organization);

        if ($password !== '') {
            $hashedPassword = $passwordHasher->hashPassword($newUser, $password);
            $newUser->setPassword($hashedPassword);
        }

        $errors = $validator->validate($newUser);
        if (count($errors) > 0) {
            return $this->renderBadRequest('users/new.html.twig', [
                'organizations' => $organizations,
                'email' => $email,
                'name' => $name,
                'organizationUid' => $organizationUid,
                'errors' => $this->formatErrors($errors),
            ]);
        }

        $userRepository->save($newUser, true);

        return $this->redirectToRoute('new user authorization', [
            'uid' => $newUser->getUid(),
        ]);
    }

    #[Route('/users/{uid}/edit', name: 'edit user', methods: ['GET', 'HEAD'])]
    public function edit(
        User $user,
        OrganizationRepository $organizationRepository,
        OrganizationSorter $organizationSorter,
    ): Response {
        $this->denyAccessUnlessGranted('admin:manage:users');

        $organizations = $organizationRepository->findAll();
        $organizations = $organizationSorter->asTree($organizations);

        $userOrganization = $user->getOrganization();

        return $this->render('users/edit.html.twig', [
            'user' => $user,
            'organizations' => $organizations,
            'email' => $user->getEmail(),
            'name' => $user->getName(),
            'organizationUid' => $userOrganization ? $userOrganization->getUid() : '',
        ]);
    }

    #[Route('/users/{uid}/edit', name: 'update user', methods: ['POST'])]
    public function update(
        User $user,
        Request $request,
        UserRepository $userRepository,
        OrganizationRepository $organizationRepository,
        OrganizationSorter $organizationSorter,
        ValidatorInterface $validator,
        TranslatorInterface $translator,
        UserPasswordHasherInterface $passwordHasher,
    ): Response {
        $this->denyAccessUnlessGranted('admin:manage:users');

        /** @var string $email */
        $email = $request->request->get('email', '');

        /** @var string $name */
        $name = $request->request->get('name', '');

        /** @var string $password */
        $password = $request->request->get('password', '');

        /** @var string $organizationUid */
        $organizationUid = $request->request->get('organization', '');

        /** @var string $csrfToken */
        $csrfToken = $request->request->get('_csrf_token', '');

        $organizations = $organizationRepository->findAll();
        $organizations = $organizationSorter->asTree($organizations);

        if (!$this->isCsrfTokenValid('update user', $csrfToken)) {
            return $this->renderBadRequest('users/edit.html.twig', [
                'user' => $user,
                'organizations' => $organizations,
                'email' => $email,
                'name' => $name,
                'organizationUid' => $organizationUid,
                'error' => $translator->trans('csrf.invalid', [], 'errors'),
            ]);
        }

        $organization = $organizationRepository->findOneBy(['uid' => $organizationUid]);

        $user->setEmail($email);
        $user->setName($name);
        $user->setOrganization($organization);

        if ($password !== '') {
            $hashedPassword = $passwordHasher->hashPassword($user, $password);
            $user->setPassword($hashedPassword);
        }

        $errors = $validator->validate($user);
        if (count($errors) > 0) {
            return $this->renderBadRequest('users/edit.html.twig', [
                'user' => $user,
                'organizations' => $organizations,
                'email' => $email,
                'name' => $name,
                'organizationUid' => $organizationUid,
                'errors' => $this->formatErrors($errors),
            ]);
        }

        $userRepository->save($user, true);

        return $this->redirectToRoute('users');
    }
}
