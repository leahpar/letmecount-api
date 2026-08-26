<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260823120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout de l\'identifiant Google (sub) sur les utilisateurs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD google_sub VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_IDENTIFIER_GOOGLE_SUB ON user (google_sub)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_IDENTIFIER_GOOGLE_SUB ON user');
        $this->addSql('ALTER TABLE user DROP google_sub');
    }
}
