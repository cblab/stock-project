<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260420170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add cumulative Watchlist Candidate Registry.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE watchlist_candidate_registry (id INT AUTO_INCREMENT NOT NULL, ticker VARCHAR(32) NOT NULL, name VARCHAR(255) DEFAULT NULL, sector_key VARCHAR(64) NOT NULL, sector_label VARCHAR(128) NOT NULL, first_seen_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL, seen_count INT NOT NULL, latest_intake_score DOUBLE PRECISION NOT NULL, best_intake_score DOUBLE PRECISION NOT NULL, latest_status VARCHAR(32) NOT NULL, manual_state VARCHAR(32) DEFAULT NULL, active_candidate TINYINT(1) NOT NULL, latest_reason VARCHAR(255) NOT NULL, latest_buy_signals_json JSON NOT NULL, latest_sepa_json JSON NOT NULL, latest_epa_json JSON NOT NULL, latest_detail_json JSON NOT NULL, latest_run_id INT DEFAULT NULL, latest_candidate_id INT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_watchlist_candidate_registry_ticker (ticker), INDEX idx_watchlist_candidate_registry_status (latest_status), INDEX idx_watchlist_candidate_registry_manual_state (manual_state), INDEX idx_watchlist_candidate_registry_active (active_candidate), INDEX idx_watchlist_candidate_registry_score (latest_intake_score), INDEX idx_watchlist_candidate_registry_last_seen (last_seen_at), INDEX IDX_WATCHLIST_CANDIDATE_REGISTRY_RUN (latest_run_id), INDEX IDX_WATCHLIST_CANDIDATE_REGISTRY_CANDIDATE (latest_candidate_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE watchlist_candidate_registry ADD CONSTRAINT FK_WATCHLIST_CANDIDATE_REGISTRY_RUN FOREIGN KEY (latest_run_id) REFERENCES sector_intake_run (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE watchlist_candidate_registry ADD CONSTRAINT FK_WATCHLIST_CANDIDATE_REGISTRY_CANDIDATE FOREIGN KEY (latest_candidate_id) REFERENCES sector_intake_candidate (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE watchlist_candidate_registry DROP FOREIGN KEY FK_WATCHLIST_CANDIDATE_REGISTRY_RUN');
        $this->addSql('ALTER TABLE watchlist_candidate_registry DROP FOREIGN KEY FK_WATCHLIST_CANDIDATE_REGISTRY_CANDIDATE');
        $this->addSql('DROP TABLE watchlist_candidate_registry');
    }
}
