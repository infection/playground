<?php
/**
 * This code is licensed under the BSD 3-Clause License.
 *
 * Copyright (c) 2017, Maks Rafalko
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * * Redistributions of source code must retain the above copyright notice, this
 *   list of conditions and the following disclaimer.
 *
 * * Redistributions in binary form must reproduce the above copyright notice,
 *   this list of conditions and the following disclaimer in the documentation
 *   and/or other materials provided with the distribution.
 *
 * * Neither the name of the copyright holder nor the names of its
 *   contributors may be used to endorse or promote products derived from
 *   this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
 * FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
 * DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
 * OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Baseline for an empty database.
 *
 * The `example` table predates Doctrine Migrations, and the migrations that altered it until
 * 2024 were declared in the `migrations` namespace, which the migrations finder never loaded.
 * They were never executed on production and are removed; this migration creates the table in
 * its current shape instead. Production already has the table, so there it changes nothing.
 */
final class Version20240310000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the example table on an empty database';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS example (
          id INT AUTO_INCREMENT NOT NULL,
          code LONGTEXT NOT NULL,
          test LONGTEXT NOT NULL,
          config LONGTEXT NOT NULL,
          result_output LONGTEXT NOT NULL,
          json_log LONGTEXT DEFAULT NULL,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
          input_hash VARCHAR(32) NOT NULL,
          id_hash VARCHAR(10) DEFAULT NULL,
          infection_version VARCHAR(11) DEFAULT NULL,
          phpunit_version VARCHAR(11) DEFAULT NULL,
          php_version VARCHAR(12) DEFAULT \'8.1.3\' NOT NULL,
          UNIQUE INDEX UNIQ_6EEC9B9F92D5D98F (input_hash),
          PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        // Production data lives in this table: dropping it is never what a rollback should do.
        $this->throwIrreversibleMigrationException();
    }
}
