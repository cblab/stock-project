<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260420152000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Sector Discovery and Watchlist Intake run history.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE sector_intake_run (id INT AUTO_INCREMENT NOT NULL, run_key VARCHAR(64) NOT NULL, status VARCHAR(32) NOT NULL, mode VARCHAR(32) NOT NULL, dry_run TINYINT(1) NOT NULL, config_json JSON NOT NULL, summary_json JSON DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_sector_intake_run_key (run_key), INDEX idx_sector_intake_run_created_at (created_at), INDEX idx_sector_intake_run_status (status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE sector_intake_sector (id INT AUTO_INCREMENT NOT NULL, run_id INT NOT NULL, sector_key VARCHAR(64) NOT NULL, sector_label VARCHAR(128) NOT NULL, proxy_ticker VARCHAR(32) NOT NULL, sector_rank INT NOT NULL, sector_score DOUBLE PRECISION NOT NULL, return_1m_pct DOUBLE PRECISION NOT NULL, return_3m_pct DOUBLE PRECISION NOT NULL, relative_1m_pct DOUBLE PRECISION NOT NULL, relative_3m_pct DOUBLE PRECISION NOT NULL, detail_json JSON NOT NULL, created_at DATETIME NOT NULL, INDEX IDX_SECTOR_INTAKE_SECTOR_RUN (run_id), INDEX idx_sector_intake_sector_rank (sector_rank), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE sector_intake_candidate (id INT AUTO_INCREMENT NOT NULL, run_id INT NOT NULL, ticker VARCHAR(32) NOT NULL, sector_key VARCHAR(64) NOT NULL, sector_label VARCHAR(128) NOT NULL, sector_rank INT NOT NULL, candidate_rank INT NOT NULL, status VARCHAR(32) NOT NULL, intake_score DOUBLE PRECISION NOT NULL, added_to_watchlist TINYINT(1) NOT NULL, manual_action VARCHAR(32) DEFAULT NULL, reason VARCHAR(255) NOT NULL, hard_checks_json JSON NOT NULL, detail_json JSON NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, INDEX IDX_SECTOR_INTAKE_CANDIDATE_RUN (run_id), INDEX idx_sector_intake_candidate_status (status), INDEX idx_sector_intake_candidate_added (added_to_watchlist), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE sector_intake_sector ADD CONSTRAINT FK_SECTOR_INTAKE_SECTOR_RUN FOREIGN KEY (run_id) REFERENCES sector_intake_run (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sector_intake_candidate ADD CONSTRAINT FK_SECTOR_INTAKE_CANDIDATE_RUN FOREIGN KEY (run_id) REFERENCES sector_intake_run (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sector_intake_candidate DROP FOREIGN KEY FK_SECTOR_INTAKE_CANDIDATE_RUN');
        $this->addSql('ALTER TABLE sector_intake_sector DROP FOREIGN KEY FK_SECTOR_INTAKE_SECTOR_RUN');
        $this->addSql('DROP TABLE sector_intake_candidate');
        $this->addSql('DROP TABLE sector_intake_sector');
        $this->addSql('DROP TABLE sector_intake_run');
    }
}
