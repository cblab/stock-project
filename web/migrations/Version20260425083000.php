<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260425083000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add master data fields (wkn, isin, region, master_data_status, master_data_source, master_data_note) to watchlist_candidate_registry.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE watchlist_candidate_registry ADD wkn VARCHAR(32) DEFAULT NULL, ADD isin VARCHAR(32) DEFAULT NULL, ADD region VARCHAR(32) DEFAULT NULL, ADD master_data_status VARCHAR(32) DEFAULT NULL, ADD master_data_source VARCHAR(64) DEFAULT NULL, ADD master_data_note TEXT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_watchlist_candidate_registry_master_data_status ON watchlist_candidate_registry (master_data_status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_watchlist_candidate_registry_master_data_status ON watchlist_candidate_registry');
        $this->addSql('ALTER TABLE watchlist_candidate_registry DROP wkn, DROP isin, DROP region, DROP master_data_status, DROP master_data_source, DROP master_data_note');
    }
}
