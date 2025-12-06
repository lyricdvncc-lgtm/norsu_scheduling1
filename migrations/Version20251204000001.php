<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create activity_logs table for tracking system activities
 */
final class Version20251204000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create activity_logs table for tracking all system activities';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE activity_logs (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT DEFAULT NULL,
                action VARCHAR(100) NOT NULL,
                description VARCHAR(255) NOT NULL,
                entity_type VARCHAR(100) DEFAULT NULL,
                entity_id INT DEFAULT NULL,
                metadata JSON DEFAULT NULL,
                ip_address VARCHAR(45) DEFAULT NULL,
                user_agent VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                PRIMARY KEY(id),
                INDEX idx_activity_user (user_id),
                INDEX idx_activity_action (action),
                INDEX idx_activity_created (created_at),
                CONSTRAINT FK_activity_logs_user FOREIGN KEY (user_id) 
                    REFERENCES users (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE activity_logs');
    }
}
