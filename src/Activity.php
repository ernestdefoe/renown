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
        private readonly Boosts $boosts,
    ) {
    }

    /** A new comment, or one that was just approved (not the author's visit then: a moderator approved it). */
    public function posted(Post $post, bool $visit = true): void
    {
        // Approving a discussion's first post approves the discussion too, but Approval does that
        // in its own listener: until it saves, the discussion still looks private. Count it then.
        $discussion = $post->discussion;
        if (! $visit && (int) $post->number === 1 && $discussion && $discussion->getAttribute('is_approved') === false) {
            $discussion->afterSave(fn () => $this->posted($post, false));

            return;
        }

        $author = $post->user;
        if (! $author || ! $this->counts($post)) {
            return;
        }

        $first = (int) $post->id === (int) $discussion->first_post_id || (int) $post->number === 1;
        $source = $first ? 'discussion' : 'reply';

        if ($first || mb_strlen(self::ownWords((string) $post->content)) >= $this->settings->int('min_length', 20)) {
            // An approved post counts on the day it was written, and a moderator approving a batch
            // at once is no reason for a cooldown.
            $options = $visit ? [] : ['at' => $post->created_at, 'cooldown' => false];
            $this->ledger->award($author, $source, (int) $post->id, 0, $this->settings->xp($source), ['tags' => $this->boostTags($discussion)] + $options);
        }
        if ($visit) {
            $this->visited($author);
        }
    }

    public function liked(Post $post, User $liker): void
    {
        $author = $post->user;
        if (! $author || (int) $author->id === (int) $liker->id || ! $this->counts($post)) {
            return;
        }

        $this->ledger->award($author, 'like', (int) $post->id, (int) $liker->id, $this->settings->xp('like'), ['tags' => $this->boostTags($post->discussion)]);
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

        $this->ledger->award($author, 'best_answer', (int) $post->id, 0, $this->settings->xp('best_answer'), ['tags' => $this->boostTags($post->discussion)]);
    }

    /** Answers swaps the accepted answer without an "unaccepted" event: the discussion's other posts give theirs back. */
    public function onlyBestAnswer(Post $post): void
    {
        $others = $post->discussion->posts()->where('id', '<>', $post->id)->pluck('id')->all();
        foreach (array_chunk($others, 500) as $chunk) {
            $this->ledger->revoke(fn ($q) => $q->where('source', 'best_answer')->whereIn('subject_id', $chunk));
        }
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

        $this->ledger->award($author, 'answer_upvote', (int) $post->id, $voterId, $this->settings->xp('answer_upvote'), ['tags' => $this->boostTags($post->discussion)]);
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
     * A post (or its discussion) was hidden or deleted: everything it earned
     * goes. Hidden is soft, so a restore brings back exactly that.
     *
     * @param list<int> $postIds
     */
    public function takeBack(array $postIds, bool $soft = false): void
    {
        foreach (array_chunk($postIds, 500) as $chunk) {
            $this->ledger->revoke(fn ($q) => $q->whereIn('source', self::POST_SOURCES)->whereIn('subject_id', $chunk), $soft);
        }
    }

    /**
     * Posts came back (restored, or their discussion moved back into scope):
     * they get exactly what they earned before, never fresh XP, so hiding and
     * restoring can't get around the day's rules.
     *
     * @param list<int> $postIds
     */
    public function restore(array $postIds): void
    {
        foreach (array_chunk($postIds, 500) as $chunk) {
            $this->ledger->reinstate(fn ($q) => $q->whereIn('source', self::POST_SOURCES)->whereIn('subject_id', $chunk));
        }
    }

    /**
     * A discussion moved: retagged (into or out of an excluded tag) or made
     * private or public (FoF Byobu). Out of scope takes its XP back, back in
     * scope restores exactly that. Read afresh: is_private is worked out on save.
     */
    public function rescope(Discussion $discussion): void
    {
        $discussion = Discussion::query()->find($discussion->id);
        if (! $discussion) {
            return;
        }
        $ids = $discussion->posts()->whereNull('hidden_at')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->discussionCounts($discussion) ? $this->restore($ids) : $this->takeBack($ids, true);
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

        return $post->discussion && $this->discussionCounts($post->discussion);
    }

    public function discussionCounts(Discussion $discussion): bool
    {
        if ($discussion->hidden_at || $discussion->getAttribute('is_private')) {
            return false;
        }

        $excluded = $this->settings->excludedTags();

        return ! $excluded || ! array_intersect($excluded, $this->tagIds($discussion) ?? []);
    }

    /**
     * The tags a boost may care about: only looked up while a boost tied to a tag is running.
     *
     * @return list<int>|null
     */
    private function boostTags(?Discussion $discussion): ?array
    {
        foreach ($this->boosts->running() as $boost) {
            if ($boost['tag_id'] !== null) {
                return $this->tagIds($discussion);
            }
        }

        return null;
    }

    /** @return list<int>|null the discussion's tags, or null without Tags */
    public function tagIds(?Discussion $discussion): ?array
    {
        if (! $discussion || ! $this->extensions->isEnabled('flarum-tags')) {
            return null;
        }

        return array_map('intval', $discussion->tags()->pluck('tags.id')->all());
    }

    /** The member's own words: quotes (Markdown `>` lines and BBCode [quote]) don't count toward the minimum length. */
    public static function ownWords(string $text): string
    {
        $text = (string) preg_replace('~\[quote[^\]]*\].*?\[/quote\]~is', '', $text);
        $text = (string) preg_replace('~^\s*>.*$~m', '', $text);

        return trim((string) preg_replace('~\s+~u', ' ', $text));
    }
}
