<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Familles de refresh tokens, introduites par gesdinet/jwt-refresh-token-bundle 3.0.
 *
 * Une famille regroupe les tokens issus d'une même connexion : celui qui en
 * remplace un autre hérite de sa famille. Doctrine lit tous les champs mappés,
 * donc la première requête échoue tant que les colonnes ne sont pas là.
 *
 * Les lignes existantes gardent une famille nulle, lue comme « ce token
 * n'appartient à aucune chaîne » : elles continuent de fonctionner.
 */
final class Version20260825091731 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les colonnes family et family_valid sur refresh_tokens';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE refresh_tokens ADD family VARCHAR(32) DEFAULT NULL, ADD family_valid DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_9BACE7E1A5E6215B ON refresh_tokens (family)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_9BACE7E1A5E6215B ON refresh_tokens');
        $this->addSql('ALTER TABLE refresh_tokens DROP family, DROP family_valid');
    }
}
