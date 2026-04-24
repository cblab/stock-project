<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260424161100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create instrument_buy_signal_snapshot table for historical K, S, M scores';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE instrument_buy_signal_snapshot (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                instrument_id BIGINT UNSIGNED NOT NULL,
                as_of_date DATE NOT NULL,
                kronos_score DECIMAL(10,6) NULL,
                sentiment_score DECIMAL(10,6) NULL,
                merged_score DECIMAL(10,6) NULL,
                decision VARCHAR(20) NULL,
                sentiment_label VARCHAR(20) NULL,
                kronos_raw_score DECIMAL(10,6) NULL,
                sentiment_raw_score DECIMAL(10,6) NULL,
                detail_json LONGTEXT NULL,
                forward_return_5d DECIMAL(10,4) NULL,
                forward_return_20d DECIMAL(10,4) NULL,
                forward_return_60d DECIMAL(10,4) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE INDEX uk_instrument_date (instrument_id, as_of_date),
                INDEX idx_as_of_date (as_of_date),
                INDEX idx_merged_score (merged_score),
                INDEX idx_decision (decision),
                CONSTRAINT fk_buy_signal_instrument
                    FOREIGN KEY (instrument_id)
                    REFERENCES instrument (id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE instrument_buy_signal_snapshot');
    }
}
