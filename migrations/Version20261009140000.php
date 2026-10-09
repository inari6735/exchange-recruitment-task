<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add wallets.closed_at; one open wallet per user and currency';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `wallets` ADD `closed_at` DATETIME NULL AFTER `last_activity_at`');
        // 1 for open wallets, NULL for closed ones: NULLs never collide in a unique index (MariaDB has no partial indexes).
        $this->addSql('ALTER TABLE `wallets` ADD `open_flag` TINYINT AS (IF(`closed_at` IS NULL, 1, NULL)) PERSISTENT');
        // Add the new index before dropping the old one: fk_wallets_user_id needs an index starting with user_id.
        $this->addSql('ALTER TABLE `wallets` ADD UNIQUE KEY `wallet_user_currency_open_unique` (`user_id`, `currency`, `open_flag`)');
        $this->addSql('ALTER TABLE `wallets` DROP INDEX `wallet_user_currency_unique`');
    }

    public function down(Schema $schema): void
    {
        // Fails when a user has a closed and an open wallet in the same currency — reopening history is not reversible.
        $this->addSql('ALTER TABLE `wallets` ADD UNIQUE KEY `wallet_user_currency_unique` (`user_id`, `currency`)');
        $this->addSql('ALTER TABLE `wallets` DROP INDEX `wallet_user_currency_open_unique`');
        $this->addSql('ALTER TABLE `wallets` DROP `open_flag`');
        $this->addSql('ALTER TABLE `wallets` DROP `closed_at`');
    }
}
