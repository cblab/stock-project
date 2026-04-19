<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260419162000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Introduce DB-first instruments and relational pipeline run items.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE pipeline_run ADD run_key VARCHAR(64) NOT NULL DEFAULT '', ADD status VARCHAR(32) NOT NULL DEFAULT 'completed', ADD finished_at DATETIME DEFAULT NULL");
        $this->addSql("UPDATE pipeline_run SET run_key = run_id WHERE run_key = ''");
        $this->addSql("ALTER TABLE pipeline_run ALTER run_key DROP DEFAULT, ALTER status DROP DEFAULT");
        $this->addSql('CREATE UNIQUE INDEX uniq_pipeline_run_run_key ON pipeline_run (run_key)');

        $this->addSql("CREATE TABLE instrument (id INT AUTO_INCREMENT NOT NULL, input_ticker VARCHAR(32) NOT NULL, provider_ticker VARCHAR(64) NOT NULL, display_ticker VARCHAR(32) NOT NULL, name VARCHAR(255) DEFAULT NULL, wkn VARCHAR(32) DEFAULT NULL, isin VARCHAR(32) DEFAULT NULL, asset_class VARCHAR(32) NOT NULL, region VARCHAR(32) DEFAULT NULL, benchmark VARCHAR(255) DEFAULT NULL, active TINYINT(1) NOT NULL, is_portfolio TINYINT(1) NOT NULL, mapping_status VARCHAR(64) DEFAULT NULL, mapping_note LONGTEXT DEFAULT NULL, context_type VARCHAR(128) DEFAULT NULL, region_exposure JSON NOT NULL, sector_profile JSON NOT NULL, top_holdings_profile JSON NOT NULL, macro_profile JSON NOT NULL, direct_news_weight DOUBLE PRECISION DEFAULT NULL, context_news_weight DOUBLE PRECISION DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_instrument_input_ticker (input_ticker), INDEX idx_instrument_is_portfolio (is_portfolio), INDEX idx_instrument_active (active), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE pipeline_run_item (id INT AUTO_INCREMENT NOT NULL, pipeline_run_id INT NOT NULL, instrument_id INT NOT NULL, sentiment_mode VARCHAR(64) DEFAULT NULL, market_data_status VARCHAR(64) DEFAULT NULL, news_status VARCHAR(64) DEFAULT NULL, kronos_status VARCHAR(64) DEFAULT NULL, sentiment_status VARCHAR(64) DEFAULT NULL, kronos_direction VARCHAR(64) DEFAULT NULL, kronos_raw_score DOUBLE PRECISION DEFAULT NULL, kronos_normalized_score DOUBLE PRECISION DEFAULT NULL, sentiment_label VARCHAR(64) DEFAULT NULL, sentiment_raw_score DOUBLE PRECISION DEFAULT NULL, sentiment_normalized_score DOUBLE PRECISION DEFAULT NULL, sentiment_confidence DOUBLE PRECISION DEFAULT NULL, sentiment_backend VARCHAR(128) DEFAULT NULL, merged_score DOUBLE PRECISION DEFAULT NULL, decision VARCHAR(64) NOT NULL, explain_json JSON NOT NULL, created_at DATETIME NOT NULL, INDEX IDX_18B1EBBD622BD72B (pipeline_run_id), INDEX IDX_18B1EBBDCF11D9C (instrument_id), INDEX idx_pipeline_run_item_decision (decision), INDEX idx_pipeline_run_item_sentiment_mode (sentiment_mode), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE pipeline_run_item_news (id INT AUTO_INCREMENT NOT NULL, pipeline_run_item_id INT NOT NULL, source VARCHAR(128) DEFAULT NULL, published_at DATETIME DEFAULT NULL, headline LONGTEXT NOT NULL, snippet LONGTEXT DEFAULT NULL, article_sentiment_label VARCHAR(64) DEFAULT NULL, article_sentiment_confidence DOUBLE PRECISION DEFAULT NULL, relevance VARCHAR(32) DEFAULT NULL, context_kind VARCHAR(64) DEFAULT NULL, raw_payload JSON NOT NULL, INDEX IDX_EF58EFA9AA2B0156 (pipeline_run_item_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE pipeline_run_item ADD CONSTRAINT FK_RUN_ITEM_RUN FOREIGN KEY (pipeline_run_id) REFERENCES pipeline_run (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pipeline_run_item ADD CONSTRAINT FK_RUN_ITEM_INSTRUMENT FOREIGN KEY (instrument_id) REFERENCES instrument (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pipeline_run_item_news ADD CONSTRAINT FK_RUN_ITEM_NEWS_ITEM FOREIGN KEY (pipeline_run_item_id) REFERENCES pipeline_run_item (id) ON DELETE CASCADE');

        $this->addSql("INSERT IGNORE INTO instrument (input_ticker, provider_ticker, display_ticker, name, wkn, isin, asset_class, region, benchmark, active, is_portfolio, mapping_status, mapping_note, context_type, region_exposure, sector_profile, top_holdings_profile, macro_profile, direct_news_weight, context_news_weight, created_at, updated_at) SELECT pt.input_ticker, pt.provider_ticker, pt.display_ticker, NULL, NULL, NULL, pt.asset_class, pt.region, JSON_UNQUOTE(JSON_EXTRACT(pt.explain_json, '$.benchmark')), 1, 1, pt.mapping_status, pt.mapping_note, JSON_UNQUOTE(JSON_EXTRACT(pt.explain_json, '$.context_type')), COALESCE(JSON_EXTRACT(pt.explain_json, '$.region_exposure'), JSON_ARRAY()), COALESCE(JSON_EXTRACT(pt.explain_json, '$.sector_profile'), JSON_ARRAY()), COALESCE(JSON_EXTRACT(pt.explain_json, '$.top_holdings_profile'), JSON_ARRAY()), COALESCE(JSON_EXTRACT(pt.explain_json, '$.macro_profile'), JSON_ARRAY()), JSON_EXTRACT(pt.explain_json, '$.direct_news_weight'), JSON_EXTRACT(pt.explain_json, '$.context_news_weight'), NOW(), NOW() FROM pipeline_ticker pt");
        $this->addSql("INSERT INTO pipeline_run_item (pipeline_run_id, instrument_id, sentiment_mode, market_data_status, news_status, kronos_status, sentiment_status, kronos_direction, kronos_raw_score, kronos_normalized_score, sentiment_label, sentiment_raw_score, sentiment_normalized_score, sentiment_confidence, sentiment_backend, merged_score, decision, explain_json, created_at) SELECT pt.pipeline_run_id, i.id, pt.sentiment_mode, pt.market_data_status, pt.news_status, pt.kronos_status, pt.sentiment_status, pt.kronos_direction, pt.kronos_raw_score, pt.kronos_normalized_score, pt.sentiment_label, pt.sentiment_raw_score, pt.sentiment_normalized_score, pt.sentiment_confidence, pt.sentiment_backend, pt.merged_score, pt.decision, pt.explain_json, pt.created_at FROM pipeline_ticker pt INNER JOIN instrument i ON i.input_ticker = pt.input_ticker");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pipeline_run_item_news DROP FOREIGN KEY FK_RUN_ITEM_NEWS_ITEM');
        $this->addSql('ALTER TABLE pipeline_run_item DROP FOREIGN KEY FK_RUN_ITEM_RUN');
        $this->addSql('ALTER TABLE pipeline_run_item DROP FOREIGN KEY FK_RUN_ITEM_INSTRUMENT');
        $this->addSql('DROP TABLE pipeline_run_item_news');
        $this->addSql('DROP TABLE pipeline_run_item');
        $this->addSql('DROP TABLE instrument');
        $this->addSql('DROP INDEX uniq_pipeline_run_run_key ON pipeline_run');
        $this->addSql('ALTER TABLE pipeline_run DROP run_key, DROP status, DROP finished_at');
    }
}
