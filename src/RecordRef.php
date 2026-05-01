<?php

declare(strict_types=1);

namespace Gimucco\Bluesky;

use Stringable;

/**
 * Marker interface for the five reference types returned by record-creating
 * convenience methods (PostRef, FollowRef, LikeRef, RepostRef, BlockRef).
 *
 * The `un-X` methods (deletePost, unfollow, unlike, unrepost, unblock) accept
 * `string|AtUri|RecordRef` as their target — this interface lets them reject
 * unrelated Stringable types like Did or Handle that would otherwise compile
 * but produce confusing server errors.
 *
 * `__toString()` returns the record's AT-URI.
 */
interface RecordRef extends Stringable {}
