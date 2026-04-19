<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260419123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create pipeline run and ticker persistence tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE pipeline_run (id INT AUTO_INCREMENT NOT NULL, run_id VARCHAR(64) NOT NULL, run_path VARCHAR(1024) NOT NULL, started_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, data_frequency VARCHAR(32) DEFAULT NULL, horizon_steps INT DEFAULT NULL, horizon_label VARCHAR(128) DEFAULT NULL, score_validity_hours INT DEFAULT NULL, summary_generated TINYINT(1) NOT NULL, notes LONGTEXT DEFAULT NULL, decision_entry_count INT NOT NULL, decision_watch_count INT NOT NULL, decision_hold_count INT NOT NULL, decision_no_trade_count INT NOT NULL, score_min DOUBLE PRECISION DEFAULT NULL, score_max DOUBLE PRECISION DEFAULT NULL, score_mean DOUBLE PRECISION DEFAULT NULL, score_median DOUBLE PRECISION DEFAULT NULL, UNIQUE INDEX uniq_pipeline_run_run_id (run_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE pipeline_ticker (id INT AUTO_INCREMENT NOT NULL, pipeline_run_id INT NOT NULL, input_ticker VARCHAR(32) NOT NULL, provider_ticker VARCHAR(64) NOT NULL, display_ticker VARCHAR(32) NOT NULL, asset_class VARCHAR(32) NOT NULL, region VARCHAR(32) DEFAULT NULL, sentiment_mode VARCHAR(64) DEFAULT NULL, mapping_status VARCHAR(64) DEFAULT NULL, mapping_note LONGTEXT DEFAULT NULL, market_data_status VARCHAR(64) DEFAULT NULL, news_status VARCHAR(64) DEFAULT NULL, kronos_status VARCHAR(64) DEFAULT NULL, sentiment_status VARCHAR(64) DEFAULT NULL, kronos_direction VARCHAR(64) DEFAULT NULL, kronos_raw_score DOUBLE PRECISION DEFAULT NULL, kronos_normalized_score DOUBLE PRECISION DEFAULT NULL, sentiment_label VARCHAR(64) DEFAULT NULL, sentiment_raw_score DOUBLE PRECISION DEFAULT NULL, sentiment_normalized_score DOUBLE PRECISION DEFAULT NULL, sentiment_confidence DOUBLE PRECISION DEFAULT NULL, sentiment_backend VARCHAR(128) DEFAULT NULL, merged_score DOUBLE PRECISION DEFAULT NULL, decision VARCHAR(64) NOT NULL, explain_json JSON NOT NULL, created_at DATETIME NOT NULL, INDEX IDX_980B2179622BD72B (pipeline_run_id), INDEX idx_pipeline_ticker_decision (decision), INDEX idx_pipeline_ticker_asset_class (asset_class), INDEX idx_pipeline_ticker_sentiment_mode (sentiment_mode), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE pipeline_ticker ADD CONSTRAINT FK_PIPELINE_TICKER_RUN FOREIGN KEY (pipeline_run_id) REFERENCES pipeline_run (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pipeline_ticker DROP FOREIGN KEY FK_PIPELINE_TICKER_RUN');
        $this->addSql('DROP TABLE pipeline_ticker');
        $this->addSql('DROP TABLE pipeline_run');
    }
}
