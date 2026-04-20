<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260420113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add EPA / Exit & Risk snapshots for instruments.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE instrument_epa_snapshot (id INT AUTO_INCREMENT NOT NULL, instrument_id INT NOT NULL, as_of_date DATE NOT NULL, failure_score DOUBLE PRECISION NOT NULL, trend_exit_score DOUBLE PRECISION NOT NULL, climax_score DOUBLE PRECISION NOT NULL, risk_score DOUBLE PRECISION NOT NULL, total_score DOUBLE PRECISION NOT NULL, action VARCHAR(24) NOT NULL, hard_triggers_json JSON NOT NULL, soft_warnings_json JSON NOT NULL, detail_json JSON NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_instrument_epa_as_of (instrument_id, as_of_date), INDEX idx_instrument_epa_as_of (as_of_date), INDEX idx_instrument_epa_action (action), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE instrument_epa_snapshot ADD CONSTRAINT FK_INSTRUMENT_EPA_INSTRUMENT FOREIGN KEY (instrument_id) REFERENCES instrument (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE instrument_epa_snapshot DROP FOREIGN KEY FK_INSTRUMENT_EPA_INSTRUMENT');
        $this->addSql('DROP TABLE instrument_epa_snapshot');
    }
}
