<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add per-semester start/end date columns to academic_years table
 * for automatic semester transition support.
 */
final class Version20260219120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add per-semester start/end date columns to academic_years for auto-transition';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE academic_years ADD first_sem_start DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE academic_years ADD first_sem_end DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE academic_years ADD second_sem_start DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE academic_years ADD second_sem_end DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE academic_years ADD summer_start DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE academic_years ADD summer_end DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE academic_years DROP COLUMN first_sem_start');
        $this->addSql('ALTER TABLE academic_years DROP COLUMN first_sem_end');
        $this->addSql('ALTER TABLE academic_years DROP COLUMN second_sem_start');
        $this->addSql('ALTER TABLE academic_years DROP COLUMN second_sem_end');
        $this->addSql('ALTER TABLE academic_years DROP COLUMN summer_start');
        $this->addSql('ALTER TABLE academic_years DROP COLUMN summer_end');
    }
}
