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

use App\Infection\Runner;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use const PREG_UNMATCHED_AS_NULL;
use function sprintf;
use function strtolower;
use function substr;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use function trim;

/**
 * Turns the link to a pull request or a commit of infection/infection into the commit a run builds Infection from.
 *
 * Infection runs outside the sandbox of the submitted code: it must not be built from code anybody can push.
 * Only the commits of the branches of infection/infection itself are built, which needs write access to it:
 * the head of a pull request from a branch of infection/infection, the commits before it,
 * and the commits of the default branch. Pull requests from forks are refused, and so are the ones bots opened:
 * the code of a coding agent is written from issues anybody can write, and the updates of dependencies are not reviewed yet.
 *
 * The commit must contain the release the playground runs: older commits may not keep the code they test
 * in the sandbox (see ConfigBuilder), nor install on the PHP version of the playground, and their number is unbounded.
 */
class InfectionCommitResolver
{
    private const API_URL = 'https://api.github.com/repos/' . InfectionCommit::REPOSITORY;

    private const DEFAULT_BRANCH = 'master';

    // https://github.com/infection/infection/pull/3717, its /files, /commits or /commits/<sha> (or /changes/<sha>),
    // https://github.com/infection/infection/commit/<sha>, or only the number of the pull request.
    // "(?|" numbers the groups of each alternative from the same number: they all set the named groups.
    // The number of a pull request fits the INT column of Example
    private const REFERENCE_REGEX = '~^(?|
        \#?(?<pullRequest>[1-9]\d{0,8})(?<sha>)
        |(?:https?://)?(?:www\.)?github\.com/infection/infection/pull/(?<pullRequest>[1-9]\d{0,8})(?:/(?:commits|changes|files)/(?<sha>[0-9a-f]{7,40})|/[a-z]+)?/?(?:[?\#].*)?
        |(?:https?://)?(?:www\.)?github\.com/infection/infection/commit/(?<pullRequest>)(?<sha>[0-9a-f]{7,40})/?(?:[?\#].*)?
    )$~ix';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly InfectionSourceBuilder $sourceBuilder,
        // empty for none: GitHub allows 60 requests an hour without a token, 5000 with one
        #[Autowire('%env(GITHUB_TOKEN)%')]
        private readonly string $gitHubToken = '',
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
            $commitSha = $this->findCommitOf($sha, self::DEFAULT_BRANCH, sprintf('the %s branch', self::DEFAULT_BRANCH));
        } else {
            $headSha = $this->findPullRequestHeadSha($pullRequest);
            $commitSha = $sha === '' ? $headSha : $this->findCommitOf($sha, $headSha, sprintf('pull request #%d', $pullRequest));
        }

        if ($this->findCommonCommit(Runner::CURRENT_INFECTION_VERSION, $commitSha, sprintf('Infection %s', Runner::CURRENT_INFECTION_VERSION)) === null) {
            throw new InvalidInfectionCommit(sprintf(
                'Commit %s is older than Infection %s, the release the playground runs: its pull request must be rebased.',
                substr($commitSha, 0, 7),
                Runner::CURRENT_INFECTION_VERSION,
            ));
        }

        return new InfectionCommit($commitSha, $pullRequest);
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

        $user = is_array($data['user'] ?? null) ? $data['user'] : [];

        if (($user['type'] ?? null) === 'Bot') {
            throw new InvalidInfectionCommit(sprintf(
                'Pull request #%d was opened by a bot: only the pull requests of people can be run, Infection is not sandboxed like the code it tests.',
                $pullRequest,
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
     *
     * @return string the full SHA of the commit
     */
    private function findCommitOf(string $sha, string $trustedHead, string $trustedHeadName): string
    {
        return $this->findCommonCommit($sha, $trustedHead, sprintf('Commit %s', $sha))
            ?? throw new InvalidInfectionCommit(sprintf('Commit %s is not a commit of %s.', $sha, $trustedHeadName));
    }

    /**
     * @return string|null the full SHA of $ancestor when $descendant contains it, NULL otherwise
     */
    private function findCommonCommit(string $ancestor, string $descendant, string $subject): ?string
    {
        $data = $this->request(sprintf('/compare/%s...%s?per_page=1', $ancestor, $descendant), $subject);

        $status = $data['status'] ?? null;
        $baseCommit = is_array($data['base_commit'] ?? null) ? $data['base_commit'] : [];
        $fullSha = $baseCommit['sha'] ?? null;

        if (!in_array($status, ['ahead', 'identical'], true) || !is_string($fullSha) || !InfectionCommit::isSha($fullSha)) {
            return null;
        }

        return $fullSha;
    }

    /**
     * @return array<mixed>
     */
    private function request(string $path, string $subject): array
    {
        try {
            $headers = [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'infection-playground',
            ];

            if ($this->gitHubToken !== '') {
                $headers['Authorization'] = 'Bearer ' . $this->gitHubToken;
            }

            $response = $this->httpClient->request('GET', self::API_URL . $path, [
                'headers' => $headers,
                'max_duration' => 10,
            ]);

            $statusCode = $response->getStatusCode();

            // 422 for a short SHA of several commits
            if (in_array($statusCode, [404, 422], true)) {
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
