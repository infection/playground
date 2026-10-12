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
use function sprintf;

/**
 * Explore lists every run: no title, no reports, no unlisted runs. Version20261012000000 added their columns,
 * and production ran it. MySQL has no DROP COLUMN IF EXISTS or DROP INDEX IF EXISTS, so each step checks the
 * schema first: the migration works whatever part of them a database has.
 */
final class Version20261013000000 extends AbstractMigration
{
    /**
     * Index name => its columns, before and after: the old indexes all start with is_listed.
     */
    private const OLD_INDEXES = [
        'idx_example_listed_created_at' => 'is_listed, created_at',
        'idx_example_listed_msi' => 'is_listed, msi',
        'idx_example_listed_mutants_total' => 'is_listed, mutants_total',
    ];

    private const NEW_INDEXES = [
        'idx_example_created_at' => 'created_at',
        'idx_example_msi' => 'msi',
        'idx_example_mutants_total' => 'mutants_total',
    ];

    /**
     * Column => its definition, as Version20261012000000 added it.
     */
    private const DROPPED_COLUMNS = [
        'title' => 'VARCHAR(80) DEFAULT NULL',
        'is_listed' => 'TINYINT DEFAULT 0 NOT NULL',
        'reported_at' => 'DATETIME DEFAULT NULL',
    ];

    public function getDescription(): string
    {
        return 'Remove the title, listing and report columns of Explore, which lists every run';
    }

    public function up(Schema $schema): void
    {
        foreach (self::OLD_INDEXES as $index => $columns) {
            if ($this->hasIndex($index)) {
                $this->addSql(sprintf('DROP INDEX %s ON example', $index));
            }
        }

        foreach (self::DROPPED_COLUMNS as $column => $definition) {
            if ($this->hasColumn($column)) {
                $this->addSql(sprintf('ALTER TABLE example DROP %s', $column));
            }
        }

        foreach (self::NEW_INDEXES as $index => $columns) {
            if (!$this->hasIndex($index)) {
                $this->addSql(sprintf('CREATE INDEX %s ON example (%s)', $index, $columns));
            }
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::NEW_INDEXES as $index => $columns) {
            if ($this->hasIndex($index)) {
                $this->addSql(sprintf('DROP INDEX %s ON example', $index));
            }
        }

        foreach (self::DROPPED_COLUMNS as $column => $definition) {
            if (!$this->hasColumn($column)) {
                $this->addSql(sprintf('ALTER TABLE example ADD %s %s', $column, $definition));
            }
        }

        foreach (self::OLD_INDEXES as $index => $columns) {
            if (!$this->hasIndex($index)) {
                $this->addSql(sprintf('CREATE INDEX %s ON example (%s)', $index, $columns));
            }
        }
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function hasColumn(string $column): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'example\' AND COLUMN_NAME = ?',
            [$column],
        ) > 0;
    }

    private function hasIndex(string $index): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'example\' AND INDEX_NAME = ?',
            [$index],
        ) > 0;
    }
}
