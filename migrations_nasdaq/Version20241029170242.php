<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241029170242 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE candlesticks ADD highest_price NUMERIC(10, 2) DEFAULT NULL, ADD lowest_price NUMERIC(10, 2) DEFAULT NULL, ADD close_price NUMERIC(10, 2) DEFAULT NULL, CHANGE volume volume NUMERIC(14, 2) DEFAULT NULL, CHANGE price open_price NUMERIC(10, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE CandleSticks ADD price NUMERIC(10, 2) DEFAULT NULL, DROP open_price, DROP highest_price, DROP lowest_price, DROP close_price, CHANGE volume volume NUMERIC(10, 2) DEFAULT NULL');
    }
}
