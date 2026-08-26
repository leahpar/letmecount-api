<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260826090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout de l\'identifiant PocketID (sub) sur les utilisateurs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD pocket_id_sub VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_IDENTIFIER_POCKETID_SUB ON user (pocket_id_sub)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_IDENTIFIER_POCKETID_SUB ON user');
        $this->addSql('ALTER TABLE user DROP pocket_id_sub');
    }
}
