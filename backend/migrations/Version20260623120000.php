<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260623120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add refund table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE refund (id INT AUTO_INCREMENT NOT NULL, amount NUMERIC(14, 2) NOT NULL, reason VARCHAR(500) NOT NULL, status VARCHAR(20) NOT NULL, provider_refund_id VARCHAR(64) DEFAULT NULL, idempotency_key VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, transaction_id INT NOT NULL, INDEX IDX_5B2C14582FC0CB0F (transaction_id), UNIQUE INDEX uniq_refund_idempotency_key (idempotency_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE refund ADD CONSTRAINT FK_5B2C14582FC0CB0F FOREIGN KEY (transaction_id) REFERENCES transaction (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE refund DROP FOREIGN KEY FK_5B2C14582FC0CB0F');
        $this->addSql('DROP TABLE refund');
    }
}
