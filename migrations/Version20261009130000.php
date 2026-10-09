<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009130000 extends AbstractMigration
{
    private const string IN_FLIGHT = "('pending', 'fraud_review')";

    public function getDescription(): string
    {
        return 'Add wallets.reserved and move in-flight transfers to the reservation model';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `wallets` ADD `reserved` DECIMAL(15,4) NOT NULL DEFAULT 0 AFTER `balance`');

        // In-flight transfers were placed under the old model: money already left the source and reached the target.
        // Undo that move and reserve the source amount instead, so complete()/reject() settle them exactly once.
        $this->addSql(sprintf(<<<'SQL'
            UPDATE `wallets` w
            JOIN (
                SELECT `from_wallet_id` AS wallet_id, SUM(`from_amount`) AS amount
                FROM `transactions` WHERE `status` IN %s GROUP BY `from_wallet_id`
            ) t ON t.wallet_id = w.id
            SET w.`balance` = w.`balance` + t.amount, w.`reserved` = w.`reserved` + t.amount
            SQL, self::IN_FLIGHT));
        $this->addSql(sprintf(<<<'SQL'
            UPDATE `wallets` w
            JOIN (
                SELECT `to_wallet_id` AS wallet_id, SUM(`to_amount`) AS amount
                FROM `transactions` WHERE `status` IN %s GROUP BY `to_wallet_id`
            ) t ON t.wallet_id = w.id
            SET w.`balance` = w.`balance` - t.amount
            SQL, self::IN_FLIGHT));
    }

    public function down(Schema $schema): void
    {
        $this->addSql(sprintf(<<<'SQL'
            UPDATE `wallets` w
            JOIN (
                SELECT `from_wallet_id` AS wallet_id, SUM(`from_amount`) AS amount
                FROM `transactions` WHERE `status` IN %s GROUP BY `from_wallet_id`
            ) t ON t.wallet_id = w.id
            SET w.`balance` = w.`balance` - t.amount
            SQL, self::IN_FLIGHT));
        $this->addSql(sprintf(<<<'SQL'
            UPDATE `wallets` w
            JOIN (
                SELECT `to_wallet_id` AS wallet_id, SUM(`to_amount`) AS amount
                FROM `transactions` WHERE `status` IN %s GROUP BY `to_wallet_id`
            ) t ON t.wallet_id = w.id
            SET w.`balance` = w.`balance` + t.amount
            SQL, self::IN_FLIGHT));
        $this->addSql('ALTER TABLE `wallets` DROP `reserved`');
    }
}
