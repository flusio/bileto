<?php

// This file is part of Bileto.
// Copyright 2022-2023 Probesys
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Entity;

use App\EntityListener\EntitySetMetaListener;
use App\Repository\ContractRepository;
use App\Utils;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ContractRepository::class)]
#[ORM\EntityListeners([EntitySetMetaListener::class])]
class Contract implements MetaEntityInterface, ActivityRecordableInterface
{
    use MetaEntityTrait;

    public const STATUSES = ['coming', 'ongoing', 'finished'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private ?string $uid = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $createdBy = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $updatedBy = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(
        message: new TranslatableMessage('contract.name.required', [], 'errors'),
    )]
    #[Assert\Length(
        max: 255,
        maxMessage: new TranslatableMessage('contract.name.max_chars', [], 'errors'),
    )]
    private ?string $name = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    #[Assert\NotBlank(
        message: new TranslatableMessage('contract.start_at.required', [], 'errors'),
    )]
    private ?\DateTimeImmutable $startAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    #[Assert\NotBlank(
        message: new TranslatableMessage('contract.end_at.required', [], 'errors'),
    )]
    #[Assert\GreaterThan(
        propertyPath: 'startAt',
        message: new TranslatableMessage('contract.end_at.greater_than_start_at', [], 'errors'),
    )]
    private ?\DateTimeImmutable $endAt = null;

    #[ORM\Column]
    #[Assert\NotBlank(
        message: new TranslatableMessage('contract.max_hours.required', [], 'errors'),
    )]
    #[Assert\GreaterThan(
        value: 0,
        message: new TranslatableMessage('contract.max_hours.greater_than_zero', [], 'errors'),
    )]
    private ?int $maxHours = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $notes = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Organization $organization = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getStartAt(): ?\DateTimeImmutable
    {
        return $this->startAt;
    }

    public function setStartAt(\DateTimeImmutable $startAt): static
    {
        $this->startAt = $startAt->modify('00:00:00');

        return $this;
    }

    public function getEndAt(): ?\DateTimeImmutable
    {
        return $this->endAt;
    }

    public function setEndAt(\DateTimeImmutable $endAt): static
    {
        $this->endAt = $endAt->modify('23:59:59');

        return $this;
    }

    public function getMaxHours(): ?int
    {
        return $this->maxHours;
    }

    public function setMaxHours(int $maxHours): static
    {
        $this->maxHours = $maxHours;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getOrganization(): ?Organization
    {
        return $this->organization;
    }

    public function setOrganization(?Organization $organization): static
    {
        $this->organization = $organization;

        return $this;
    }

    /**
     * @return value-of<self::STATUSES>
     */
    public function getStatus(): string
    {
        $today = Utils\Time::now();
        if ($today < $this->startAt) {
            return 'coming';
        } elseif ($today < $this->endAt) {
            return 'ongoing';
        } else {
            return 'finished';
        }
    }

    public function getStatusLabel(): string
    {
        $statusesWithLabels = self::getStatusesWithLabels();
        return $statusesWithLabels[$this->getStatus()];
    }

    public function getStatusBadgeColor(): string
    {
        $status = $this->getStatus();
        if ($status === 'coming') {
            return 'blue';
        } elseif ($status === 'ongoing') {
            return 'green';
        } elseif ($status === 'finished') {
            return 'grey';
        }
    }

    /**
     * @return array<value-of<self::STATUSES>, string>
     */
    public static function getStatusesWithLabels(): array
    {
        return [
            'coming' => new TranslatableMessage('contracts.status.coming'),
            'ongoing' => new TranslatableMessage('contracts.status.ongoing'),
            'finished' => new TranslatableMessage('contracts.status.finished'),
        ];
    }
}
