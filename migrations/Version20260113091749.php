<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to add new daily pattern M-T-TH-F (Monday, Tuesday, Thursday, Friday)
 * This pattern supports daily classes that skip Wednesday for university activities
 */
final class Version20260113091749 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add new daily pattern M-T-TH-F (Mon-Tue-Thu-Fri) for schedules that skip Wednesday';
    }

    public function up(Schema $schema): void
    {
        // This migration adds support for the new M-T-TH-F pattern
        // No schema changes needed as day_pattern is already a VARCHAR(255)
        // The new pattern will be available in the application immediately
        
        $this->addSql('-- New pattern M-T-TH-F now supported: Monday, Tuesday, Thursday, Friday (skips Wednesday)');
    }

    public function down(Schema $schema): void
    {
        // Convert any M-T-TH-F schedules to either M-T or TH-F
        // Split the daily pattern into two separate patterns
        $this->addSql("-- Note: Manual review required for any M-T-TH-F schedules before reverting");
    }
}
