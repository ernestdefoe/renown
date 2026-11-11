<?php

namespace ErnestDefoe\Renown\Listener;

use ErnestDefoe\Renown\Activity;
use ErnestDefoe\Renown\Curve;
use ErnestDefoe\Renown\Event\LevelChanged;
use ErnestDefoe\Renown\Event\PrestigeTaken;
use ErnestDefoe\Renown\Notification\PrestigeReadyBlueprint;
use ErnestDefoe\Renown\Rewards;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event as DiscussionEvent;
use Flarum\Notification\NotificationSyncer;
use Flarum\Post\Event as PostEvent;
use Flarum\Post\Post;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Core's events: posts earn, hidden or deleted posts give it back, restored
 * ones earn it again. Level changes carry group rewards and the "Prestige is
 * ready" alert. Optional extensions' events are wired in extend.php, only
 * while that extension is enabled.
 */
class Subscriber
{
    public function __construct(
        private readonly Activity $activity,
        private readonly Rewards $rewards,
        private readonly NotificationSyncer $notifications,
    ) {
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(PostEvent\Posted::class, function (PostEvent\Posted $event) {
            // Waiting for approval: it earns when a moderator approves it (Approval's event).
            if ($event->post->getAttribute('is_approved') !== false) {
                $this->activity->posted($event->post);
            }
        });
        $events->listen(PostEvent\Hidden::class, fn (PostEvent\Hidden $event) => $this->activity->takeBack([(int) $event->post->id]));
        $events->listen(PostEvent\Deleted::class, fn (PostEvent\Deleted $event) => $this->activity->takeBack([(int) $event->post->id]));
        $events->listen(PostEvent\Restored::class, fn (PostEvent\Restored $event) => $this->activity->restore($event->post));

        $events->listen(DiscussionEvent\Hidden::class, fn (DiscussionEvent\Hidden $event) => $this->activity->takeBack($this->postIds($event->discussion)));
        // Before the posts go with it: afterwards there's nothing left to look up.
        $events->listen(DiscussionEvent\Deleting::class, fn (DiscussionEvent\Deleting $event) => $this->activity->takeBack($this->postIds($event->discussion)));
        $events->listen(DiscussionEvent\Restored::class, function (DiscussionEvent\Restored $event) {
            $event->discussion->hidden_at = null;
            foreach ($event->discussion->posts()->where('type', 'comment')->whereNull('hidden_at')->get() as $post) {
                /** @var Post $post */
                $post->setRelation('discussion', $event->discussion);
                $this->activity->restore($post);
            }
        });

        $events->listen(LevelChanged::class, function (LevelChanged $event) {
            if (Curve::rank($event->from) !== Curve::rank($event->to)) {
                $this->rewards->sync($event->user);
            }
            if (! $event->quiet && $event->to >= Curve::MAX_LEVEL && $event->from < Curve::MAX_LEVEL && (int) $event->user->getAttribute('renown_prestige') < Curve::ETERNAL) {
                $this->notifications->sync(new PrestigeReadyBlueprint($event->user, (int) $event->user->getAttribute('renown_prestige') + 1), [$event->user]);
            }
        });
        $events->listen(PrestigeTaken::class, fn (PrestigeTaken $event) => $this->rewards->sync($event->user));
    }

    /** @return list<int> */
    private function postIds(Discussion $discussion): array
    {
        return $discussion->posts()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
