<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260420205000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normalize Watchlist Candidate lifecycle states.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE watchlist_candidate_registry SET manual_state = 'REJECTED', active_candidate = 0, latest_reason = 'manual_dismiss' WHERE manual_state = 'DISMISSED'");
        $this->addSql("UPDATE watchlist_candidate_registry SET manual_state = NULL, active_candidate = 1, latest_reason = 'manual_later_state_removed' WHERE manual_state = 'RECHECK_LATER'");
        $this->addSql("UPDATE sector_intake_candidate SET status = 'REJECTED', reason = 'manual_dismiss' WHERE status = 'DISMISSED' OR manual_action = 'dismiss'");
        $this->addSql("UPDATE sector_intake_candidate SET manual_action = NULL, reason = 'manual_later_state_removed' WHERE status = 'RECHECK_LATER' OR manual_action = 'recheck'");
    }

    public function down(Schema $schema): void
    {
    }
}
