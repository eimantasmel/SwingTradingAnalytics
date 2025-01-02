<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250102003709 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE CandleSticks (id INT AUTO_INCREMENT NOT NULL, security_id INT NOT NULL, date DATE NOT NULL, open_price NUMERIC(14, 6) DEFAULT NULL, highest_price NUMERIC(14, 6) DEFAULT NULL, lowest_price NUMERIC(14, 6) DEFAULT NULL, close_price NUMERIC(14, 6) DEFAULT NULL, volume NUMERIC(14, 2) DEFAULT NULL, INDEX IDX_F8EBBB066DBE4214 (security_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE Securities (id INT AUTO_INCREMENT NOT NULL, ticker VARCHAR(20) NOT NULL, is_crypto TINYINT(1) DEFAULT NULL, is_forex TINYINT(1) DEFAULT NULL, description VARCHAR(255) DEFAULT NULL, sector VARCHAR(255) DEFAULT NULL, industry VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0 (queue_name), INDEX IDX_75EA56E0E3BD61CE (available_at), INDEX IDX_75EA56E016BA31DB (delivered_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE CandleSticks ADD CONSTRAINT FK_F8EBBB066DBE4214 FOREIGN KEY (security_id) REFERENCES Securities (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE CandleSticks DROP FOREIGN KEY FK_F8EBBB066DBE4214');
        $this->addSql('DROP TABLE CandleSticks');
        $this->addSql('DROP TABLE Securities');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
