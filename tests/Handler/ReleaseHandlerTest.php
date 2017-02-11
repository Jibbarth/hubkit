<?php

declare(strict_types=1);

/*
 * This file is part of the HubKit package.
 *
 * (c) Sebastiaan Stok <s.stok@rollerscapes.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace HubKit\Tests\Handler;

use HubKit\Cli\Handler\ReleaseHandler;
use HubKit\Service\CliProcess;
use HubKit\Service\Editor;
use HubKit\Service\Git;
use HubKit\Service\GitHub;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument as PropArgument;
use Prophecy\Prophecy\ObjectProphecy;
use Symfony\Component\Process\Process;
use Webmozart\Console\Api\Args\Args;
use Webmozart\Console\Api\Args\Format\ArgsFormat;
use Webmozart\Console\Api\Args\Format\Argument;
use Webmozart\Console\Api\Args\Format\Option;
use Webmozart\Console\Args\StringArgs;
use Webmozart\Console\IO\BufferedIO;

class ReleaseHandlerTest extends TestCase
{
    use SymfonyStyleTrait;

    const COMMITS = [
        [
            'sha' => '2bee3b83a9b0073497f37acd4f0920ef61945552',
            'author' => 'Sebastiaan Stok <s.stok@rollerscapes.net>',
            'subject' => 'feature #93 Introduce a new API for ValuesBag (sstok)',
            'message' => 'This PR was merged into the master branch.

                Discussion
                ----------
            
                |Q            |A  |
                |---          |---|
                |Bug Fix?     |no |
                |New Feature? |yes|
                |BC Breaks?   |no |
                |Deprecations?|yes|
                |Fixed Tickets|   |
                |License      |MIT|
            
                Commits
                -------
            
                1b04532c8a09d9084abce36f8d9daf675f89eacc Introduce a new API for ValuesBag',
        ],
        [
            'sha' => 'd22220c0a97a5fc0ff4e0a0e595247919b89bfa0',
            'author' => 'Sebastiaan Stok <s.stok@rollerscapes.net>',
            'subject' => 'minor #56 Clean up (sstok)',
            'message' => 'This PR was merged into the 1.0-dev branch.

                9b67df3871e871084d0ebbf1e0db639d552fc7eb commit 1',
        ],
        [
            'sha' => 'd2222010a97a5fc0ff4e0a0e595247919b89bfa0',
            'author' => 'Sebastiaan Stok <s.stok@rollerscapes.net>',
            'subject' => 'feature #55 Great new architecture (sstok, someone)',
            'message' => 'This PR was merged into the 1.0-dev branch.
labels: deprecation , removed-deprecation

9b67df3871e871084d0ebbf1e0db639d552fc7eb commit 1',
        ],
        [
            'sha' => 'd2222010a97a5fc0ff4e0a0e595247919b89bfa0',
            'author' => 'Sebastiaan Stok <s.stok@rollerscapes.net>',
            'subject' => 'refactor #52 Removed deprecated API (sstok)',
            'message' => 'This PR was merged into the 1.0-dev branch.
labels: removed-deprecation

9b67df3871e871084d0abef1e0db639d552fc7e commit 2',
        ],
        [
            'sha' => 'd22220c0a97a666fc0ff4e0a0e5247919b89bfa0',
            'author' => 'Sebastiaan Stok <s.stok@rollerscapes.net>',
            'subject' => 'Merge pull request #50 from sstok/docs-cleanup',
            'message' => 'testing #50 Diddly (sstok)',
        ],
    ];

    /** @var ObjectProphecy */
    private $git;
    /** @var ObjectProphecy */
    private $github;
    /** @var ObjectProphecy */
    private $process;
    /** @var ObjectProphecy */
    private $editor;
    /** @var BufferedIO */
    private $io;

    /** @before */
    public function setUpCommandHandler()
    {
        $this->git = $this->prophesize(Git::class);
        $this->git->getActiveBranchName()->willReturn('master');

        $this->github = $this->prophesize(GitHub::class);
        $this->github->getHostname()->willReturn('github.com');
        $this->github->getOrganization()->willReturn('park-manager');
        $this->github->getRepository()->willReturn('hubkit');

        $this->process = $this->prophesize(CliProcess::class);
        $this->editor = $this->prophesize(Editor::class);

        $this->io = new BufferedIO();
        $this->io->setInteractive(true);
    }

    /** @test */
    public function it_creates_a_new_release_for_current_branch_without_existing_tags()
    {
        $this->expectTags();
        $this->expectMatchingVersionBranchNotExists();
        $this->expectEditorReturns('Initial release.');

        $url = $this->expectTagAndGitHubRelease('1.0.0', 'Initial release.');

        $args = $this->getArgs('1.0');
        $this->executeHandler($args);

        $this->assertOutputMatches(
            [
                'Preparing release 1.0.0 (target branch master)',
                'Please wait...',
                'Successfully released 1.0.0',
                $url,
            ]
        );
    }

    /** @test */
    public function it_creates_a_new_release_for_current_branch_with_existing_tags_and_no_gap()
    {
        $this->expectTags(['0.1.0', '1.0.0-BETA1']);
        $this->git->getLastTagOnBranch()->willReturn('1.0.0-BETA1');
        $this->expectMatchingVersionBranchNotExists();

        $this->git->getLogBetweenCommits('1.0.0-BETA1', 'master')->willReturn(self::COMMITS);

        $this->expectEditorReturns("### Added\n- Introduce a new API for ValuesBag");

        $url = $this->expectTagAndGitHubRelease('1.0.0', "### Added\n- Introduce a new API for ValuesBag");

        $args = $this->getArgs('1.0');
        $this->executeHandler($args);

        $this->assertOutputMatches(
            [
                'Preparing release 1.0.0 (target branch master)',
                'Please wait...',
                'Successfully released 1.0.0',
                $url,
            ]
        );
    }

    /** @test */
    public function it_asks_confirmation_when_there_is_a_gap_and_continues_when_confirmed()
    {
        $this->expectTags(['0.1.0', '1.0.0-BETA1']);
        $this->git->getLastTagOnBranch()->willReturn('1.0.0-BETA1');
        $this->expectMatchingVersionBranchNotExists('3.0');

        $this->git->getLogBetweenCommits('1.0.0-BETA1', 'master')->willReturn(self::COMMITS);

        $this->expectEditorReturns("### Added\n- Introduce a new API for ValuesBag");

        $url = $this->expectTagAndGitHubRelease('3.0.0', "### Added\n- Introduce a new API for ValuesBag");

        $args = $this->getArgs('3.0');
        $this->executeHandler($args, ['yes']);

        $this->assertOutputMatches(
            [
                'It appears there is gap compared to the last version.',
                'Expected one of : 2.0.0-ALPHA1, 2.0.0-BETA1, 2.0.0',
                'Please confirm your input is correct. (yes/no) [no]:',
                'Preparing release 3.0.0 (target branch master)',
                'Please wait...',
                'Successfully released 3.0.0',
                $url,
            ]
        );
    }

    /** @test */
    public function it_asks_confirmation_when_there_is_a_gap_and_aborts_when_rejected()
    {
        $this->expectTags(['0.1.0', '1.0.0-BETA1']);
        $this->git->getLastTagOnBranch()->willReturn('1.0.0-BETA1');
        $this->expectMatchingVersionBranchNotExists('3.0');

        $this->expectException('RuntimeException');
        $this->expectExceptionMessage('User aborted.');

        $args = $this->getArgs('3.0');
        $this->executeHandler($args); // default answer is no
    }

    /** @test */
    public function it_asks_confirmation_when_target_branch_has_a_mismatch_and_continues_when_confirmed()
    {
        $this->git->getActiveBranchName()->willReturn('2.0');

        $this->expectTags(['0.1.0', '1.0.0', '2.0.0']);
        $this->git->getLastTagOnBranch()->willReturn('2.0.0');
        $this->expectMatchingVersionBranchExists('3.0');

        $this->git->getLogBetweenCommits('2.0.0', '2.0')->willReturn(self::COMMITS);

        $this->expectEditorReturns("### Added\n- Introduce a new API for ValuesBag");

        $url = $this->expectTagAndGitHubRelease('3.0.0', "### Added\n- Introduce a new API for ValuesBag");

        $args = $this->getArgs('3.0');
        $this->executeHandler($args, ['yes']);

        $this->assertOutputMatches(
            [
                'This release will be created for the "2.0" branch.',
                'But a branch with version pattern "3.0" exists, did you target the correct branch?',
                'Please confirm your input is correct. (yes/no) [no]:',
                'Preparing release 3.0.0 (target branch 2.0)',
                'Please wait...',
                'Successfully released 3.0.0',
                $url,
            ]
        );
    }

    /** @test */
    public function it_asks_confirmation_when_target_branch_has_a_mismatch_and_aborts_when_rejected()
    {
        $this->git->getActiveBranchName()->willReturn('2.0');
        $this->git->getActiveBranchName()->willReturn('2.0');

        $this->expectTags(['0.1.0', '1.0.0', '2.0.0']);
        $this->git->getLastTagOnBranch()->willReturn('2.0.0');
        $this->expectMatchingVersionBranchExists('3.0');

        $this->expectException('RuntimeException');
        $this->expectExceptionMessage('User aborted.');

        $args = $this->getArgs('3.0');
        $this->executeHandler($args); // default answer is no
    }

    /** @test */
    public function it_creates_a_new_release_with_a_relative_version()
    {
        $this->expectTags(['0.1.0', '1.0.0', '2.0.0']);
        $this->git->getLastTagOnBranch()->willReturn('2.5.0');
        $this->expectMatchingVersionBranchNotExists('3.0');

        $this->git->getLogBetweenCommits('2.5.0', 'master')->willReturn(self::COMMITS);

        $this->expectEditorReturns("### Added\n- Introduce a new API for ValuesBag", 'Drop support for older PHP versions');

        $url = $this->expectTagAndGitHubRelease('3.0.0', 'Drop support for older PHP versions');

        $args = $this->getArgs('major');
        $this->executeHandler($args);

        $this->assertOutputMatches(
            [
                'Preparing release 3.0.0 (target branch master)',
                'Please wait...',
                'Successfully released 3.0.0',
                $url,
            ]
        );
    }

    private function getArgs(string $version): Args
    {
        $format = ArgsFormat::build()
            ->addOption(new Option('all-categories', null, Option::NO_VALUE | Option::BOOLEAN))
            ->addOption(new Option('no-edit', null, Option::NO_VALUE | Option::BOOLEAN))
            ->addOption(new Option('pre-release', null, Option::BOOLEAN))
            ->addArgument(new Argument('version', Argument::REQUIRED | Argument::STRING))
            ->getFormat()
        ;

        $args = new Args($format, new StringArgs(''));
        $args->setArgument('version', $version);

        return $args;
    }

    private function executeHandler(Args $args, array $input = ['no'])
    {
        $style = $this->createStyle($input);
        $handler = new ReleaseHandler(
            $style,
            $this->git->reveal(),
            $this->github->reveal(),
            $this->process->reveal(),
            $this->editor->reveal()
        );

        $handler->handle($args, $this->io);
    }

    private function expectTags(array $tags = [])
    {
        $process = $this->prophesize(Process::class);
        $process->getOutput()->willReturn(implode("\n", $tags));

        $this->process->mustRun('git tag --list')->willReturn($process->reveal());
    }

    private function expectMatchingVersionBranchExists(string $branch = '1.0')
    {
        $this->git->remoteBranchExists('upstream', $branch)->willReturn(true);
    }

    private function expectMatchingVersionBranchNotExists(string $branch = '1.0')
    {
        $this->git->remoteBranchExists('upstream', $branch)->willReturn(false);
    }

    private function expectTagAndGitHubRelease(string $version, string $message): string
    {
        $this->process->mustRun(['git', 'tag', '-s', 'v'.$version, '-m', 'Release '.$version])->shouldBeCalled();
        $this->process->mustRun(['git', 'push', '--tags', 'upstream'])->shouldBeCalled();

        $this->github->createRelease('v'.$version, $message, false)->willReturn(
            ['html_url' => $url = 'https://github.com/park-manager/hubkit/releases/tag/v'.$version]
        );

        return $url;
    }

    private function expectEditorReturns(string $input, string $output = null)
    {
        $this->editor->fromString(PropArgument::containingString($input), true, PropArgument::containingString('Leave file empty to abort operation.'))
            ->willReturn($output ?? $input);
    }
}
