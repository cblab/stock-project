<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260419212500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add deterministic SEPA / Minervi Phase-1 snapshots for instruments.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE instrument_sepa_snapshot (id INT AUTO_INCREMENT NOT NULL, instrument_id INT NOT NULL, as_of_date DATE NOT NULL, market_score DOUBLE PRECISION NOT NULL, stage_score DOUBLE PRECISION NOT NULL, relative_strength_score DOUBLE PRECISION NOT NULL, base_quality_score DOUBLE PRECISION NOT NULL, volume_score DOUBLE PRECISION NOT NULL, momentum_score DOUBLE PRECISION NOT NULL, risk_score DOUBLE PRECISION NOT NULL, superperformance_score DOUBLE PRECISION NOT NULL, total_score DOUBLE PRECISION NOT NULL, traffic_light VARCHAR(16) NOT NULL, kill_triggers_json JSON NOT NULL, detail_json JSON NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_instrument_sepa_as_of (instrument_id, as_of_date), INDEX idx_instrument_sepa_as_of (as_of_date), INDEX idx_instrument_sepa_traffic_light (traffic_light), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE instrument_sepa_snapshot ADD CONSTRAINT FK_INSTRUMENT_SEPA_INSTRUMENT FOREIGN KEY (instrument_id) REFERENCES instrument (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE instrument_sepa_snapshot DROP FOREIGN KEY FK_INSTRUMENT_SEPA_INSTRUMENT');
        $this->addSql('DROP TABLE instrument_sepa_snapshot');
    }
}
