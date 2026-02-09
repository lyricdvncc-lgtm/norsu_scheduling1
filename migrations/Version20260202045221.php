<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260202045221 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE academic_years (id BIGINT AUTO_INCREMENT NOT NULL, year VARCHAR(255) NOT NULL, start_date DATE DEFAULT NULL, end_date DATE DEFAULT NULL, is_current TINYINT(1) DEFAULT NULL, current_semester VARCHAR(20) DEFAULT NULL, is_active TINYINT(1) DEFAULT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE activity_logs (id INT AUTO_INCREMENT NOT NULL, user_id INT DEFAULT NULL, action VARCHAR(100) NOT NULL, description VARCHAR(255) NOT NULL, entity_type VARCHAR(100) DEFAULT NULL, entity_id INT DEFAULT NULL, metadata JSON DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_activity_user (user_id), INDEX idx_activity_action (action), INDEX idx_activity_created (created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE colleges (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(10) NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, dean VARCHAR(255) DEFAULT NULL, logo VARCHAR(255) DEFAULT NULL, is_active TINYINT(1) DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_F5AA74A077153098 (code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE curricula (id BIGINT AUTO_INCREMENT NOT NULL, department_id INT NOT NULL, name VARCHAR(255) NOT NULL, version INT DEFAULT NULL, is_published TINYINT(1) DEFAULT NULL, effective_year_id BIGINT DEFAULT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, INDEX IDX_463CC9FCAE80F5DF (department_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE curriculum_subjects (id INT AUTO_INCREMENT NOT NULL, curriculum_id BIGINT NOT NULL, curriculum_term_id INT NOT NULL, subject_id BIGINT NOT NULL, sections_mapping JSON DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX IDX_51FD4753D9CF12E2 (curriculum_term_id), INDEX IDX_51FD475323EDC87 (subject_id), INDEX idx_curriculum_subject (curriculum_id), UNIQUE INDEX unique_term_subject (curriculum_term_id, subject_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE curriculum_terms (id INT AUTO_INCREMENT NOT NULL, curriculum_id BIGINT NOT NULL, year_level INT NOT NULL, semester VARCHAR(10) NOT NULL, term_name VARCHAR(100) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX IDX_7333F2735AEA4428 (curriculum_id), INDEX idx_curriculum_term (curriculum_id, year_level, semester), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE department_groups (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description VARCHAR(500) DEFAULT NULL, color VARCHAR(7) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE rooms (id BIGINT AUTO_INCREMENT NOT NULL, department_id INT NOT NULL, department_group_id INT DEFAULT NULL, code VARCHAR(255) NOT NULL, name VARCHAR(255) DEFAULT NULL, type VARCHAR(50) DEFAULT NULL, capacity INT DEFAULT NULL, building VARCHAR(255) DEFAULT NULL, floor VARCHAR(255) DEFAULT NULL, equipment LONGTEXT DEFAULT NULL, is_active TINYINT(1) DEFAULT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, INDEX IDX_7CA11A96AE80F5DF (department_id), INDEX IDX_7CA11A96C7EAC36D (department_group_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE schedules (id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, academic_year_id BIGINT NOT NULL, subject_id BIGINT NOT NULL, room_id BIGINT NOT NULL, faculty_id INT DEFAULT NULL, semester VARCHAR(10) NOT NULL, day_pattern VARCHAR(255) DEFAULT NULL, start_time TIME NOT NULL, end_time TIME NOT NULL, section VARCHAR(255) DEFAULT NULL, enrolled_students INT DEFAULT 0 NOT NULL, is_conflicted TINYINT(1) DEFAULT 0 NOT NULL, is_overload TINYINT(1) DEFAULT 0 NOT NULL, status VARCHAR(20) DEFAULT \'active\' NOT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, INDEX IDX_313BDC8EC54F3401 (academic_year_id), INDEX IDX_313BDC8E23EDC87 (subject_id), INDEX IDX_313BDC8E54177093 (room_id), INDEX IDX_313BDC8E680CAB68 (faculty_id), INDEX schedules_conflict_check_index (room_id, day_pattern, start_time, end_time), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE subjects (id BIGINT AUTO_INCREMENT NOT NULL, department_id INT NOT NULL, code VARCHAR(255) NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, units INT NOT NULL, lecture_hours INT DEFAULT NULL, lab_hours INT DEFAULT NULL, prerequisite VARCHAR(255) DEFAULT NULL, type VARCHAR(50) NOT NULL, is_active TINYINT(1) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, year_level INT DEFAULT NULL, semester VARCHAR(20) DEFAULT NULL, INDEX IDX_AB259917AE80F5DF (department_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', available_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', delivered_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE activity_logs ADD CONSTRAINT FK_F34B1DCEA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE curricula ADD CONSTRAINT FK_463CC9FCAE80F5DF FOREIGN KEY (department_id) REFERENCES departments (id)');
        $this->addSql('ALTER TABLE curriculum_subjects ADD CONSTRAINT FK_51FD47535AEA4428 FOREIGN KEY (curriculum_id) REFERENCES curricula (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE curriculum_subjects ADD CONSTRAINT FK_51FD4753D9CF12E2 FOREIGN KEY (curriculum_term_id) REFERENCES curriculum_terms (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE curriculum_subjects ADD CONSTRAINT FK_51FD475323EDC87 FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE curriculum_terms ADD CONSTRAINT FK_7333F2735AEA4428 FOREIGN KEY (curriculum_id) REFERENCES curricula (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE rooms ADD CONSTRAINT FK_7CA11A96AE80F5DF FOREIGN KEY (department_id) REFERENCES departments (id)');
        $this->addSql('ALTER TABLE rooms ADD CONSTRAINT FK_7CA11A96C7EAC36D FOREIGN KEY (department_group_id) REFERENCES department_groups (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE schedules ADD CONSTRAINT FK_313BDC8EC54F3401 FOREIGN KEY (academic_year_id) REFERENCES academic_years (id)');
        $this->addSql('ALTER TABLE schedules ADD CONSTRAINT FK_313BDC8E23EDC87 FOREIGN KEY (subject_id) REFERENCES subjects (id)');
        $this->addSql('ALTER TABLE schedules ADD CONSTRAINT FK_313BDC8E54177093 FOREIGN KEY (room_id) REFERENCES rooms (id)');
        $this->addSql('ALTER TABLE schedules ADD CONSTRAINT FK_313BDC8E680CAB68 FOREIGN KEY (faculty_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE subjects ADD CONSTRAINT FK_AB259917AE80F5DF FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE departments ADD department_group_id INT DEFAULT NULL, ADD contact_email VARCHAR(255) DEFAULT NULL, CHANGE description description LONGTEXT DEFAULT NULL, CHANGE head head_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE departments ADD CONSTRAINT FK_16AEB8D4770124B2 FOREIGN KEY (college_id) REFERENCES colleges (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE departments ADD CONSTRAINT FK_16AEB8D4C7EAC36D FOREIGN KEY (department_group_id) REFERENCES department_groups (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_16AEB8D4C7EAC36D ON departments (department_group_id)');
        $this->addSql('ALTER TABLE departments RENAME INDEX code TO UNIQ_16AEB8D477153098');
        $this->addSql('DROP INDEX idx_users_role ON users');
        $this->addSql('DROP INDEX idx_users_active ON users');
        $this->addSql('DROP INDEX idx_users_email ON users');
        $this->addSql('ALTER TABLE users ADD firstname VARCHAR(255) DEFAULT NULL, ADD middlename VARCHAR(255) DEFAULT NULL, ADD lastname VARCHAR(255) DEFAULT NULL, ADD email_verified_at DATETIME DEFAULT NULL, ADD employee_id VARCHAR(255) DEFAULT NULL, ADD position VARCHAR(255) DEFAULT NULL, ADD address LONGTEXT DEFAULT NULL, ADD last_login DATETIME DEFAULT NULL, ADD remember_token VARCHAR(100) DEFAULT NULL, ADD deleted_at DATETIME DEFAULT NULL, ADD preferred_semester_filter VARCHAR(20) DEFAULT NULL, ADD other_designation LONGTEXT DEFAULT NULL, DROP first_name, DROP last_name, CHANGE username username VARCHAR(255) NOT NULL, CHANGE role role INT NOT NULL, CHANGE is_active is_active TINYINT(1) DEFAULT NULL, CHANGE created_at created_at DATETIME DEFAULT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_1483A5E9770124B2 FOREIGN KEY (college_id) REFERENCES colleges (id)');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_1483A5E9AE80F5DF FOREIGN KEY (department_id) REFERENCES departments (id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_employee_id ON users (employee_id)');
        $this->addSql('ALTER TABLE users RENAME INDEX idx_users_college TO IDX_1483A5E9770124B2');
        $this->addSql('ALTER TABLE users RENAME INDEX idx_users_department TO IDX_1483A5E9AE80F5DF');
        $this->addSql('ALTER TABLE users RENAME INDEX uniq_1483a5e9e7927c74 TO UNIQ_email');
        $this->addSql('ALTER TABLE users RENAME INDEX uniq_1483a5e9f85e0677 TO UNIQ_username');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE departments DROP FOREIGN KEY FK_16AEB8D4770124B2');
        $this->addSql('ALTER TABLE users DROP FOREIGN KEY FK_1483A5E9770124B2');
        $this->addSql('ALTER TABLE departments DROP FOREIGN KEY FK_16AEB8D4C7EAC36D');
        $this->addSql('ALTER TABLE activity_logs DROP FOREIGN KEY FK_F34B1DCEA76ED395');
        $this->addSql('ALTER TABLE curricula DROP FOREIGN KEY FK_463CC9FCAE80F5DF');
        $this->addSql('ALTER TABLE curriculum_subjects DROP FOREIGN KEY FK_51FD47535AEA4428');
        $this->addSql('ALTER TABLE curriculum_subjects DROP FOREIGN KEY FK_51FD4753D9CF12E2');
        $this->addSql('ALTER TABLE curriculum_subjects DROP FOREIGN KEY FK_51FD475323EDC87');
        $this->addSql('ALTER TABLE curriculum_terms DROP FOREIGN KEY FK_7333F2735AEA4428');
        $this->addSql('ALTER TABLE rooms DROP FOREIGN KEY FK_7CA11A96AE80F5DF');
        $this->addSql('ALTER TABLE rooms DROP FOREIGN KEY FK_7CA11A96C7EAC36D');
        $this->addSql('ALTER TABLE schedules DROP FOREIGN KEY FK_313BDC8EC54F3401');
        $this->addSql('ALTER TABLE schedules DROP FOREIGN KEY FK_313BDC8E23EDC87');
        $this->addSql('ALTER TABLE schedules DROP FOREIGN KEY FK_313BDC8E54177093');
        $this->addSql('ALTER TABLE schedules DROP FOREIGN KEY FK_313BDC8E680CAB68');
        $this->addSql('ALTER TABLE subjects DROP FOREIGN KEY FK_AB259917AE80F5DF');
        $this->addSql('DROP TABLE academic_years');
        $this->addSql('DROP TABLE activity_logs');
        $this->addSql('DROP TABLE colleges');
        $this->addSql('DROP TABLE curricula');
        $this->addSql('DROP TABLE curriculum_subjects');
        $this->addSql('DROP TABLE curriculum_terms');
        $this->addSql('DROP TABLE department_groups');
        $this->addSql('DROP TABLE rooms');
        $this->addSql('DROP TABLE schedules');
        $this->addSql('DROP TABLE subjects');
        $this->addSql('DROP TABLE messenger_messages');
        $this->addSql('ALTER TABLE users DROP FOREIGN KEY FK_1483A5E9AE80F5DF');
        $this->addSql('DROP INDEX UNIQ_employee_id ON users');
        $this->addSql('ALTER TABLE users ADD first_name VARCHAR(100) NOT NULL, ADD last_name VARCHAR(100) NOT NULL, DROP firstname, DROP middlename, DROP lastname, DROP email_verified_at, DROP employee_id, DROP position, DROP address, DROP last_login, DROP remember_token, DROP deleted_at, DROP preferred_semester_filter, DROP other_designation, CHANGE username username VARCHAR(100) NOT NULL, CHANGE role role VARCHAR(20) NOT NULL, CHANGE is_active is_active TINYINT(1) NOT NULL, CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX idx_users_role ON users (role)');
        $this->addSql('CREATE INDEX idx_users_active ON users (is_active)');
        $this->addSql('CREATE INDEX idx_users_email ON users (email)');
        $this->addSql('ALTER TABLE users RENAME INDEX uniq_username TO UNIQ_1483A5E9F85E0677');
        $this->addSql('ALTER TABLE users RENAME INDEX uniq_email TO UNIQ_1483A5E9E7927C74');
        $this->addSql('ALTER TABLE users RENAME INDEX idx_1483a5e9770124b2 TO idx_users_college');
        $this->addSql('ALTER TABLE users RENAME INDEX idx_1483a5e9ae80f5df TO idx_users_department');
        $this->addSql('DROP INDEX IDX_16AEB8D4C7EAC36D ON departments');
        $this->addSql('ALTER TABLE departments ADD head VARCHAR(255) DEFAULT NULL, DROP department_group_id, DROP head_name, DROP contact_email, CHANGE description description TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE departments RENAME INDEX uniq_16aeb8d477153098 TO code');
    }
}
