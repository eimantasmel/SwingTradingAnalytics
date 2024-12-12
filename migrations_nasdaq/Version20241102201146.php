<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241102201146 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE candlesticks CHANGE open_price open_price NUMERIC(10, 6) DEFAULT NULL, CHANGE highest_price highest_price NUMERIC(10, 6) DEFAULT NULL, CHANGE lowest_price lowest_price NUMERIC(10, 6) DEFAULT NULL, CHANGE close_price close_price NUMERIC(10, 6) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE CandleSticks CHANGE open_price open_price NUMERIC(10, 2) DEFAULT NULL, CHANGE highest_price highest_price NUMERIC(10, 2) DEFAULT NULL, CHANGE lowest_price lowest_price NUMERIC(10, 2) DEFAULT NULL, CHANGE close_price close_price NUMERIC(10, 2) DEFAULT NULL');
    }
}
