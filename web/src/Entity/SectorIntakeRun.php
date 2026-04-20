<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'sector_intake_run')]
#[ORM\UniqueConstraint(name: 'uniq_sector_intake_run_key', columns: ['run_key'])]
#[ORM\Index(columns: ['created_at'], name: 'idx_sector_intake_run_created_at')]
#[ORM\Index(columns: ['status'], name: 'idx_sector_intake_run_status')]
class SectorIntakeRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $runKey = '';

    #[ORM\Column(length: 32)]
    private string $status = 'running';

    #[ORM\Column(length: 32)]
    private string $mode = 'db';

    #[ORM\Column]
    private bool $dryRun = true;

    #[ORM\Column(type: Types::JSON)]
    private array $configJson = [];

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $summaryJson = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;
}
