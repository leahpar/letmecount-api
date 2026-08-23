<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout de l\'identifiant Apple (sub) sur les utilisateurs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD apple_sub VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_IDENTIFIER_APPLE_SUB ON user (apple_sub)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_IDENTIFIER_APPLE_SUB ON user');
        $this->addSql('ALTER TABLE user DROP apple_sub');
    }
}
