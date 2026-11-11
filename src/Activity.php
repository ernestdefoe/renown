<?php

namespace ErnestDefoe\Renown;

use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * What earns XP as it happens, and what gives it back.
 *
 * A post counts when it's a visible, approved comment in a public discussion
 * outside the excluded tags. Private discussions (FoF Byobu) never count.
 * Taking XP back is exact: the log rows a post produced go, nothing else.
 */
class Activity
{
    /** Every source whose subject is a post id. */
    public const POST_SOURCES = ['reply', 'discussion', 'like', 'best_answer', 'answer_upvote'];

    public function __construct(
        private readonly Ledger $ledger,
        private readonly Settings $settings,
        private readonly ConnectionInterface $db,
        private readonly ExtensionManager $extensions,
    ) {
    }

    /** A new comment, or one that was just approved. */
    public function posted(Post $post): void
    {
        $author = $post->user;
        if (! $author || ! $this->counts($post)) {
            return;
        }

        $discussion = $post->discussion;
        $first = (int) $post->id === (int) $discussion->first_post_id || (int) $post->number === 1;
        $source = $first ? 'discussion' : 'reply';

        if (! $first && mb_strlen(self::ownWords((string) $post->content)) < $this->settings->int('min_length', 20)) {
            $this->visited($author);

            return;
        }

        $this->ledger->award($author, $source, (int) $post->id, 0, $this->settings->xp($source), ['tags' => $this->tagIds($discussion)]);
        $this->visited($author);
    }

    public function liked(Post $post, User $liker): void
    {
        $author = $post->user;
        if (! $author || (int) $author->id === (int) $liker->id || ! $this->counts($post)) {
            return;
        }

        $this->ledger->award($author, 'like', (int) $post->id, (int) $liker->id, $this->settings->xp('like'), ['tags' => $this->tagIds($post->discussion)]);
    }

    public function unliked(Post $post, User $liker): void
    {
        $this->ledger->revoke(fn ($q) => $q->where('source', 'like')->where('subject_id', $post->id)->where('actor_id', $liker->id));
    }

    /** FoF Best Answer or Answers accepted this post. */
    public function bestAnswer(Post $post): void
    {
        $author = $post->user;
        if (! $author || ! $this->counts($post)) {
            return;
        }

        $this->ledger->award($author, 'best_answer', (int) $post->id, 0, $this->settings->xp('best_answer'), ['tags' => $this->tagIds($post->discussion)]);
    }

    public function notBestAnswer(Post $post): void
    {
        $this->ledger->revoke(fn ($q) => $q->where('source', 'best_answer')->where('subject_id', $post->id));
    }

    /** An Answers upvote on this member's post, by the voter. */
    public function upvoted(Post $post, int $voterId): void
    {
        $author = $post->user;
        if (! $author || (int) $author->id === $voterId || ! $this->counts($post)) {
            return;
        }

        $this->ledger->award($author, 'answer_upvote', (int) $post->id, $voterId, $this->settings->xp('answer_upvote'), ['tags' => $this->tagIds($post->discussion)]);
    }

    /** The member's first visit of the (UTC) day; then their Cadence streak, if it's alive today. */
    public function visited(User $user): void
    {
        $today = Curve::day();
        if ((int) $user->getAttribute('renown_day') !== $today) {
            $this->db->table('users')->where('id', $user->id)->update(['renown_day' => $today]);
            $user->setAttribute('renown_day', $today);
            $user->syncOriginalAttribute('renown_day');
            $this->ledger->award($user, 'visit', $today, 0, $this->settings->xp('visit'));
        }

        $this->streak($user, $today);
    }

    /**
     * Cadence: a day the member's flame was fed. Cadence fires no event, so
     * this looks once at its row after the member visits or posts, until the
     * day is counted (`renown_streak_day`).
     */
    public function streak(User $user, ?int $today = null): void
    {
        $today ??= Curve::day();
        if ((int) $user->getAttribute('renown_streak_day') === $today || $this->settings->xp('streak') <= 0 || ! $this->extensions->isEnabled('ernestdefoe-cadence')) {
            return;
        }

        $row = $this->db->table('cadence_streaks')->where('user_id', $user->id)->first(['current', 'fed_at']);
        if (! $row || (int) $row->current < 1 || ! $row->fed_at || Curve::day(new \DateTimeImmutable((string) $row->fed_at)) !== $today) {
            return;
        }

        $this->db->table('users')->where('id', $user->id)->update(['renown_streak_day' => $today]);
        $user->setAttribute('renown_streak_day', $today);
        $user->syncOriginalAttribute('renown_streak_day');
        $this->ledger->award($user, 'streak', $today, 0, $this->settings->xp('streak'));
    }

