<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250910082305 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout de la relation conjoint entre utilisateurs';
    }

    public function up(Schema $schema): void
    {
        // Ajout de la relation conjoint entre utilisateurs
        $this->addSql('ALTER TABLE user ADD conjoint_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE user ADD CONSTRAINT FK_8D93D6495E8D7836 FOREIGN KEY (conjoint_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D6495E8D7836 ON user (conjoint_id)');
    }

    public function down(Schema $schema): void
    {
        // Suppression de la relation conjoint entre utilisateurs
        $this->addSql('ALTER TABLE user DROP FOREIGN KEY FK_8D93D6495E8D7836');
        $this->addSql('DROP INDEX UNIQ_8D93D6495E8D7836 ON user');
        $this->addSql('ALTER TABLE user DROP conjoint_id');
    }
}
