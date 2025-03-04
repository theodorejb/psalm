<?php

declare(strict_types=1);

namespace Psalm\SourceControl\Git;

use Override;
use Psalm\SourceControl\SourceControlInfo;

/**
 * Data represents "git" of Coveralls API.
 *
 * "git": {
 *   "head": {
 *     "id": "b31f08d07ae564b08237e5a336e478b24ccc4a65",
 *     "author_name": "Nick Merwin",
 *     "author_email": "...",
 *     "committer_name": "Nick Merwin",
 *     "committer_email": "...",
 *     "message": "version bump"
 *   },
 *   "branch": "master",
 *   "remotes": [
 *     {
 *       "name": "origin",
 *       "url": "git@github.com:lemurheavy/coveralls-ruby.git"
 *     }
 *   ]
 * }
 *
 * @author Kitamura Satoshi <with.no.parachute@gmail.com>
 */
final class GitInfo extends SourceControlInfo
{
    /**
     * Constructor.
     *
     * @param string $branch  branch name
     * @param CommitInfo $head    HEAD commit
     * @param RemoteInfo[]  $remotes remote repositories
     */
    public function __construct(
        protected string $branch,
        protected CommitInfo $head,
        /**
         * Remote.
         */
        protected array $remotes,
    ) {
    }

    #[Override]
    public function toArray(): array
    {
        $remotes = [];

        foreach ($this->remotes as $remote) {
            $remotes[] = $remote->toArray();
        }

        return [
            'branch' => $this->branch,
            'head' => $this->head->toArray(),
            'remotes' => $remotes,
        ];
    }

    // accessor

    /**
     * Return branch name.
     */
    public function getBranch(): string
    {
        return $this->branch;
    }

    /**
     * Return HEAD commit.
     */
    public function getHead(): CommitInfo
    {
        return $this->head;
    }

    /**
     * Return remote repositories.
     *
     * @return RemoteInfo[]
     */
    public function getRemotes(): array
    {
        return $this->remotes;
    }
}
