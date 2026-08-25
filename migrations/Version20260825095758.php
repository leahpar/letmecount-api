<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remet la base au niveau des entités sur deux points qui avaient dérivé.
 *
 * 1. `log.libelle` et `log.montant` sont non-nullables depuis septembre 2025,
 *    du côté des entités seulement : la table a été créée nullable la veille et
 *    n'a jamais reçu la migration de suivi. Ce ne sont pas des propriétés
 *    nullables en PHP — `Log::$libelle` est un `string` et `$montant` un
 *    `float`, toujours renseignés par le constructeur depuis la dépense — donc
 *    une valeur nulle en base ferait échouer l'hydratation de `GET /logs`. Le
 *    remplissage préalable n'est là que par précaution : il récupère la valeur
 *    sur la dépense quand elle existe encore.
 *
 * 2. Les commentaires `(DC2Type:datetime_immutable)` étaient écrits par DBAL 3.
 *    DBAL 4 ne les écrit ni ne les lit — le type vient du mapping. Purement
 *    cosmétique, mais tant qu'ils sont là `migrations:diff` les remonte à
 *    chaque appel, ce qui noie les vraies différences.
 */
final class Version20260825095758 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Aligne la base sur les entités : log NOT NULL, et commentaires DC2Type hérités de DBAL 3';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE `log` l
            LEFT JOIN depense d ON d.id = l.depense_id
            SET l.libelle = COALESCE(l.libelle, d.titre, ''),
                l.montant = COALESCE(l.montant, d.montant, 0)
            WHERE l.libelle IS NULL OR l.montant IS NULL
            SQL);
        $this->addSql('ALTER TABLE `log` CHANGE libelle libelle VARCHAR(255) NOT NULL, CHANGE montant montant DOUBLE PRECISION NOT NULL');

        $this->addSql('ALTER TABLE push_subscription CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE webauthn_credential CHANGE created_at created_at DATETIME NOT NULL, CHANGE last_used_at last_used_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Les valeurs remplies à l'aller ne sont pas distinguables de celles qui
        // étaient déjà là : le retour rend les colonnes nullables, sans les vider.
        $this->addSql('ALTER TABLE `log` CHANGE libelle libelle VARCHAR(255) DEFAULT NULL, CHANGE montant montant DOUBLE PRECISION DEFAULT NULL');

        $this->addSql('ALTER TABLE push_subscription CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE webauthn_credential CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE last_used_at last_used_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }
}
