<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * De quoi nommer les sessions et borner la table des clients OAuth.
 *
 * `refresh_tokens.label` et `created_at` sont nullables et le restent : les
 * sessions déjà ouvertes n'ont ni l'un ni l'autre, et le renouvellement les leur
 * donnera. `oauth_client.last_used_at` mesure la dernière autorisation accordée,
 * c'est-à-dire ce qui décide qu'un client mérite encore sa ligne.
 */
final class Version20260825212206 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le libellé et la date des sessions, et la dernière utilisation des clients OAuth';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oauth_client ADD last_used_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE refresh_tokens ADD label VARCHAR(100) DEFAULT NULL, ADD created_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oauth_client DROP last_used_at');
        $this->addSql('ALTER TABLE refresh_tokens DROP label, DROP created_at');
    }
}
