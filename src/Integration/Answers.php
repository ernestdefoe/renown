<?php

namespace ErnestDefoe\Renown\Integration;

use ErnestDefoe\Renown\Activity;
use ErnestDefoe\Renown\Ledger;
use Flarum\Post\Post;
use Illuminate\Database\ConnectionInterface;

/**
 * ernestdefoe/answers: accepted answers and upvotes on answers.
 *
 * Answers tells the world about a vote only through its reputation log
 * (ReputationChanged, once per tag), so an upvote is read back from the log
 * row it just wrote, and a vote taken back is matched against the votes
 * that are still there.
 */
class Answers
{
    public function __construct(
        private readonly Activity $activity,
        private readonly Ledger $ledger,
        private readonly ConnectionInterface $db,
    ) {
    }

    public function accepted(object $event): void
    {
        $this->activity->bestAnswer($event->post);
    }

    public function unaccepted(object $event): void
    {
        $this->activity->notBestAnswer($event->post);
    }

    public function reputation(object $event): void
    {
        if ($event->reason !== 'upvote') {
            return;
        }

        if ($event->points > 0) {
            $log = $this->db->table('answers_reputation_log')->where('user_id', $event->userId)->where('reason', 'upvote')
                ->orderByDesc('id')->first(['post_id', 'actor_id']);
            if ($log && $log->post_id && $log->actor_id && ($post = Post::query()->find($log->post_id))) {
                $this->activity->upvoted($post, (int) $log->actor_id);
            }

            return;
        }

        // An upvote taken back: drop the rows whose vote is gone.
        $userId = (int) $event->userId;
        $this->ledger->revoke(fn ($q) => $q->where('user_id', $userId)->where('source', 'answer_upvote')
            ->whereNotExists(fn ($v) => $v->selectRaw('1')->from('answers_votes')
                ->whereColumn('answers_votes.post_id', 'renown_xp_log.subject_id')
                ->whereColumn('answers_votes.user_id', 'renown_xp_log.actor_id')
                ->where('answers_votes.value', '>', 0)));
    }
}
