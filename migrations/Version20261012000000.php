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

final class Version20261012000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the stats, title, listing and report columns of Explore, and the runs\' mutators table (fill them with app:example:backfill-stats)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE example_mutator (id INT AUTO_INCREMENT NOT NULL, mutator VARCHAR(64) NOT NULL, killed_count INT NOT NULL, escaped_count INT NOT NULL, timed_out_count INT NOT NULL, not_covered_count INT NOT NULL, example_id INT NOT NULL, INDEX idx_example_mutator_mutator (mutator), INDEX IDX_841F6887AB07C711 (example_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE example_mutator ADD CONSTRAINT FK_841F6887AB07C711 FOREIGN KEY (example_id) REFERENCES example (id) ON DELETE CASCADE');
        // the runs made so far were shared as unlisted links: is_listed is 0 for them
        $this->addSql('ALTER TABLE example ADD title VARCHAR(80) DEFAULT NULL, ADD is_listed TINYINT DEFAULT 0 NOT NULL, ADD is_default_example TINYINT DEFAULT 0 NOT NULL, ADD has_custom_config TINYINT DEFAULT 0 NOT NULL, ADD mutants_total INT DEFAULT NULL, ADD killed_count INT DEFAULT NULL, ADD escaped_count INT DEFAULT NULL, ADD timed_out_count INT DEFAULT NULL, ADD not_covered_count INT DEFAULT NULL, ADD msi DOUBLE PRECISION DEFAULT NULL, ADD covered_msi DOUBLE PRECISION DEFAULT NULL, ADD reported_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_example_listed_created_at ON example (is_listed, created_at)');
        $this->addSql('CREATE INDEX idx_example_listed_msi ON example (is_listed, msi)');
        $this->addSql('CREATE INDEX idx_example_listed_mutants_total ON example (is_listed, mutants_total)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE example_mutator DROP FOREIGN KEY FK_841F6887AB07C711');
        $this->addSql('DROP TABLE example_mutator');
        $this->addSql('DROP INDEX idx_example_listed_created_at ON example');
        $this->addSql('DROP INDEX idx_example_listed_msi ON example');
        $this->addSql('DROP INDEX idx_example_listed_mutants_total ON example');
        $this->addSql('ALTER TABLE example DROP title, DROP is_listed, DROP is_default_example, DROP has_custom_config, DROP mutants_total, DROP killed_count, DROP escaped_count, DROP timed_out_count, DROP not_covered_count, DROP msi, DROP covered_msi, DROP reported_at');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
