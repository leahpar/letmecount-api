<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260727135854 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Suppression du mot de passe : connexion par passkey ou code d\'accès';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP password');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD password VARCHAR(255) NOT NULL');
    }
}
