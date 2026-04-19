<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260419202000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track whether pipeline runs were started for portfolio or watchlist.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE pipeline_run ADD run_scope VARCHAR(32) NOT NULL DEFAULT 'portfolio'");
        $this->addSql("ALTER TABLE pipeline_run ALTER run_scope DROP DEFAULT");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pipeline_run DROP run_scope');
    }
}