    public function kudos(User $receiver, int $kudosId, ?int $giverId): void
    {
        $this->ledger->award($receiver, 'kudos', $kudosId, (int) $giverId, $this->settings->xp('kudos'));
    }

    public function kudosRemoved(int $kudosId): void
    {
        $this->ledger->revoke(fn ($q) => $q->where('source', 'kudos')->where('subject_id', $kudosId));
    }

    /**
     * A post was hidden or deleted (or its discussion was): everything it earned goes.
     *
     * @param list<int> $postIds
     */
    public function takeBack(array $postIds): void
    {
        foreach (array_chunk($postIds, 500) as $chunk) {
            $this->ledger->revoke(fn ($q) => $q->whereIn('source', self::POST_SOURCES)->whereIn('subject_id', $chunk));
        }
    }

    /**
     * A post came back: it earns again what it earned before, outside the
     * day's caps (it was earned already), with its likes, upvotes and best answer.
     */
    public function restore(Post $post): void
    {
        $author = $post->user;
        if (! $author || ! $this->counts($post)) {
            return;
        }

        $discussion = $post->discussion;
        $first = (int) $post->id === (int) $discussion->first_post_id || (int) $post->number === 1;
        $at = $post->created_at;
        $uncapped = ['capped' => false, 'at' => $at];

        $this->ledger->award($author, $first ? 'discussion' : 'reply', (int) $post->id, 0, $this->settings->xp($first ? 'discussion' : 'reply'), $uncapped);

        if ($this->extensions->isEnabled('flarum-likes')) {
            foreach ($this->db->table('post_likes')->where('post_id', $post->id)->where('user_id', '<>', $author->id)->pluck('user_id') as $liker) {
                $this->ledger->award($author, 'like', (int) $post->id, (int) $liker, $this->settings->xp('like'), $uncapped);
            }
        }

        if ($this->extensions->isEnabled('ernestdefoe-answers')) {
            foreach ($this->db->table('answers_votes')->where('post_id', $post->id)->where('value', '>', 0)->where('user_id', '<>', $author->id)->pluck('user_id') as $voter) {
                $this->ledger->award($author, 'answer_upvote', (int) $post->id, (int) $voter, $this->settings->xp('answer_upvote'), $uncapped);
            }
        }

        if ($this->isBestAnswer($post)) {
            $this->ledger->award($author, 'best_answer', (int) $post->id, 0, $this->settings->xp('best_answer'), $uncapped);
        }
    }

    /** Whether this post may earn XP at all. */
    public function counts(Post $post): bool
    {
        if (! $post instanceof CommentPost || $post->hidden_at || ! $post->user_id) {
            return false;
        }
        if ($post->getAttribute('is_approved') === false || $post->getAttribute('is_approved') === 0) {
            return false;
        }

        $discussion = $post->discussion;
        if (! $discussion || $discussion->hidden_at || $discussion->getAttribute('is_private')) {
            return false;
        }

        $excluded = $this->settings->excludedTags();

        return ! $excluded || ! array_intersect($excluded, $this->tagIds($discussion) ?? []);
    }

    /** @return list<int>|null the discussion's tags, or null without Tags */
    public function tagIds(?Discussion $discussion): ?array
    {
        if (! $discussion || ! $this->extensions->isEnabled('flarum-tags')) {
            return null;
        }

        return array_map('intval', $discussion->tags()->pluck('tags.id')->all());
    }

    private function isBestAnswer(Post $post): bool
    {
        $discussion = $post->discussion;

        return ($this->extensions->isEnabled('fof-best-answer') && (int) $discussion->getAttribute('best_answer_post_id') === (int) $post->id)
            || ($this->extensions->isEnabled('ernestdefoe-answers') && (int) $discussion->getAttribute('answers_accepted_post_id') === (int) $post->id);
    }

    /** The member's own words: quotes (Markdown `>` lines and BBCode [quote]) don't count toward the minimum length. */
    public static function ownWords(string $text): string
    {
        $text = (string) preg_replace('~\[quote[^\]]*\].*?\[/quote\]~is', '', $text);
        $text = (string) preg_replace('~^\s*>.*$~m', '', $text);

        return trim((string) preg_replace('~\s+~u', ' ', $text));
    }
}
