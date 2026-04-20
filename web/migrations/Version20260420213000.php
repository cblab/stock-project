<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260420213000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add intake and run hot-path indexes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_sector_intake_candidate_ticker_created_id ON sector_intake_candidate (ticker, created_at, id)');
        $this->addSql('CREATE INDEX idx_watchlist_candidate_registry_active_seen ON watchlist_candidate_registry (active_candidate, last_seen_at, id)');
        $this->addSql('CREATE INDEX idx_pipeline_run_created_id ON pipeline_run (created_at, id)');
        $this->addSql('CREATE INDEX idx_pipeline_run_item_instrument_run_id ON pipeline_run_item (instrument_id, pipeline_run_id, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_pipeline_run_item_instrument_run_id ON pipeline_run_item');
        $this->addSql('DROP INDEX idx_pipeline_run_created_id ON pipeline_run');
        $this->addSql('DROP INDEX idx_watchlist_candidate_registry_active_seen ON watchlist_candidate_registry');
        $this->addSql('DROP INDEX idx_sector_intake_candidate_ticker_created_id ON sector_intake_candidate');
    }
}
