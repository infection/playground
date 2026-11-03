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

namespace App\Tests\Unit\Infection\Build;

use App\Infection\Build\InfectionCommit;
use App\Infection\Build\InfectionCommitResolver;
use App\Infection\Build\InfectionSourceBuilder;
use App\Infection\Build\InvalidInfectionCommit;
use App\Infection\Runner;
use Generator;
use function json_encode;
use const JSON_THROW_ON_ERROR;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use function preg_grep;
use function sprintf;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class InfectionCommitResolverTest extends TestCase
{
    private const API_URL = 'https://api.github.com/repos/infection/infection';

    private const HEAD_SHA = 'dbc3013fc473271b4bd86f5ec103702a19d86e83';

    private const OLDER_SHA = 'c4fbaf5da98747b963977cdeb9d3f8e8d60e1621';

    /**
     * @return Generator<string, array{string}>
     */
    public static function providePullRequestLinks(): Generator
    {
        yield 'link' => ['https://github.com/infection/infection/pull/3717'];

        yield 'link to its files' => ['https://github.com/infection/infection/pull/3717/files'];

        yield 'link with a review anchor' => ['https://github.com/infection/infection/pull/3717#pullrequestreview-5758867273'];

        yield 'number' => ['3717'];

        yield 'number with a hash' => [' #3717 '];
    }

    #[DataProvider('providePullRequestLinks')]
    public function test_it_resolves_a_pull_request_to_its_head(string $link): void
    {
        $httpClient = new MockHttpClient([
            self::pullRequestResponse('infection/infection'),
            self::releaseResponse(),
        ]);

        $commit = $this->createResolver($httpClient)->resolve($link);

        self::assertCommit(self::HEAD_SHA, 3717, $commit);
        self::assertSame(2, $httpClient->getRequestsCount());
    }

    public function test_it_asks_the_api_of_github(): void
    {
        $pullRequestResponse = self::pullRequestResponse('infection/infection');

        $this->createResolver(new MockHttpClient([$pullRequestResponse, self::releaseResponse()]))->resolve('3717');

        self::assertSame(self::API_URL . '/pulls/3717', $pullRequestResponse->getRequestUrl());
        $options = $pullRequestResponse->getRequestOptions();
        self::assertIsArray($options['headers']);
        self::assertContains('Accept: application/vnd.github+json', $options['headers']);
        self::assertContains('User-Agent: infection-playground', $options['headers']);
        self::assertSame([], preg_grep('/^Authorization:/', $options['headers']));
        self::assertSame(10.0, $options['max_duration']);
    }

    public function test_it_authenticates_with_a_token(): void
    {
        $pullRequestResponse = self::pullRequestResponse('infection/infection');

        $this->createResolver(new MockHttpClient([$pullRequestResponse, self::releaseResponse()]), gitHubToken: 'github_pat_test')->resolve('3717');

        $options = $pullRequestResponse->getRequestOptions();
        self::assertIsArray($options['headers']);
        self::assertContains('Authorization: Bearer github_pat_test', $options['headers']);
    }

    public function test_it_refuses_a_pull_request_a_bot_opened(): void
    {
        $resolver = $this->createResolver(new MockHttpClient([
            self::pullRequestResponse('infection/infection', 'Bot'),
        ]));

        $this->expectException(InvalidInfectionCommit::class);
        $this->expectExceptionMessage('Pull request #3717 was opened by a bot: only the pull requests of people can be run, Infection is not sandboxed like the code it tests.');

        $resolver->resolve('3717');
    }

    #[DataProvider('provideStatusesOfAnotherBranch')]
    public function test_it_refuses_a_commit_older_than_the_release_the_playground_runs(string $status): void
    {
        $releaseResponse = self::compareResponse($status, self::OLDER_SHA);
        $resolver = $this->createResolver(new MockHttpClient([
            self::pullRequestResponse('infection/infection'),
            $releaseResponse,
        ]));

        try {
            $resolver->resolve('3717');

            self::fail('The commit should be refused.');
        } catch (InvalidInfectionCommit $exception) {
            self::assertSame(
                sprintf('Commit dbc3013 is older than Infection %s, the release the playground runs: its pull request must be rebased.', Runner::CURRENT_INFECTION_VERSION),
                $exception->getMessage(),
            );
        }

        self::assertSame(
            sprintf('%s/compare/%s...%s?per_page=1', self::API_URL, Runner::CURRENT_INFECTION_VERSION, self::HEAD_SHA),
            $releaseResponse->getRequestUrl(),
        );
    }

    public function test_it_needs_the_head_commit_of_the_pull_request(): void
    {
        $resolver = $this->createResolver(new MockHttpClient([
            self::jsonResponse(['head' => ['sha' => 'dbc3013', 'repo' => ['full_name' => 'infection/infection']]]),
        ]));

        $this->expectException(InvalidInfectionCommit::class);
        $this->expectExceptionMessage('GitHub did not return the head commit of pull request #3717.');

        $resolver->resolve('3717');
    }

    /**
     * @return Generator<string, array{array<string, mixed>}>
     */
    public static function provideBaseCommitsWithoutSha(): Generator
    {
        yield 'no SHA' => [[]];

        yield 'not a string' => [['sha' => 123]];
    }

    /**
     * @param array<string, mixed> $baseCommit
     */
    #[DataProvider('provideBaseCommitsWithoutSha')]
    public function test_it_needs_the_full_sha_of_a_commit_the_pull_request_contains(array $baseCommit): void
    {
        $resolver = $this->createResolver(new MockHttpClient([
            self::pullRequestResponse('infection/infection'),
            self::jsonResponse(['status' => 'ahead', 'base_commit' => $baseCommit]),
        ]));

        $this->expectException(InvalidInfectionCommit::class);
        $this->expectExceptionMessage('Commit c4fbaf5 is not a commit of pull request #3717.');

        $resolver->resolve('https://github.com/infection/infection/pull/3717/commits/c4fbaf5');
    }

    public function test_a_short_sha_is_checked_even_when_a_commit_was_built(): void
    {
        $httpClient = new MockHttpClient([
            self::pullRequestResponse('infection/infection'),
            self::compareResponse('ahead', self::OLDER_SHA),
            self::releaseResponse(),
        ]);

        $commit = $this->createResolver($httpClient, isBuilt: true)
            ->resolve('https://github.com/infection/infection/pull/3717/commits/c4fbaf5');

        self::assertCommit(self::OLDER_SHA, 3717, $commit);
        self::assertSame(3, $httpClient->getRequestsCount());
    }

    public function test_it_refuses_a_pull_request_from_a_fork(): void
    {
        $resolver = $this->createResolver(new MockHttpClient([
            self::pullRequestResponse('someone/infection'),
        ]));

        $this->expectException(InvalidInfectionCommit::class);
        $this->expectExceptionMessage('Pull request #3717 is from a fork: only the pull requests from a branch of infection/infection can be run, Infection is not sandboxed like the code it tests.');

        $resolver->resolve('https://github.com/infection/infection/pull/3717');
    }

    public function test_it_refuses_a_pull_request_of_a_deleted_fork(): void
    {
        $resolver = $this->createResolver(new MockHttpClient([
            self::pullRequestResponse(null),
        ]));

        $this->expectException(InvalidInfectionCommit::class);
        $this->expectExceptionMessage('Pull request #3717 is from a fork');

        $resolver->resolve('https://github.com/infection/infection/pull/3717');
    }

    /**
     * @return Generator<string, array{string}>
     */
    public static function provideLinksToCommitsOfPullRequests(): Generator
    {
        yield 'commit' => ['https://github.com/infection/infection/pull/3717/commits/c4fbaf5'];

        yield 'changes of the commit' => ['https://github.com/infection/infection/pull/3717/changes/c4fbaf5'];

        yield 'files of the commit' => ['https://github.com/infection/infection/pull/3717/files/c4fbaf5'];
    }

    #[DataProvider('provideLinksToCommitsOfPullRequests')]
    public function test_it_resolves_a_commit_the_head_of_the_pull_request_contains(string $link): void
    {
        $compareResponse = self::compareResponse('ahead', self::OLDER_SHA);
        $releaseResponse = self::releaseResponse();
        $httpClient = new MockHttpClient([
            self::pullRequestResponse('infection/infection'),
            $compareResponse,
            $releaseResponse,
        ]);

        $commit = $this->createResolver($httpClient)->resolve($link);

        // the commit, not the head of the pull request, must contain the release
        self::assertSame(
            sprintf('%s/compare/%s...%s?per_page=1', self::API_URL, Runner::CURRENT_INFECTION_VERSION, self::OLDER_SHA),
            $releaseResponse->getRequestUrl(),
        );

        self::assertCommit(self::OLDER_SHA, 3717, $commit);
        self::assertSame(self::API_URL . '/compare/c4fbaf5...' . self::HEAD_SHA . '?per_page=1', $compareResponse->getRequestUrl());
    }

    #[DataProvider('provideStatusesOfAnotherBranch')]
    public function test_it_refuses_a_commit_the_head_of_the_pull_request_does_not_contain(string $status): void
    {
        $resolver = $this->createResolver(new MockHttpClient([
            self::pullRequestResponse('infection/infection'),
            self::compareResponse($status, self::OLDER_SHA),
        ]));

        $this->expectException(InvalidInfectionCommit::class);
        $this->expectExceptionMessage('Commit c4fbaf5 is not a commit of pull request #3717.');

        $resolver->resolve('https://github.com/infection/infection/pull/3717/commits/c4fbaf5');
    }

    /**
     * @return Generator<string, array{string}>
     */
    public static function provideStatusesOfAnotherBranch(): Generator
    {
        yield 'after the head' => ['behind'];

        yield 'on another branch' => ['diverged'];
    }

    public function test_it_resolves_a_commit_of_the_default_branch(): void
    {
        $compareResponse = self::compareResponse('identical', self::HEAD_SHA);

        $commit = $this->createResolver(new MockHttpClient([$compareResponse, self::releaseResponse()]))->resolve('https://github.com/infection/infection/commit/DBC3013');

        self::assertCommit(self::HEAD_SHA, null, $commit);
        self::assertSame(self::API_URL . '/compare/dbc3013...master?per_page=1', $compareResponse->getRequestUrl());
    }

    public function test_it_refuses_a_commit_that_is_not_on_the_default_branch(): void
    {
        $resolver = $this->createResolver(new MockHttpClient([
            self::compareResponse('diverged', self::HEAD_SHA),
        ]));

        $this->expectException(InvalidInfectionCommit::class);
        $this->expectExceptionMessage('Commit dbc3013 is not a commit of the master branch.');

        $resolver->resolve('https://github.com/infection/infection/commit/dbc3013');
    }

    public function test_a_commit_that_was_built_needs_no_request_to_github(): void
    {
        $httpClient = new MockHttpClient([]);

        $commit = $this->createResolver($httpClient, isBuilt: true)
            ->resolve('https://github.com/infection/infection/pull/3717/commits/' . self::HEAD_SHA);

        self::assertCommit(self::HEAD_SHA, 3717, $commit);
        self::assertSame(0, $httpClient->getRequestsCount());
    }

    /**
     * @return Generator<string, array{string}>
     */
    public static function provideInvalidLinks(): Generator
    {
        yield 'another repository' => ['https://github.com/someone/infection/pull/3717'];

        yield 'another host' => ['https://example.com/infection/infection/pull/3717'];

        yield 'a branch' => ['https://github.com/infection/infection/tree/master'];

        yield 'a SHA that is too short' => ['https://github.com/infection/infection/commit/dbc30'];

        yield 'text' => ['master'];

        yield 'pull request 0' => ['https://github.com/infection/infection/pull/0/commits/' . self::HEAD_SHA];

        yield 'a number of a pull request too large for the database' => ['#1234567890'];
    }

    #[DataProvider('provideInvalidLinks')]
    public function test_it_refuses_a_link_that_is_not_a_pull_request_or_a_commit(string $link): void
    {
        $httpClient = new MockHttpClient([]);

        try {
            $this->createResolver($httpClient)->resolve($link);

            self::fail('The link should be refused.');
        } catch (InvalidInfectionCommit $exception) {
            self::assertSame('Must be a link to a pull request or a commit of infection/infection.', $exception->getMessage());
        }

        self::assertSame(0, $httpClient->getRequestsCount());
    }

    /**
     * @return Generator<string, array{MockResponse, string}>
     */
    public static function provideGitHubErrors(): Generator
    {
        yield 'not found' => [new MockResponse('{}', ['http_code' => 404]), 'Pull request #3717 was not found in infection/infection.'];

        yield 'invalid' => [new MockResponse('{}', ['http_code' => 422]), 'Pull request #3717 was not found in infection/infection.'];

        yield 'rate limit' => [new MockResponse('{}', ['http_code' => 403]), 'GitHub refused the request, probably its rate limit: try again later.'];

        yield 'secondary rate limit' => [new MockResponse('{}', ['http_code' => 429]), 'GitHub refused the request, probably its rate limit: try again later.'];

        yield 'server error' => [new MockResponse('{}', ['http_code' => 502]), 'GitHub answered with HTTP 502, try again later.'];

        yield 'network error' => [new MockResponse('', ['error' => 'Connection refused']), 'GitHub could not be reached, try again later.'];
    }

    #[DataProvider('provideGitHubErrors')]
    public function test_it_explains_the_errors_of_github(MockResponse $response, string $expectedMessage): void
    {
        $resolver = $this->createResolver(new MockHttpClient([$response]));

        $this->expectException(InvalidInfectionCommit::class);
        $this->expectExceptionMessage($expectedMessage);

        $resolver->resolve('https://github.com/infection/infection/pull/3717');
    }

    private function createResolver(MockHttpClient $httpClient, bool $isBuilt = false, string $gitHubToken = ''): InfectionCommitResolver
    {
        $sourceBuilder = self::createStub(InfectionSourceBuilder::class);
        $sourceBuilder->method('isBuilt')->willReturn($isBuilt);

        return new InfectionCommitResolver($httpClient, $sourceBuilder, $gitHubToken);
    }

    private static function pullRequestResponse(?string $headRepository, string $userType = 'User'): MockResponse
    {
        return self::jsonResponse([
            'user' => ['type' => $userType],
            'head' => [
                'sha' => self::HEAD_SHA,
                'repo' => $headRepository === null ? null : ['full_name' => $headRepository],
            ],
        ]);
    }

    private static function compareResponse(string $status, string $baseSha): MockResponse
    {
        return self::jsonResponse([
            'status' => $status,
            'base_commit' => ['sha' => $baseSha],
        ]);
    }

    /**
     * The commit contains the release the playground runs.
     */
    private static function releaseResponse(): MockResponse
    {
        return self::compareResponse('ahead', 'a7f1b8c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6f7a8');
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function jsonResponse(array $data): MockResponse
    {
        return new MockResponse(json_encode($data, JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }

    private static function assertCommit(string $expectedSha, ?int $expectedPullRequest, ?InfectionCommit $commit): void
    {
        self::assertNotNull($commit);
        self::assertSame($expectedSha, $commit->sha);
        self::assertSame($expectedPullRequest, $commit->pullRequest);
    }
}
