<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260421211000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add pragmatic run diagnostics for pipeline and sector intake jobs.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pipeline_run ADD exit_code INT DEFAULT NULL, ADD stdout_log_path VARCHAR(1024) DEFAULT NULL, ADD stderr_log_path VARCHAR(1024) DEFAULT NULL, ADD error_summary VARCHAR(512) DEFAULT NULL');
        $this->addSql('ALTER TABLE sector_intake_run ADD exit_code INT DEFAULT NULL, ADD stdout_log_path VARCHAR(1024) DEFAULT NULL, ADD stderr_log_path VARCHAR(1024) DEFAULT NULL, ADD error_summary VARCHAR(512) DEFAULT NULL');
        $this->addSql("UPDATE pipeline_run SET status = 'success' WHERE status = 'completed'");
        $this->addSql("UPDATE sector_intake_run SET status = 'success' WHERE status = 'completed'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE pipeline_run SET status = 'completed' WHERE status = 'success'");
        $this->addSql("UPDATE sector_intake_run SET status = 'completed' WHERE status = 'success'");
        $this->addSql('ALTER TABLE sector_intake_run DROP exit_code, DROP stdout_log_path, DROP stderr_log_path, DROP error_summary');
        $this->addSql('ALTER TABLE pipeline_run DROP exit_code, DROP stdout_log_path, DROP stderr_log_path, DROP error_summary');
    }
}
