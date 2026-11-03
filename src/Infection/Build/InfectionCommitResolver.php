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

use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use const PREG_UNMATCHED_AS_NULL;
use function sprintf;
use function strtolower;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use function trim;

/**
 * Turns the link to a pull request or a commit of infection/infection into the commit a run builds Infection from.
 *
 * Infection runs outside the sandbox of the submitted code: it must not be built from code anybody can push.
 * Only the commits of the branches of infection/infection itself are built, which needs write access to it:
 * the head of a pull request from a branch of infection/infection, the commits before it,
 * and the commits of the default branch. Pull requests from forks are refused.
 */
class InfectionCommitResolver
{
    private const API_URL = 'https://api.github.com/repos/' . InfectionCommit::REPOSITORY;

    private const DEFAULT_BRANCH = 'master';

    // https://github.com/infection/infection/pull/3717, its /files, /commits or /commits/<sha>,
    // https://github.com/infection/infection/commit/<sha>, or only the number of the pull request.
    // "(?|" numbers the groups of each alternative from the same number: they all set the named groups
    private const REFERENCE_REGEX = '~^(?|
        \#?(?<pullRequest>\d+)(?<sha>)
        |(?:https?://)?(?:www\.)?github\.com/infection/infection/pull/(?<pullRequest>\d+)(?:/commits/(?<sha>[0-9a-f]{7,40})|/[a-z]+)?/?(?:[?\#].*)?
        |(?:https?://)?(?:www\.)?github\.com/infection/infection/commit/(?<pullRequest>)(?<sha>[0-9a-f]{7,40})/?(?:[?\#].*)?
    )$~ix';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly InfectionSourceBuilder $sourceBuilder,
    ) {
    }

    /**
     * @throws InvalidInfectionCommit
     */
    public function resolve(string $reference): InfectionCommit
    {
        if (preg_match(self::REFERENCE_REGEX, trim($reference), $matches, PREG_UNMATCHED_AS_NULL) !== 1) {
            throw new InvalidInfectionCommit(sprintf('Must be a link to a pull request or a commit of %s.', InfectionCommit::REPOSITORY));
        }

        // NULL for a link to a pull request without a commit
        $sha = strtolower((string) $matches['sha']);
        $pullRequest = $matches['pullRequest'] === '' ? null : (int) $matches['pullRequest'];

        // a commit that was built was checked before: no request to GitHub, which limits them
        if (InfectionCommit::isSha($sha) && $this->sourceBuilder->isBuilt($sha)) {
            return new InfectionCommit($sha, $pullRequest);
        }

        if ($pullRequest === null) {
            return new InfectionCommit($this->findAncestorSha($sha, self::DEFAULT_BRANCH, sprintf('the %s branch', self::DEFAULT_BRANCH)));
        }

        $headSha = $this->findPullRequestHeadSha($pullRequest);

        if ($sha === '') {
            return new InfectionCommit($headSha, $pullRequest);
        }

        return new InfectionCommit($this->findAncestorSha($sha, $headSha, sprintf('pull request #%d', $pullRequest)), $pullRequest);
    }

    private function findPullRequestHeadSha(int $pullRequest): string
    {
        $data = $this->request(sprintf('/pulls/%d', $pullRequest), sprintf('Pull request #%d', $pullRequest));

        $head = is_array($data['head'] ?? null) ? $data['head'] : [];
        $headRepository = is_array($head['repo'] ?? null) ? $head['repo'] : [];

        if (($headRepository['full_name'] ?? null) !== InfectionCommit::REPOSITORY) {
            throw new InvalidInfectionCommit(sprintf(
                'Pull request #%d is from a fork: only the pull requests from a branch of %s can be run, Infection is not sandboxed like the code it tests.',
                $pullRequest,
                InfectionCommit::REPOSITORY,
            ));
        }

        $headSha = $head['sha'] ?? null;

        if (!is_string($headSha) || !InfectionCommit::isSha($headSha)) {
            throw new InvalidInfectionCommit(sprintf('GitHub did not return the head commit of pull request #%d.', $pullRequest));
        }

        return $headSha;
    }

    /**
     * The commit is trusted when the trusted head contains it. GitHub also finds the commits of forks
     * from the main repository, so the commit alone is not enough.
     */
    private function findAncestorSha(string $sha, string $trustedHead, string $trustedHeadName): string
    {
        $data = $this->request(
            sprintf('/compare/%s...%s?per_page=1', $sha, $trustedHead),
            sprintf('Commit %s', $sha),
        );

        $status = $data['status'] ?? null;
        $baseCommit = is_array($data['base_commit'] ?? null) ? $data['base_commit'] : [];
        $fullSha = $baseCommit['sha'] ?? null;

        if (!in_array($status, ['ahead', 'identical'], true) || !is_string($fullSha) || !InfectionCommit::isSha($fullSha)) {
            throw new InvalidInfectionCommit(sprintf('Commit %s is not a commit of %s.', $sha, $trustedHeadName));
        }

        return $fullSha;
    }

    /**
     * @return array<mixed>
     */
    private function request(string $path, string $subject): array
    {
        try {
            $response = $this->httpClient->request('GET', self::API_URL . $path, [
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => 'infection-playground',
                ],
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 404) {
                throw new InvalidInfectionCommit(sprintf('%s was not found in %s.', $subject, InfectionCommit::REPOSITORY));
            }

            if (in_array($statusCode, [403, 429], true)) {
                throw new InvalidInfectionCommit('GitHub refused the request, probably its rate limit: try again later.');
            }

            if ($statusCode !== 200) {
                throw new InvalidInfectionCommit(sprintf('GitHub answered with HTTP %d, try again later.', $statusCode));
            }

            return $response->toArray();
        } catch (ExceptionInterface $exception) {
            throw new InvalidInfectionCommit('GitHub could not be reached, try again later.', previous: $exception);
        }
    }
}
