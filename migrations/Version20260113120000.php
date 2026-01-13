<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to remove Wednesday from day patterns
 * Changes M-T-W-TH-F to M-T-TH-F (Wednesday is now for events only)
 */
final class Version20260113120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove Wednesday from day patterns - change M-T-W-TH-F to M-T-TH-F (Wednesday is now for events)';
    }

    public function up(Schema $schema): void
    {
        // Update any existing M-T-W-TH-F patterns to M-T-TH-F (removing Wednesday)
        $this->addSql("UPDATE schedules SET day_pattern = 'M-T-TH-F' WHERE day_pattern = 'M-T-W-TH-F'");
        
        // Also handle the non-hyphenated version if it exists
        $this->addSql("UPDATE schedules SET day_pattern = 'M-T-TH-F' WHERE day_pattern = 'MTWTHF'");
        
        // Update any audit log entries that reference the old pattern (optional, for data consistency)
        $this->addSql("UPDATE audit_logs SET description = REPLACE(description, 'M-T-W-TH-F', 'M-T-TH-F') WHERE description LIKE '%M-T-W-TH-F%'");
    }

    public function down(Schema $schema): void
    {
        // Revert M-T-TH-F back to M-T-W-TH-F (in case rollback is needed)
        // Note: This may not be desirable as it re-adds Wednesday
        $this->addSql("UPDATE schedules SET day_pattern = 'M-T-W-TH-F' WHERE day_pattern = 'M-T-TH-F'");
        
        // Revert audit log entries
        $this->addSql("UPDATE audit_logs SET description = REPLACE(description, 'M-T-TH-F', 'M-T-W-TH-F') WHERE description LIKE '%M-T-TH-F%'");
    }
}
