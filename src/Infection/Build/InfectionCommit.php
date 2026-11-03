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

namespace App\Infection\Build;

use function preg_match;
use function sprintf;
use function substr;
use Webmozart\Assert\Assert;

/**
 * A commit of infection/infection a run builds Infection from, instead of using the released PHAR.
 * The pull request is the one it was picked from, NULL for a commit of the default branch.
 */
final readonly class InfectionCommit
{
    public const REPOSITORY = 'infection/infection';

    public function __construct(
        public string $sha,
        public ?int $pullRequest = null,
    ) {
        Assert::true(self::isSha($sha), sprintf('Expected a full commit SHA, got "%s".', $sha));
        Assert::nullOrPositiveInteger($pullRequest);
    }

    /**
     * The Infection version of a run built from a commit is the SHA of the commit.
     */
    public static function isSha(?string $value): bool
    {
        return $value !== null && preg_match('/^[0-9a-f]{40}$/', $value) === 1;
    }

    public function getShortSha(): string
    {
        return substr($this->sha, 0, 7);
    }

    public function getLabel(): string
    {
        return $this->pullRequest === null
            ? $this->getShortSha()
            : sprintf('#%d %s', $this->pullRequest, $this->getShortSha());
    }

    public function getUrl(): string
    {
        return $this->pullRequest === null
            ? sprintf('https://github.com/%s/commit/%s', self::REPOSITORY, $this->sha)
            : sprintf('https://github.com/%s/pull/%d/commits/%s', self::REPOSITORY, $this->pullRequest, $this->sha);
    }
}
