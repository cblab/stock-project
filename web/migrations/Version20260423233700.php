<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260423233700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create instrument_price_history table for OHLCV data';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE instrument_price_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                instrument_id INT NOT NULL,
                price_date DATE NOT NULL,
                open_price DECIMAL(15, 4) NULL,
                high_price DECIMAL(15, 4) NULL,
                low_price DECIMAL(15, 4) NULL,
                close_price DECIMAL(15, 4) NULL,
                adj_close DECIMAL(15, 4) NULL,
                volume BIGINT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE INDEX uniq_instrument_price_date (instrument_id, price_date),
                INDEX idx_price_date (price_date),
                CONSTRAINT fk_price_history_instrument
                    FOREIGN KEY (instrument_id)
                    REFERENCES instrument (id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE instrument_price_history');
    }
}
