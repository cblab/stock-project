<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260420093000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add SEPA / Minervini Execution Layer scores to snapshots.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE instrument_sepa_snapshot ADD vcp_score DOUBLE PRECISION NOT NULL DEFAULT 0, ADD microstructure_score DOUBLE PRECISION NOT NULL DEFAULT 0, ADD breakout_readiness_score DOUBLE PRECISION NOT NULL DEFAULT 0, ADD structure_score DOUBLE PRECISION NOT NULL DEFAULT 0, ADD execution_score DOUBLE PRECISION NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE instrument_sepa_snapshot CHANGE vcp_score vcp_score DOUBLE PRECISION NOT NULL, CHANGE microstructure_score microstructure_score DOUBLE PRECISION NOT NULL, CHANGE breakout_readiness_score breakout_readiness_score DOUBLE PRECISION NOT NULL, CHANGE structure_score structure_score DOUBLE PRECISION NOT NULL, CHANGE execution_score execution_score DOUBLE PRECISION NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE instrument_sepa_snapshot DROP vcp_score, DROP microstructure_score, DROP breakout_readiness_score, DROP structure_score, DROP execution_score');
    }
}
