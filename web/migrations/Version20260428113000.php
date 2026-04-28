<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260428113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable run provenance and availability timestamps to evidence snapshot tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE instrument_buy_signal_snapshot ADD source_run_id BIGINT UNSIGNED DEFAULT NULL, ADD available_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE instrument_buy_signal_snapshot ADD CONSTRAINT FK_BUY_SIGNAL_SOURCE_RUN FOREIGN KEY (source_run_id) REFERENCES pipeline_run (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_BUY_SIGNAL_SOURCE_RUN ON instrument_buy_signal_snapshot (source_run_id)');
        $this->addSql('CREATE INDEX IDX_BUY_SIGNAL_AVAILABLE_AT ON instrument_buy_signal_snapshot (available_at)');

        $this->addSql('ALTER TABLE instrument_sepa_snapshot ADD source_run_id BIGINT UNSIGNED DEFAULT NULL, ADD available_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE instrument_sepa_snapshot ADD CONSTRAINT FK_SEPA_SOURCE_RUN FOREIGN KEY (source_run_id) REFERENCES pipeline_run (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_SEPA_SOURCE_RUN ON instrument_sepa_snapshot (source_run_id)');
        $this->addSql('CREATE INDEX IDX_SEPA_AVAILABLE_AT ON instrument_sepa_snapshot (available_at)');

        $this->addSql('ALTER TABLE instrument_epa_snapshot ADD source_run_id BIGINT UNSIGNED DEFAULT NULL, ADD available_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE instrument_epa_snapshot ADD CONSTRAINT FK_EPA_SOURCE_RUN FOREIGN KEY (source_run_id) REFERENCES pipeline_run (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_EPA_SOURCE_RUN ON instrument_epa_snapshot (source_run_id)');
        $this->addSql('CREATE INDEX IDX_EPA_AVAILABLE_AT ON instrument_epa_snapshot (available_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE instrument_buy_signal_snapshot DROP FOREIGN KEY FK_BUY_SIGNAL_SOURCE_RUN');
        $this->addSql('DROP INDEX IDX_BUY_SIGNAL_SOURCE_RUN ON instrument_buy_signal_snapshot');
        $this->addSql('DROP INDEX IDX_BUY_SIGNAL_AVAILABLE_AT ON instrument_buy_signal_snapshot');
        $this->addSql('ALTER TABLE instrument_buy_signal_snapshot DROP source_run_id, DROP available_at');

        $this->addSql('ALTER TABLE instrument_sepa_snapshot DROP FOREIGN KEY FK_SEPA_SOURCE_RUN');
        $this->addSql('DROP INDEX IDX_SEPA_SOURCE_RUN ON instrument_sepa_snapshot');
        $this->addSql('DROP INDEX IDX_SEPA_AVAILABLE_AT ON instrument_sepa_snapshot');
        $this->addSql('ALTER TABLE instrument_sepa_snapshot DROP source_run_id, DROP available_at');

        $this->addSql('ALTER TABLE instrument_epa_snapshot DROP FOREIGN KEY FK_EPA_SOURCE_RUN');
        $this->addSql('DROP INDEX IDX_EPA_SOURCE_RUN ON instrument_epa_snapshot');
        $this->addSql('DROP INDEX IDX_EPA_AVAILABLE_AT ON instrument_epa_snapshot');
        $this->addSql('ALTER TABLE instrument_epa_snapshot DROP source_run_id, DROP available_at');
    }
}
