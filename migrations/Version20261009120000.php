<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store wallet balances as DECIMAL and round money columns to currency scale';
    }

    public function up(Schema $schema): void
    {
        // Convert first, so ROUND() below works on exact DECIMAL values instead of DOUBLE.
        $this->addSql('ALTER TABLE `wallets` MODIFY `balance` DECIMAL(15,4) NOT NULL DEFAULT 0');

        // Currency scales (ISO 4217) are hardcoded on purpose: a migration must not change when application code does.
        $this->addSql("UPDATE `wallets` SET `balance` = ROUND(`balance`, IF(`currency` = 'JPY', 0, 2))");
        $this->addSql("UPDATE `company_wallets` SET `balance` = ROUND(`balance`, IF(`currency` = 'JPY', 0, 2))");
        $this->addSql(<<<SQL
            UPDATE `transactions` SET
                `from_amount` = ROUND(`from_amount`, IF(`from_currency` = 'JPY', 0, 2)),
                `to_amount`   = ROUND(`to_amount`,   IF(`to_currency` = 'JPY', 0, 2)),
                `spread`      = ROUND(`spread`,      IF(`to_currency` = 'JPY', 0, 2))
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Rounding done in up() is irreversible; only the column type is restored.
        $this->addSql('ALTER TABLE `wallets` MODIFY `balance` DOUBLE NOT NULL DEFAULT 0');
    }
}
