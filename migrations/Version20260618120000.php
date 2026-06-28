<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create the `mediary_ro` read-only Postgres role.
 *
 * mediary is the central owner of claims + media (like lingua for translation memory).
 * Other apps that use media-bundle READ this DB directly via a connection, but only
 * mediary writes — enforced here at the role level (Doctrine can't enforce read-only).
 * Consumers connect as `mediary_ro` (see media-bundle's `mediary_ro` DBAL connection +
 * claims-bundle's ClaimReader).
 *
 * Idempotent. Dev password is the role name; ROTATE in production (out-of-band
 * `ALTER ROLE mediary_ro PASSWORD '…'`). Must run as a role with CREATEROLE.
 */
final class Version20260618120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create read-only mediary_ro Postgres role (SELECT on public, read-only transactions).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Read-only role is Postgres-only (skipped on sqlite/test).',
        );

        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'mediary_ro') THEN
                    CREATE ROLE mediary_ro LOGIN PASSWORD 'mediary_ro';
                END IF;
            END
            $$;
        SQL);

        // Connect + read everything in public (claims, media/asset, …); no write grants.
        // GRANT CONNECT needs a literal db name, but it varies (mediary in prod, sais in CI),
        // so resolve it at runtime via current_database() rather than hardcoding 'mediary'.
        $this->addSql(<<<'SQL'
            DO $$ BEGIN
                EXECUTE format('GRANT CONNECT ON DATABASE %I TO mediary_ro', current_database());
            END $$;
        SQL);
        $this->addSql('GRANT USAGE ON SCHEMA public TO mediary_ro');
        $this->addSql('GRANT SELECT ON ALL TABLES IN SCHEMA public TO mediary_ro');
        // Future tables (created by mediary's owner role) auto-grant SELECT to the RO role.
        $this->addSql('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO mediary_ro');
        // Belt-and-suspenders: the role's sessions default to read-only transactions.
        $this->addSql('ALTER ROLE mediary_ro SET default_transaction_read_only = on');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Read-only role is Postgres-only (skipped on sqlite/test).',
        );

        $this->addSql('ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE SELECT ON TABLES FROM mediary_ro');
        $this->addSql('REVOKE SELECT ON ALL TABLES IN SCHEMA public FROM mediary_ro');
        $this->addSql('REVOKE USAGE ON SCHEMA public FROM mediary_ro');
        $this->addSql(<<<'SQL'
            DO $$ BEGIN
                EXECUTE format('REVOKE CONNECT ON DATABASE %I FROM mediary_ro', current_database());
            END $$;
        SQL);
        $this->addSql('DROP ROLE IF EXISTS mediary_ro');
    }
}
