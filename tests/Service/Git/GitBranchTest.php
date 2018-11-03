<?php

declare(strict_types=1);

namespace HubKit\Tests\Service\Git;

use HubKit\Service\Git\GitBranch;
use HubKit\Tests\GitTesterTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Style\StyleInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;

class GitBranchTest extends TestCase
{
    use GitTesterTrait;

    /** @var string */
    private $localRepository;
    /** @var string */
    private $remoteRepository;
    /** @var GitBranch */
    private $git;

    /** @before */
    public function setUpLocalRepository(): void
    {
        $this->cwd = $this->localRepository = $this->createGitDirectory($this->getTempDir() . '/git');
        $this->commitFileToRepository('foo.txt', $this->localRepository);
        $this->commitFileToRepository('diggy.txt', $this->localRepository);

        $this->remoteRepository = $this->createBareGitDirectory($this->getTempDir() . '/git2');
        $this->addRemote('origin', $this->remoteRepository, $this->localRepository);
        $this->runCliCommand(['git', 'push', 'origin', 'master'], $this->localRepository);

        $this->git = new GitBranch($this->getProcessService(), $this->createMock(StyleInterface::class));
    }

    /** @test */
    public function it_returns_up_to_date_when_up_to_date()
    {
        self::assertEquals(GitBranch::STATUS_UP_TO_DATE, $this->git->getRemoteDiffStatus('origin', 'master'));
        self::assertEquals(GitBranch::STATUS_UP_TO_DATE, $this->git->getRemoteDiffStatus('origin', 'master', 'master'));
    }

    /** @test */
    public function it_returns_needs_push_when_local_is_ahead()
    {
        $this->commitFileToRepository('hole.cmd', $this->localRepository);

        self::assertEquals(GitBranch::STATUS_NEED_PUSH, $this->git->getRemoteDiffStatus('origin', 'master'));
    }

    /** @test */
    public function it_returns_needs_pull_when_remote_is_ahead()
    {
        $this->runCliCommand(['git', 'reset', '--hard', 'HEAD@{1}'], $this->localRepository);

        self::assertEquals(GitBranch::STATUS_NEED_PULL, $this->git->getRemoteDiffStatus('origin', 'master'));
    }

    /** @test */
    public function it_returns_needs_divered_when_both_are_newer()
    {
        $this->runCliCommand(['git', 'reset', '--hard', 'HEAD@{1}'], $this->localRepository);
        $this->commitFileToRepository('something.txt', $this->localRepository);

        self::assertEquals(GitBranch::STATUS_DIVERGED, $this->git->getRemoteDiffStatus('origin', 'master'));
    }

    /** @test */
    public function it_gets_the_active_branch_name()
    {
        self::assertEquals('master', $this->git->getActiveBranchName());
    }

    /** @test */
    public function it_cannot_get_active_branch_when_in_deteached_head()
    {
        $this->runCliCommand(['git', 'checkout', 'HEAD@{1}']);

        $this->expectException('RuntimeException');
        $this->expectExceptionMessage(
            'You are currently in a detached HEAD state, ' .
            'unable to get active branch-name.Please run `git checkout` first.'
        );

        self::assertEquals('master', $this->git->getActiveBranchName());
    }

    /** @test */
    public function it_cannot_get_latest_tag_if_none_exists()
    {
        $this->expectException(ProcessFailedException::class);
        $this->expectExceptionMessage('fatal: No names found, cannot describe anything.');

        self::assertEquals('master', $this->git->getLastTagOnBranch());
    }

    /** @test */
    public function it_gets_latest_tag()
    {
        $this->runCliCommand(['git', 'tag', 'v1.0']);
        $this->commitFileToRepository('you', $this->localRepository);
        $this->runCliCommand(['git', 'tag', 'v1.1']);

        self::assertEquals('v1.1', $this->git->getLastTagOnBranch());
    }

    /** @test */
    public function it_gets_versioned_branches_in_correct_order()
    {
        $this->addRemote('upstream', $this->remoteRepository);
        $this->givenRemoteBranchesExist(['1.0', 'v1.1', '2.0', 'x.1']);

        self::assertSame(['1.0', 'v1.1', '2.0'], $this->git->getVersionBranches('origin'));
        self::assertSame([], $this->git->getVersionBranches('upstream'));
    }

    /** @test */
    public function it_returns_whether_remote_branch_exists()
    {
        $this->setUpstreamRepository();
        $this->givenRemoteBranchesExist(['2.0', '1.x']);
        $this->givenRemoteBranchesExist(['3.0'], 'upstream');

        self::assertTrue($this->git->remoteBranchExists('origin', 'master'));
        self::assertTrue($this->git->remoteBranchExists('origin', '1.x'));
        self::assertTrue($this->git->remoteBranchExists('origin', '2.0'));

        self::assertFalse($this->git->remoteBranchExists('origin', '3.0'));
        self::assertTrue($this->git->remoteBranchExists('upstream', '3.0'));
        self::assertFalse($this->git->remoteBranchExists('upstream', '1.x'));
        self::assertFalse($this->git->remoteBranchExists('upstream', '2.0'));
    }

    /** @test */
    public function it_returns_whether_local_branch_exists()
    {
        $this->givenLocalBranchesExist(['2.0', '1.x']);

        self::assertTrue($this->git->branchExists('master'));
        self::assertTrue($this->git->branchExists('2.0'));
        self::assertTrue($this->git->branchExists('1.x'));
        self::assertFalse($this->git->branchExists('3.0'));
    }
}
