<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260424110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add forward_return_5d, forward_return_20d, forward_return_60d to instrument_sepa_snapshot';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE instrument_sepa_snapshot ADD forward_return_5d DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE instrument_sepa_snapshot ADD forward_return_20d DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE instrument_sepa_snapshot ADD forward_return_60d DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE instrument_sepa_snapshot DROP forward_return_5d');
        $this->addSql('ALTER TABLE instrument_sepa_snapshot DROP forward_return_20d');
        $this->addSql('ALTER TABLE instrument_sepa_snapshot DROP forward_return_60d');
    }
}
