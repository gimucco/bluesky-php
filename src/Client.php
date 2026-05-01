<?php

declare(strict_types=1);

namespace Gimucco\Bluesky;

use DateTimeImmutable;
use finfo;
use Gimucco\Atproto\Session;
use Gimucco\Bluesky\Exception\InvalidArgumentException;
use Gimucco\Bluesky\Exception\NotFoundException;
use Gimucco\Bluesky\Generated\Methods\App\Bsky\Actor;
use Gimucco\Bluesky\Generated\Methods\App\Bsky\Bookmark;
use Gimucco\Bluesky\Generated\Methods\App\Bsky\Feed;
use Gimucco\Bluesky\Generated\Methods\App\Bsky\Graph;
use Gimucco\Bluesky\Generated\Methods\App\Bsky\Labeler;
use Gimucco\Bluesky\Generated\Methods\App\Bsky\Notification;
use Gimucco\Bluesky\Generated\Methods\App\Bsky\Video;
use Gimucco\Bluesky\Generated\Methods\Com\Atproto\Identity;
use Gimucco\Bluesky\Generated\Methods\Com\Atproto\Label;
use Gimucco\Bluesky\Generated\Methods\Com\Atproto\Repo;
use Gimucco\Bluesky\Generated\Methods\Com\Atproto\Server;
use Gimucco\Bluesky\Generated\Types\App\Bsky\Actor\Defs\ProfileViewDetailed;
use Gimucco\Bluesky\Generated\Types\App\Bsky\Feed\Defs\PostView;
use Gimucco\Bluesky\Internal\Cast;
use Gimucco\Bluesky\Internal\EmbedBuilder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class Client
{
	public readonly Actor $actor;
	public readonly Feed $feed;
	public readonly Graph $graph;
	public readonly Notification $notification;
	public readonly Labeler $labeler;
	public readonly Video $video;
	public readonly Bookmark $bookmark;
	public readonly Repo $repo;
	public readonly Identity $identity;
	public readonly Server $server;
	public readonly Label $label;

	/** @var Session */
	public readonly object $session;
	public readonly LoggerInterface $logger;
	private readonly EmbedBuilder $embed;

	/**
	 * @param Session $session
	 */
	public function __construct(
		object $session,
		?LoggerInterface $logger = null,
	) {
		$this->session = $session;
		$this->logger = $logger ?? new NullLogger();
		$this->embed = new EmbedBuilder();
		$this->actor = new Actor($session);
		$this->feed = new Feed($session);
		$this->graph = new Graph($session);
		$this->notification = new Notification($session);
		$this->labeler = new Labeler($session);
		$this->video = new Video($session);
		$this->bookmark = new Bookmark($session);
		$this->repo = new Repo($session);
		$this->identity = new Identity($session);
		$this->server = new Server($session);
		$this->label = new Label($session);
	}

	// =========================================================================
	// Posts
	// =========================================================================

	/**
	 * @param list<BlobRef|EmbeddedImage>|null $images Pass EmbeddedImage to set alt text (recommended for accessibility); BlobRef defaults alt to "". Max 4 per post.
	 * @param EmbeddedVideo|null $video Single video; pair with EmbeddedRecordWithMedia to combine with a quote.
	 * @param EmbeddedExternal|null $external Link card with optional thumbnail blob.
	 * @param EmbeddedRecord|EmbeddedRecordWithMedia|null $quoting Quote-post embed (optionally with attached media).
	 * @param list<string>|null $tags
	 * @param list<string>|null $langs
	 *
	 * @throws InvalidArgumentException If more than one embed type is provided
	 *         (a post can carry at most one of: images, video, external, quoting).
	 */
	public function post(
		string $text,
		?array $images = null,
		?EmbeddedVideo $video = null,
		?EmbeddedExternal $external = null,
		EmbeddedRecord|EmbeddedRecordWithMedia|null $quoting = null,
		?array $tags = null,
		?array $langs = null,
		?DateTimeImmutable $createdAt = null,
	): PostRef {
		return $this->createPost(
			text: $text,
			replyContext: null,
			images: $images,
			video: $video,
			external: $external,
			quoting: $quoting,
			tags: $tags,
			langs: $langs,
			createdAt: $createdAt,
			logEvent: 'Created post',
			logExtra: [],
		);
	}

	/**
	 * @param list<BlobRef|EmbeddedImage>|null $images Pass EmbeddedImage to set alt text (recommended for accessibility); BlobRef defaults alt to "". Max 4 per post.
	 * @param EmbeddedVideo|null $video Single video; pair with EmbeddedRecordWithMedia to combine with a quote.
	 * @param EmbeddedExternal|null $external Link card with optional thumbnail blob.
	 * @param EmbeddedRecord|EmbeddedRecordWithMedia|null $quoting Quote-post embed (optionally with attached media).
	 * @param list<string>|null $tags
	 * @param list<string>|null $langs
	 *
	 * @throws InvalidArgumentException If more than one embed type is provided.
	 */
	public function reply(
		string|AtUri $parentUri,
		string $parentCid,
		string $text,
		string|AtUri|null $rootUri = null,
		?string $rootCid = null,
		?array $images = null,
		?EmbeddedVideo $video = null,
		?EmbeddedExternal $external = null,
		EmbeddedRecord|EmbeddedRecordWithMedia|null $quoting = null,
		?array $tags = null,
		?array $langs = null,
		?DateTimeImmutable $createdAt = null,
	): PostRef {
		$parentUriStr = (string) $parentUri;
		$rootUriStr = $rootUri !== null ? (string) $rootUri : $parentUriStr;

		return $this->createPost(
			text: $text,
			replyContext: [
				'root' => ['uri' => $rootUriStr, 'cid' => $rootCid ?? $parentCid],
				'parent' => ['uri' => $parentUriStr, 'cid' => $parentCid],
			],
			images: $images,
			video: $video,
			external: $external,
			quoting: $quoting,
			tags: $tags,
			langs: $langs,
			createdAt: $createdAt,
			logEvent: 'Created reply',
			logExtra: ['parent' => $parentUriStr],
		);
	}

	/**
	 * Shared body for post() and reply(). The only difference between them is
	 * whether $replyContext is set (replies set the `reply` field with root and
	 * parent strong-refs); everything else — embed handling, optional fields,
	 * facet detection, createRecord, logging, ref construction — is identical.
	 *
	 * @param array{root: array{uri: string, cid: string}, parent: array{uri: string, cid: string}}|null $replyContext
	 * @param list<BlobRef|EmbeddedImage>|null $images
	 * @param list<string>|null $tags
	 * @param list<string>|null $langs
	 * @param array<string, mixed> $logExtra
	 */
	private function createPost(
		string $text,
		?array $replyContext,
		?array $images,
		?EmbeddedVideo $video,
		?EmbeddedExternal $external,
		EmbeddedRecord|EmbeddedRecordWithMedia|null $quoting,
		?array $tags,
		?array $langs,
		?DateTimeImmutable $createdAt,
		string $logEvent,
		array $logExtra,
	): PostRef {
		$record = [
			'$type' => 'app.bsky.feed.post',
			'text' => $text,
			'createdAt' => ($createdAt ?? new DateTimeImmutable())->format('c'),
		];

		if ($replyContext !== null) {
			$record['reply'] = $replyContext;
		}

		$built = $this->embed->build($images, $video, $external, $quoting);
		if ($built !== null) {
			$record['embed'] = $built;
		}

		// Optional fields — skip nulls.
		if ($tags !== null) {
			$record['tags'] = $tags;
		}
		if ($langs !== null) {
			$record['langs'] = $langs;
		}

		$facets = (new RichText($text))->facets(
			fn(string $handle): string => $this->identity->resolveHandle($handle)->did,
		);
		if ($facets !== []) {
			$record['facets'] = $facets;
		}

		$response = $this->repo->createRecord(
			repo: $this->session->did,
			collection: 'app.bsky.feed.post',
			record: $record,
		);

		$ref = new PostRef($response->uri, $response->cid);
		$this->logger->debug($logEvent, [...$logExtra, 'uri' => $ref->uri]);
		return $ref;
	}

	/**
	 * Delete a post owned by the authenticated user.
	 *
	 * Accepts a PostRef (returned by post()/reply()), an AtUri value object,
	 * an at:// URI string, or — for advanced use — a bare record key string.
	 * Bare rkeys are passed through unverified; the server rejects malformed
	 * ones. Most callers should pass the PostRef returned from creation.
	 */
	public function deletePost(string|AtUri|RecordRef $uriOrRkey): void
	{
		$this->deleteRecordByRkey('app.bsky.feed.post', $uriOrRkey, 'Deleted post');
	}

	/**
	 * Post a thread of N text posts where each item replies to the previous,
	 * all linked to the first post as the root. Returns the PostRef of every
	 * post in order. Useful for content that exceeds Bluesky's 300-grapheme
	 * per-post limit.
	 *
	 *     $refs = $client->thread('First post', 'Second post', 'Third post');
	 *
	 * Each item's text is parsed for facets (links, mentions, hashtags) just
	 * like a regular post(). For threads with media on individual items,
	 * compose post() and reply() manually.
	 *
	 * **Partial-failure semantics**: if a mid-thread post fails (e.g. rate
	 * limit, network error, or content rejection on item 7 of 10), the prior
	 * items remain published — there is no rollback because the AT Protocol
	 * has no transaction primitive. The caller catches the exception and is
	 * responsible for cleanup or recovery; the already-created PostRefs are
	 * not exposed by the throw, so save them incrementally if you need them.
	 *
	 * Set $delaySeconds to throttle posts and reduce rate-limit pressure on
	 * long threads (default 0 = back-to-back, fine for ≤5 items).
	 *
	 * @return list<PostRef>
	 * @throws InvalidArgumentException If $texts is empty.
	 */
	public function thread(string $first, string ...$rest): array
	{
		$texts = [$first, ...$rest];

		// Validate every text up-front, before any HTTP call. A mid-thread
		// failure leaves the prior posts published with no rollback path,
		// so it's worth catching empty inputs early rather than partway in.
		foreach ($texts as $i => $text) {
			if (trim($text) === '') {
				throw new InvalidArgumentException("thread() item {$i} is empty");
			}
		}

		$delaySeconds = $this->threadDelaySeconds;
		$lastIndex = count($texts) - 1;
		$refs = [];
		$root = null;
		$parent = null;
		foreach ($texts as $i => $text) {
			if ($parent === null) {
				$ref = $this->post($text);
				$root = $ref;
			} else {
				/** @var PostRef $root */
				$ref = $this->reply(
					parentUri: $parent->uri,
					parentCid: $parent->cid,
					text: $text,
					rootUri: $root->uri,
					rootCid: $root->cid,
				);
			}
			$refs[] = $ref;
			$parent = $ref;
			if ($delaySeconds > 0 && $i < $lastIndex) {
				sleep($delaySeconds);
			}
		}
		return $refs;
	}

	/**
	 * Set the **persistent** default inter-post delay (seconds) used by all
	 * subsequent thread() calls on this Client instance. Useful for clients
	 * that batch long threads and want to avoid Bluesky's burst rate limits.
	 *
	 * The name is `setDefault*` (not `with*` or `setThreadDelay`) on purpose —
	 * the value sticks across calls until reset:
	 *
	 *     $client->setDefaultThreadDelay(2);
	 *     $client->thread('A', 'B', 'C');   // 2s delay
	 *     $client->thread('X', 'Y');        // STILL 2s delay
	 *     $client->setDefaultThreadDelay(0); // reset
	 *
	 * Returns $this for fluent setup but mutates the existing Client. Reset
	 * before passing the Client to code that expects no delay.
	 */
	public function setDefaultThreadDelay(int $delaySeconds): self
	{
		$this->threadDelaySeconds = max(0, $delaySeconds);
		return $this;
	}
	private int $threadDelaySeconds = 0;

	// =========================================================================
	// Engagement
	// =========================================================================

	/**
	 * Like a post. Accepts either a (uri, cid) pair, a PostRef returned by
	 * post()/reply()/getPost-like calls, or a PostView from feed reads.
	 *
	 *     $client->like($postUri, $postCid);
	 *     $client->like($ref);                    // PostRef from $client->post()
	 *     $client->like($client->getPost($uri));  // PostView
	 *
	 * @throws InvalidArgumentException If a string/AtUri is passed without a $postCid.
	 */
	public function like(PostRef|PostView|string|AtUri $postOrUri, ?string $postCid = null): LikeRef
	{
		[$uriStr, $cidStr] = $this->resolveSubjectRef($postOrUri, $postCid, 'like');
		[$uri, $cid] = $this->createSubjectRecord(
			collection: 'app.bsky.feed.like',
			subject: ['uri' => $uriStr, 'cid' => $cidStr],
			logEvent: 'Liked post',
			logContext: ['post' => $uriStr],
		);
		return new LikeRef($uri, $cid);
	}

	public function unlike(string|AtUri|RecordRef $likeUri): void
	{
		$this->deleteRecordByRkey('app.bsky.feed.like', $likeUri, 'Unliked');
	}

	/**
	 * Repost. Same overload pattern as like() — accepts a (uri, cid) pair,
	 * a PostRef, or a PostView.
	 *
	 * @throws InvalidArgumentException If a string/AtUri is passed without a $postCid.
	 */
	public function repost(PostRef|PostView|string|AtUri $postOrUri, ?string $postCid = null): RepostRef
	{
		[$uriStr, $cidStr] = $this->resolveSubjectRef($postOrUri, $postCid, 'repost');
		[$uri, $cid] = $this->createSubjectRecord(
			collection: 'app.bsky.feed.repost',
			subject: ['uri' => $uriStr, 'cid' => $cidStr],
			logEvent: 'Reposted',
			logContext: ['post' => $uriStr],
		);
		return new RepostRef($uri, $cid);
	}

	/**
	 * Normalize the four possible inputs to like()/repost() into a [uri, cid]
	 * pair. PostRef and PostView already carry both; raw string|AtUri requires
	 * the caller to supply $cid separately, otherwise we throw.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function resolveSubjectRef(PostRef|PostView|string|AtUri $postOrUri, ?string $postCid, string $methodName): array
	{
		if ($postOrUri instanceof PostRef) {
			if ($postCid !== null) {
				throw new InvalidArgumentException(
					"{$methodName}(): when passing a PostRef, do not also supply \$postCid",
				);
			}
			return [$postOrUri->uri, $postOrUri->cid];
		}
		if ($postOrUri instanceof PostView) {
			if ($postCid !== null) {
				throw new InvalidArgumentException(
					"{$methodName}(): when passing a PostView, do not also supply \$postCid",
				);
			}
			return [$postOrUri->uri, $postOrUri->cid];
		}
		if ($postCid === null) {
			throw new InvalidArgumentException(
				"{$methodName}(): \$postCid is required when passing a string/AtUri",
			);
		}
		return [(string) $postOrUri, $postCid];
	}

	public function unrepost(string|AtUri|RecordRef $repostUri): void
	{
		$this->deleteRecordByRkey('app.bsky.feed.repost', $repostUri, 'Unreposted');
	}

	// =========================================================================
	// Social graph
	// =========================================================================

	/**
	 * Follow another account. Accepts a DID, a Handle, or a plain string —
	 * if the string isn't a DID (no `did:` prefix), it's resolved to one via
	 * a single identity.resolveHandle() call (one extra API request).
	 *
	 * @throws \Gimucco\Bluesky\Exception\ApiException If the actor cannot be resolved.
	 */
	public function follow(string|Did|Handle $actor): FollowRef
	{
		$did = $this->resolveActorToDid($actor);
		[$uri, $cid] = $this->createSubjectRecord(
			collection: 'app.bsky.graph.follow',
			subject: $did,
			logEvent: 'Followed',
			logContext: ['subject' => $did],
		);
		return new FollowRef($uri, $cid);
	}

	public function unfollow(string|AtUri|RecordRef $followUri): void
	{
		$this->deleteRecordByRkey('app.bsky.graph.follow', $followUri, 'Unfollowed');
	}

	/**
	 * Block another account. Accepts a DID, a Handle, or a plain string —
	 * handles are auto-resolved to DIDs (one extra API request per call).
	 *
	 * @throws \Gimucco\Bluesky\Exception\ApiException If the actor cannot be resolved.
	 */
	public function block(string|Did|Handle $actor): BlockRef
	{
		$did = $this->resolveActorToDid($actor);
		[$uri, $cid] = $this->createSubjectRecord(
			collection: 'app.bsky.graph.block',
			subject: $did,
			logEvent: 'Blocked',
			logContext: ['subject' => $did],
		);
		return new BlockRef($uri, $cid);
	}

	public function unblock(string|AtUri|RecordRef $blockUri): void
	{
		$this->deleteRecordByRkey('app.bsky.graph.block', $blockUri, 'Unblocked');
	}

	/**
	 * Mute an actor (handle or DID). Mutes are local — the muted user is
	 * not notified and your interactions remain unchanged on their side.
	 * Unlike block/follow, the API accepts handles directly (no resolution
	 * needed).
	 */
	public function mute(string|Did|Handle $actor): void
	{
		$this->graph->muteActor((string) $actor);
		$this->logger->debug('Muted', ['actor' => (string) $actor]);
	}

	public function unmute(string|Did|Handle $actor): void
	{
		$this->graph->unmuteActor((string) $actor);
		$this->logger->debug('Unmuted', ['actor' => (string) $actor]);
	}

	// =========================================================================
	// Reading
	// =========================================================================

	/**
	 * Fetch the authenticated user's own profile.
	 *
	 * @throws \Gimucco\Bluesky\Exception\ApiException
	 */
	public function myProfile(): ProfileViewDetailed
	{
		return $this->actor->getProfile($this->session->did);
	}

	/**
	 * Fetch a single post by AT-URI. Convenience over feed->getPosts() which
	 * returns a list — throws NotFoundException if the URI doesn't resolve.
	 *
	 * @throws NotFoundException If no post is returned for the URI.
	 * @throws \Gimucco\Bluesky\Exception\ApiException On other HTTP failures.
	 */
	public function getPost(string|AtUri $uri): PostView
	{
		$uriStr = (string) $uri;
		$resp = $this->feed->getPosts([$uriStr]);
		if ($resp->posts === []) {
			throw new NotFoundException(404, 'NotFound', 'No post found for URI: '.$uriStr);
		}
		return $resp->posts[0];
	}

	// =========================================================================
	// Media
	// =========================================================================

	/**
	 * Upload raw bytes to the user's PDS as a blob, returning a BlobRef
	 * suitable for embedding in posts via post(images: [...]).
	 *
	 * If $mimeType is omitted, it's detected from the bytes via fileinfo;
	 * detection failure falls back to "application/octet-stream", which the
	 * server may reject — pass an explicit type when you know it.
	 *
	 * @throws InvalidArgumentException On empty bytes or empty MIME string.
	 * @throws \Gimucco\Bluesky\Exception\ApiException On HTTP failure (4xx/5xx).
	 * @throws \Gimucco\Bluesky\Exception\LexiconException If the response shape is unexpected.
	 */
	public function uploadImage(string $bytes, ?string $mimeType = null): BlobRef
	{
		if ($bytes === '') {
			throw new InvalidArgumentException('uploadImage: $bytes must not be empty');
		}
		if ($mimeType === '') {
			throw new InvalidArgumentException('uploadImage: $mimeType must not be an empty string (pass null to auto-detect)');
		}
		if ($mimeType === null) {
			$finfo = new finfo(FILEINFO_MIME_TYPE);
			$detected = $finfo->buffer($bytes);
			$mimeType = $detected !== false ? $detected : 'application/octet-stream';
		}

		$output = $this->repo->uploadBlob($bytes, $mimeType);

		// UploadBlobOutput->blob is typed as mixed because the lexicon "blob"
		// primitive doesn't have a structured PHP type. At the wire level it's
		// an array{$type, ref:{$link}, mimeType, size}; coerce safely.
		$blobData = Cast::toArray($output->blob, 'blob');

		$blob = BlobRef::fromArray($blobData);
		$this->logger->debug('Uploaded blob', ['mimeType' => $mimeType, 'size' => strlen($bytes), 'cid' => $blob->link]);
		return $blob;
	}

	/**
	 * Poll Video::getJobStatus until the upload job completes, then return
	 * the resulting BlobRef ready to embed in a post via EmbeddedVideo.
	 *
	 * Polls with exponential backoff starting at $initialPollSeconds (capped
	 * at 10s between attempts). Returns the blob on JOB_STATE_COMPLETED;
	 * throws BlueskyException on JOB_STATE_FAILED, on timeout, or if the
	 * server reports completion without a blob.
	 *
	 * **Blocks the calling thread** — fine for CLI / cron / queue worker use,
	 * not appropriate inside a synchronous web request. The default timeout
	 * is 120s; pass a shorter $timeoutSeconds when calling from a request
	 * with a max execution time.
	 *
	 * @throws \Gimucco\Bluesky\Exception\BlueskyException
	 * @throws \Gimucco\Bluesky\Exception\ApiException If polling itself fails.
	 */
	public function awaitVideo(string $jobId, int $timeoutSeconds = 120, int $initialPollSeconds = 1): BlobRef
	{
		$deadline = time() + $timeoutSeconds;
		$sleep = max(1, $initialPollSeconds);
		while (true) {
			// Check timeout BEFORE the API call so timeoutSeconds=0 doesn't
			// burn one wasted request before throwing.
			if (time() >= $deadline) {
				throw new Exception\BlueskyException(
					'Video job '.$jobId.' did not complete within '.$timeoutSeconds.'s',
				);
			}
			$status = $this->video->getJobStatus($jobId)->jobStatus;
			if ($status->state === self::JOB_STATE_COMPLETED) {
				if ($status->blob === null) {
					throw new Exception\BlueskyException('Video job '.$jobId.' completed but blob is missing');
				}
				return BlobRef::fromArray(Cast::toArray($status->blob, 'blob'));
			}
			if ($status->state === self::JOB_STATE_FAILED) {
				throw new Exception\BlueskyException(
					'Video job '.$jobId.' failed: '.($status->error ?? $status->message ?? 'unknown error'),
				);
			}
			$this->logger->debug('Awaiting video job', ['jobId' => $jobId, 'state' => $status->state, 'progress' => $status->progress]);
			sleep($sleep);
			$sleep = min($sleep * 2, 10);
		}
	}

	/** Lexicon-defined job states; see app.bsky.video.defs#jobStatus.knownValues. */
	private const JOB_STATE_COMPLETED = 'JOB_STATE_COMPLETED';
	private const JOB_STATE_FAILED = 'JOB_STATE_FAILED';

	// =========================================================================
	// Internal helpers
	// =========================================================================

	/**
	 * Resolve an actor identifier to a DID. If the input already has a `did:`
	 * prefix it's returned unchanged; otherwise it's treated as a handle and
	 * resolved through identity.resolveHandle (one API call).
	 *
	 * Resolved handles are memoized per Client instance with a soft cap of
	 * RESOLVE_CACHE_LIMIT entries — when full, the oldest entry is dropped
	 * (FIFO). This bounds memory in long-running workers that follow/block
	 * many distinct handles, while still saving repeat lookups for the same
	 * handle within a single run.
	 */
	private function resolveActorToDid(string|Did|Handle $actor): string
	{
		$str = (string) $actor;
		if (str_starts_with($str, 'did:')) {
			return $str;
		}
		if (isset($this->handleToDidCache[$str])) {
			return $this->handleToDidCache[$str];
		}
		$did = $this->identity->resolveHandle($str)->did;
		if (count($this->handleToDidCache) >= self::RESOLVE_CACHE_LIMIT) {
			array_shift($this->handleToDidCache);  // FIFO eviction
		}
		$this->handleToDidCache[$str] = $did;
		return $did;
	}

	private const RESOLVE_CACHE_LIMIT = 1024;
	/** @var array<string, string> handle => did */
	private array $handleToDidCache = [];

	/**
	 * Generic create-record helper. The subject field shape varies by record
	 * type — strong-ref pair `{uri, cid}` for likes/reposts, bare DID string
	 * for follows/blocks — so it's passed through opaquely.
	 *
	 * @param string|array<string, string> $subject
	 * @param array<string, mixed> $logContext
	 * @return array{0: string, 1: string} [uri, cid] of the created record
	 */
	private function createSubjectRecord(string $collection, string|array $subject, string $logEvent, array $logContext): array
	{
		$response = $this->repo->createRecord(
			repo: $this->session->did,
			collection: $collection,
			record: [
				'$type' => $collection,
				'subject' => $subject,
				'createdAt' => (new DateTimeImmutable())->format('c'),
			],
		);
		$this->logger->debug($logEvent, [...$logContext, 'uri' => $response->uri]);
		return [$response->uri, $response->cid];
	}

	/**
	 * Generic delete-by-rkey helper used by all "un-X" methods.
	 */
	private function deleteRecordByRkey(string $collection, string|AtUri|RecordRef $uriOrRkey, string $logEvent): void
	{
		$rkey = $this->extractRkey($uriOrRkey);
		$this->repo->deleteRecord(
			repo: $this->session->did,
			collection: $collection,
			rkey: $rkey,
		);
		$this->logger->debug($logEvent, ['rkey' => $rkey]);
	}

	/**
	 * Extract an rkey from either an AtUri value object, an at:// string, a
	 * RecordRef (PostRef / FollowRef / LikeRef / RepostRef / BlockRef — all of
	 * which stringify to their AT-URI), or a bare rkey string.
	 *
	 * Bare rkeys aren't validated here — the API will reject malformed ones.
	 */
	private function extractRkey(string|AtUri|RecordRef $uriOrRkey): string
	{
		if ($uriOrRkey instanceof AtUri) {
			return $uriOrRkey->rkey;
		}
		$str = (string) $uriOrRkey;
		if (str_starts_with($str, 'at://')) {
			return (new AtUri($str))->rkey;
		}
		return $str;
	}
}
