<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250915092158 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE `log` (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, depense_id INT DEFAULT NULL, date DATETIME NOT NULL, action VARCHAR(255) NOT NULL, libelle VARCHAR(255) DEFAULT NULL, montant DOUBLE PRECISION DEFAULT NULL, INDEX IDX_8F3F68C5A76ED395 (user_id), INDEX IDX_8F3F68C541D81563 (depense_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE `log` ADD CONSTRAINT FK_8F3F68C5A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `log` ADD CONSTRAINT FK_8F3F68C541D81563 FOREIGN KEY (depense_id) REFERENCES depense (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE `log` DROP FOREIGN KEY FK_8F3F68C5A76ED395');
        $this->addSql('ALTER TABLE `log` DROP FOREIGN KEY FK_8F3F68C541D81563');
        $this->addSql('DROP TABLE `log`');
    }
}
