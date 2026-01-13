<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to update day patterns from non-hyphenated to hyphenated format
 * Example: MWF -> M-W-F, TTH -> T-TH, MW -> M-W
 */
final class Version20251222120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update day patterns to use hyphenated format (MWF -> M-W-F, TTH -> T-TH, etc.)';
    }

    public function up(Schema $schema): void
    {
        // Update existing schedules to use hyphenated day patterns
        $this->addSql("UPDATE schedules SET day_pattern = 'M-W-F' WHERE day_pattern = 'MWF'");
        $this->addSql("UPDATE schedules SET day_pattern = 'T-TH' WHERE day_pattern = 'TTH'");
        $this->addSql("UPDATE schedules SET day_pattern = 'M-T-TH-F' WHERE day_pattern = 'MTWTHF'");
        $this->addSql("UPDATE schedules SET day_pattern = 'M-W' WHERE day_pattern = 'MW'");
        $this->addSql("UPDATE schedules SET day_pattern = 'W-F' WHERE day_pattern = 'WF'");
        $this->addSql("UPDATE schedules SET day_pattern = 'M-TH' WHERE day_pattern = 'MTH'");
        $this->addSql("UPDATE schedules SET day_pattern = 'T-F' WHERE day_pattern = 'TF'");
        
        // SAT and SUN remain unchanged as they are single-day patterns
    }

    public function down(Schema $schema): void
    {
        // Revert to non-hyphenated format
        $this->addSql("UPDATE schedules SET day_pattern = 'MWF' WHERE day_pattern = 'M-W-F'");
        $this->addSql("UPDATE schedules SET day_pattern = 'TTH' WHERE day_pattern = 'T-TH'");
        $this->addSql("UPDATE schedules SET day_pattern = 'MTTHF' WHERE day_pattern = 'M-T-TH-F'");
        $this->addSql("UPDATE schedules SET day_pattern = 'MW' WHERE day_pattern = 'M-W'");
        $this->addSql("UPDATE schedules SET day_pattern = 'WF' WHERE day_pattern = 'W-F'");
        $this->addSql("UPDATE schedules SET day_pattern = 'MTH' WHERE day_pattern = 'M-TH'");
        $this->addSql("UPDATE schedules SET day_pattern = 'TF' WHERE day_pattern = 'T-F'");
        $this->addSql("UPDATE schedules SET day_pattern = 'MW' WHERE day_pattern = 'M-T'");
        $this->addSql("UPDATE schedules SET day_pattern = 'TF' WHERE day_pattern = 'TH-F'");
    }
}
