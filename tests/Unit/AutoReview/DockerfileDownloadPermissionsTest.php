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

namespace App\Tests\Unit\AutoReview;

use function file_get_contents;
use function in_array;
use PHPUnit\Framework\TestCase;
use function preg_match_all;
use const PREG_SET_ORDER;
use function sprintf;
use function str_replace;

/**
 * ADD of a URL makes the file 0600 and owned by root. The prod image runs as www-data,
 * so a file copied from a download stage must set its permissions, or www-data cannot read it.
 */
final class DockerfileDownloadPermissionsTest extends TestCase
{
    public function test_the_files_copied_from_the_download_stages_set_their_permissions(): void
    {
        $dockerfile = (string) file_get_contents(__DIR__ . '/../../../Dockerfile');

        preg_match_all('/^FROM (\S+) AS (\S+)$/m', $dockerfile, $stageMatches, PREG_SET_ORDER);

        // the download stages start from scratch, the per-architecture ones are picked by an alias stage
        $downloadStages = [];

        foreach ($stageMatches as [, $base, $stage]) {
            if ($base === 'scratch' || in_array(str_replace('${TARGETARCH}', 'amd64', $base), $downloadStages, true)) {
                $downloadStages[] = $stage;
            }
        }

        preg_match_all('/^COPY .*--from=(\S+) .*$/m', $dockerfile, $copyMatches, PREG_SET_ORDER);

        $copiedStages = [];

        foreach ($copyMatches as [$copy, $stage]) {
            if (!in_array($stage, $downloadStages, true)) {
                continue;
            }

            self::assertMatchesRegularExpression('/ --chmod=\d+ /', $copy, sprintf('"%s" must set --chmod', $copy));
            $copiedStages[] = $stage;
        }

        self::assertSame(['tailwind', 'phpstan'], $copiedStages);
    }
}
