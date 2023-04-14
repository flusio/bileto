<?php

// This file is part of Bileto.
// Copyright 2022-2023 Probesys
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\SearchEngine\QueryBuilder;

use App\Entity\Ticket;
use App\Entity\User;
use App\Repository\OrganizationRepository;
use App\Repository\UserRepository;
use App\SearchEngine\Query;
use Symfony\Bundle\SecurityBundle\Security;

class TicketQueryBuilder
{
    /** @var array<string, mixed> */
    private array $parameters;

    private int $querySequence;

    private OrganizationRepository $organizationRepository;

    private UserRepository $userRepository;

    private Security $security;

    public function __construct(
        UserRepository $userRepository,
        OrganizationRepository $organizationRepository,
        Security $security,
    ) {
        $this->security = $security;
        $this->userRepository = $userRepository;
        $this->organizationRepository = $organizationRepository;
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    public function build(Query $query, int $querySequence = 0): array
    {
        $this->parameters = [];
        $this->querySequence = $querySequence;

        // Build the Doctrine Query and retrieve parameters.
        $where = $this->buildWhere($query);
        $parameters = $this->parameters;

        // Reset the attributes for the next query and to free memory.
        $this->parameters = [];
        $this->querySequence = 0;

        return [$where, $parameters];
    }

    private function buildWhere(Query $query): string
    {
        $where = '';

        foreach ($query->getConditions() as $condition) {
            $expr = '';

            if ($condition->isTextCondition()) {
                $expr = $this->buildTextExpr($condition);
            } elseif ($condition->isQualifierCondition()) {
                $expr = $this->buildQualifierExpr($condition);
            } elseif ($condition->isQueryCondition()) {
                $expr = $this->buildQueryExpr($condition);
            }

            if (!$expr) {
                throw new \LogicException('A condition is defective as it generates an empty expression');
            }

            if ($where === '') {
                $where = $expr;
            } elseif ($condition->and()) {
                $where .= " AND {$expr}";
            } else {
                $where .= " OR {$expr}";
            }
        }

        return $where;
    }

    private function buildTextExpr(Query\Condition $condition): string
    {
        $value = $condition->getValue();

        if (is_array($value)) {
            $exprs = [];

            foreach ($value as $v) {
                $id = $this->extractId($v);
                if ($id !== null) {
                    $exprs[] = $this->buildExpr('id', $id, false);
                } else {
                    $exprs[] = $this->buildExprLike('title', $v, false);
                }
            }

            $where = implode(' OR ', $exprs);

            if ($condition->not()) {
                return "NOT ({$where})";
            } else {
                return "({$where})";
            }
        } else {
            $id = $this->extractId($value);

            if ($id !== null) {
                return $this->buildExpr('id', $id, $condition->not());
            } else {
                return $this->buildExprLike('title', $value, $condition->not());
            }
        }
    }

    private function buildQualifierExpr(Query\Condition $condition): string
    {
        $qualifier = $condition->getQualifier();
        $value = $condition->getValue();

        if ($qualifier === 'status') {
            $value = $this->processStatusQualifier($value);
            return $this->buildExpr('status', $value, $condition->not());
        } elseif ($qualifier === 'assignee' || $qualifier === 'requester') {
            $value = $this->processActorQualifier($value);
            return $this->buildExpr($qualifier, $value, $condition->not());
        } elseif ($qualifier === 'involves') {
            $value = $this->processActorQualifier($value);
            $assigneeWhere = $this->buildExpr('assignee', $value, false);
            $requesterWhere = $this->buildExpr('requester', $value, false);
            $where = "{$assigneeWhere} OR {$requesterWhere}";
            if ($condition->not()) {
                return "NOT ({$where})";
            } else {
                return "({$where})";
            }
        } elseif ($qualifier === 'org') {
            $value = $this->processOrganizationQualifier($value);
            return $this->buildExpr('organization', $value, $condition->not());
        } elseif (
            $qualifier === 'uid' ||
            $qualifier === 'type' ||
            $qualifier === 'urgency' ||
            $qualifier === 'impact' ||
            $qualifier === 'priority'
        ) {
            return $this->buildExpr($qualifier, $value, $condition->not());
        } elseif ($qualifier === 'no' && ($value === 'assignee' || $value === 'solution')) {
            return $this->buildExpr($value, null, $condition->not());
        } elseif ($qualifier === 'has' && ($value === 'assignee' || $value === 'solution')) {
            return $this->buildExpr($value, null, !$condition->not());
        } else {
            if (is_array($value)) {
                $value = implode(',', $value);
            }
            throw new \UnexpectedValueException("Unexpected \"{$qualifier}\" qualifier with value \"{$value}\"");
        }
    }

    private function buildQueryExpr(Query\Condition $condition): string
    {
        $subQuery = $condition->getQuery();

        if ($condition->not()) {
            return "NOT ({$this->buildWhere($subQuery)})";
        } else {
            return "({$this->buildWhere($subQuery)})";
        }
    }

    /**
     * @param literal-string $field
     */
    private function buildExpr(string $field, mixed $value, bool $not): string
    {
        if ($value === null && $not) {
            return "t.{$field} IS NOT NULL";
        } elseif ($value === null) {
            return "t.{$field} IS NULL";
        } elseif (is_array($value) && $not) {
            $key = $this->registerParameter($value);
            return "t.{$field} NOT IN (:{$key})";
        } elseif (is_array($value)) {
            $key = $this->registerParameter($value);
            return "t.{$field} IN (:{$key})";
        } elseif ($not) {
            $key = $this->registerParameter($value);
            return "t.{$field} != :{$key}";
        } else {
            $key = $this->registerParameter($value);
            return "t.{$field} = :{$key}";
        }
    }

    /**
     * @param literal-string $field
     */
    private function buildExprLike(string $field, string $value, bool $not): string
    {
        $value = mb_strtolower($value);
        $key = $this->registerParameter("%{$value}%");
        if ($not) {
            return "LOWER(t.{$field}) NOT LIKE :{$key}";
        } else {
            return "LOWER(t.{$field}) LIKE :{$key}";
        }
    }

    /**
     * @param string|string[] $value
     *
     * @return string|string[]
     */
    private function processStatusQualifier(mixed $value): mixed
    {
        if (!is_array($value)) {
            $value = [$value];
        }

        $valuesToReturn = [];

        foreach ($value as $v) {
            if ($v === 'open') {
                $valuesToReturn = array_merge($valuesToReturn, Ticket::OPEN_STATUSES);
            } elseif ($v === 'finished') {
                $valuesToReturn = array_merge($valuesToReturn, Ticket::FINISHED_STATUSES);
            } else {
                $valuesToReturn[] = $v;
            }
        }

        if (count($valuesToReturn) === 1) {
            return $valuesToReturn[0];
        } else {
            return $valuesToReturn;
        }
    }

    /**
     * @param string|string[] $value
     *
     * @return int|int[]
     */
    private function processActorQualifier(mixed $value): mixed
    {
        if (!is_array($value)) {
            $value = [$value];
        }

        $valuesToReturn = [];

        foreach ($value as $v) {
            $id = $this->extractId($v);
            if ($id !== null) {
                $ids = [$id];
            } elseif ($v === '@me') {
                /** @var User $currentUser */
                $currentUser = $this->security->getUser();
                $ids = [$currentUser->getId()];
            } else {
                $users = $this->userRepository->findLike($v);

                $ids = array_map(function ($user) {
                    return $user->getId();
                }, $users);
            }

            if ($ids) {
                $valuesToReturn = array_merge($valuesToReturn, $ids);
            } else {
                $valuesToReturn[] = -1;
            }
        }

        if (count($valuesToReturn) === 1) {
            return $valuesToReturn[0];
        } else {
            return $valuesToReturn;
        }
    }

    /**
     * @param string|string[] $value
     *
     * @return int|int[]
     */
    private function processOrganizationQualifier(mixed $value): mixed
    {
        if (!is_array($value)) {
            $value = [$value];
        }

        $valuesToReturn = [];

        foreach ($value as $v) {
            $id = $this->extractId($v);
            if ($id !== null) {
                $ids = [$id];
            } else {
                $organizations = $this->organizationRepository->findLike($v);

                $ids = array_map(function ($orga) {
                    return $orga->getId();
                }, $organizations);
            }

            if ($ids) {
                $valuesToReturn = array_merge($valuesToReturn, $ids);
            } else {
                $valuesToReturn[] = -1;
            }
        }

        if (count($valuesToReturn) === 1) {
            return $valuesToReturn[0];
        } else {
            return $valuesToReturn;
        }
    }

    private function extractId(string $value): ?int
    {
        if (preg_match('/^#[\d]+$/', $value)) {
            $value = substr($value, 1);
            return intval($value);
        } else {
            return null;
        }
    }

    private function registerParameter(mixed $value): string
    {
        $paramNumber = count($this->parameters);
        $key = "q{$this->querySequence}p{$paramNumber}";
        $this->parameters[$key] = $value;
        return $key;
    }
}
