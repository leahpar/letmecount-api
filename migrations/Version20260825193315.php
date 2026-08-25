<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les deux tables du serveur d'autorisation OAuth (lot 3 de doc/couche-mcp.md).
 *
 * `oauth_client` garde les clients enregistrés dynamiquement — sans secret, les
 * clients MCP étant publics — et `oauth_authorization_code` les codes en attente
 * d'échange, qui vivent 60 s et disparaissent à l'usage. Les deux clés
 * étrangères sont en CASCADE : un client supprimé ou un compte supprimé
 * n'emporte que des lignes sans intérêt.
 */
final class Version20260825193315 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les tables du serveur d\'autorisation OAuth : clients enregistrés et codes d\'autorisation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE oauth_authorization_code (id INT AUTO_INCREMENT NOT NULL, code_hash VARCHAR(64) NOT NULL, redirect_uri VARCHAR(500) NOT NULL, code_challenge VARCHAR(128) NOT NULL, resource VARCHAR(500) DEFAULT NULL, expires_at DATETIME NOT NULL, client_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_793B081719EB6921 (client_id), INDEX IDX_793B0817A76ED395 (user_id), UNIQUE INDEX UNIQ_OAUTH_CODE_HASH (code_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE oauth_client (id INT AUTO_INCREMENT NOT NULL, client_id VARCHAR(64) NOT NULL, client_name VARCHAR(255) NOT NULL, redirect_uris JSON NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_OAUTH_CLIENT_CLIENT_ID (client_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE oauth_authorization_code ADD CONSTRAINT FK_793B081719EB6921 FOREIGN KEY (client_id) REFERENCES oauth_client (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE oauth_authorization_code ADD CONSTRAINT FK_793B0817A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oauth_authorization_code DROP FOREIGN KEY FK_793B081719EB6921');
        $this->addSql('ALTER TABLE oauth_authorization_code DROP FOREIGN KEY FK_793B0817A76ED395');
        $this->addSql('DROP TABLE oauth_authorization_code');
        $this->addSql('DROP TABLE oauth_client');
    }
}
