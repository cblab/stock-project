<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260426043300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create trade_campaign, trade_event, trade_migration_log tables for v0.4 Truth Layer.';
    }

    public function up(Schema $schema): void
    {
        // Table 1: trade_campaign
        $this->addSql('CREATE TABLE trade_campaign (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            instrument_id INT NOT NULL,
            trade_type ENUM(\'live\', \'paper\', \'pseudo\') NOT NULL DEFAULT \'live\',
            state ENUM(\'open\', \'trimmed\', \'paused\', \'closed_profit\', \'closed_loss\', \'closed_neutral\', \'returned_to_watchlist\') NOT NULL DEFAULT \'open\',
            entry_thesis TEXT NULL,
            invalidation_rule TEXT NULL,
            outcome_tag ENUM(\'good_entry_bad_exit\', \'bad_entry_good_exit\', \'good_entry_good_exit\', \'bad_entry_bad_exit\', \'macro_headwind\', \'signal_conflict_loss\', \'stop_loss_exit\', \'time_based_exit\', \'opportunity_cost_exit\') NULL DEFAULT NULL,
            total_quantity DECIMAL(18,6) NULL,
            open_quantity DECIMAL(18,6) NULL,
            avg_entry_price DECIMAL(18,6) NULL,
            realized_pnl_gross DECIMAL(18,4) NULL COMMENT \'Brutto P&L in Basiswährung\',
            realized_pnl_net DECIMAL(18,4) NULL COMMENT \'Netto nach Steuern, NULL wenn unbekannt\',
            tax_rate_applied DECIMAL(6,4) NULL COMMENT \'z.B. 0.26375 für DE\',
            realized_pnl_pct DECIMAL(10,6) NULL COMMENT \'Prozentuale Performance brutto\',
            opened_at DATETIME NOT NULL,
            closed_at DATETIME NULL,
            entry_macro_snapshot_id BIGINT UNSIGNED NULL,
            exit_macro_snapshot_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_tc_instrument (instrument_id),
            INDEX idx_tc_state (state),
            INDEX idx_tc_trade_type (trade_type),
            INDEX idx_tc_opened_at (opened_at),
            CONSTRAINT fk_tc_instrument FOREIGN KEY (instrument_id) REFERENCES instrument(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        // Table 2: trade_event
        // Hinweis: event_price und quantity bleiben nullable, da pause/resume/migration_seed
        // keine zwingenden echten Ausführungen sind.
        $this->addSql('CREATE TABLE trade_event (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            trade_campaign_id BIGINT UNSIGNED NOT NULL,
            instrument_id INT NOT NULL,
            event_type ENUM(\'entry\', \'add\', \'trim\', \'pause\', \'resume\', \'hard_exit\', \'return_to_watchlist\', \'migration_seed\') NOT NULL,
            exit_reason ENUM(\'signal\', \'stop_loss\', \'trailing_stop\', \'time_based\', \'rebalance\', \'opportunity_cost\', \'macro_regime_change\', \'thesis_invalidated\', \'manual\') NULL DEFAULT NULL,
            event_price DECIMAL(18,6) NULL,
            quantity DECIMAL(18,6) NULL,
            fees DECIMAL(10,4) NOT NULL DEFAULT 0.00,
            currency CHAR(3) NOT NULL DEFAULT \'EUR\',
            event_timestamp DATETIME NOT NULL,
            buy_signal_snapshot_id BIGINT UNSIGNED NULL,
            sepa_snapshot_id INT NULL,
            epa_snapshot_id INT NULL,
            macro_snapshot_id BIGINT UNSIGNED NULL,
            scoring_version VARCHAR(32) NULL,
            policy_version VARCHAR(32) NULL,
            model_version VARCHAR(32) NULL,
            macro_version VARCHAR(32) NULL,
            event_notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_te_campaign (trade_campaign_id),
            INDEX idx_te_instrument (instrument_id),
            INDEX idx_te_type (event_type),
            INDEX idx_te_timestamp (event_timestamp),
            CONSTRAINT fk_te_campaign FOREIGN KEY (trade_campaign_id) REFERENCES trade_campaign(id),
            CONSTRAINT fk_te_instrument FOREIGN KEY (instrument_id) REFERENCES instrument(id),
            CONSTRAINT fk_te_buy_signal_snapshot FOREIGN KEY (buy_signal_snapshot_id) REFERENCES instrument_buy_signal_snapshot(id),
            CONSTRAINT fk_te_sepa_snapshot FOREIGN KEY (sepa_snapshot_id) REFERENCES instrument_sepa_snapshot(id),
            CONSTRAINT fk_te_epa_snapshot FOREIGN KEY (epa_snapshot_id) REFERENCES instrument_epa_snapshot(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        // Table 3: trade_migration_log
        $this->addSql('CREATE TABLE trade_migration_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            instrument_id INT NOT NULL,
            trade_campaign_id BIGINT UNSIGNED NOT NULL,
            migration_status ENUM(\'full\', \'partial\', \'manual_seed\') NOT NULL,
            migration_notes TEXT NULL,
            migrated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_tml_instrument (instrument_id),
            INDEX idx_tml_campaign (trade_campaign_id),
            INDEX idx_tml_status (migration_status),
            INDEX idx_tml_migrated_at (migrated_at),
            CONSTRAINT fk_tml_instrument FOREIGN KEY (instrument_id) REFERENCES instrument(id),
            CONSTRAINT fk_tml_campaign FOREIGN KEY (trade_campaign_id) REFERENCES trade_campaign(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE trade_migration_log');
        $this->addSql('DROP TABLE trade_event');
        $this->addSql('DROP TABLE trade_campaign');
    }
}
