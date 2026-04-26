<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260426203000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add canonical forward return columns to instrument SEPA snapshots.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('instrument_sepa_snapshot')) {
            return;
        }

        $table = $schema->getTable('instrument_sepa_snapshot');

        if (!$table->hasColumn('forward_return5d')) {
            $this->addSql('ALTER TABLE instrument_sepa_snapshot ADD forward_return5d DOUBLE PRECISION DEFAULT NULL');
        }

        if (!$table->hasColumn('forward_return20d')) {
            $this->addSql('ALTER TABLE instrument_sepa_snapshot ADD forward_return20d DOUBLE PRECISION DEFAULT NULL');
        }

        if (!$table->hasColumn('forward_return60d')) {
            $this->addSql('ALTER TABLE instrument_sepa_snapshot ADD forward_return60d DOUBLE PRECISION DEFAULT NULL');
        }

        if ($table->hasColumn('forward_return_5d')) {
            $this->addSql('UPDATE instrument_sepa_snapshot SET forward_return5d = forward_return_5d WHERE forward_return5d IS NULL');
        }

        if ($table->hasColumn('forward_return_20d')) {
            $this->addSql('UPDATE instrument_sepa_snapshot SET forward_return20d = forward_return_20d WHERE forward_return20d IS NULL');
        }

        if ($table->hasColumn('forward_return_60d')) {
            $this->addSql('UPDATE instrument_sepa_snapshot SET forward_return60d = forward_return_60d WHERE forward_return60d IS NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('instrument_sepa_snapshot')) {
            return;
        }

        $table = $schema->getTable('instrument_sepa_snapshot');

        if ($table->hasColumn('forward_return5d')) {
            $this->addSql('ALTER TABLE instrument_sepa_snapshot DROP forward_return5d');
        }

        if ($table->hasColumn('forward_return20d')) {
            $this->addSql('ALTER TABLE instrument_sepa_snapshot DROP forward_return20d');
        }

        if ($table->hasColumn('forward_return60d')) {
            $this->addSql('ALTER TABLE instrument_sepa_snapshot DROP forward_return60d');
        }
    }
}
